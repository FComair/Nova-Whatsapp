import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import LogoCropper from './LogoCropper.vue';
import LogoUploader from './LogoUploader.vue';

const post = vi.fn();
const remove = vi.fn();
const size = { width: 800, height: 600 };
let measured: { width: number; height: number; element: unknown } | null = null;

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { locale: 'en', translations: {}, auth: { organization: { id: 'o1', name: 'Salon Aurore', role: 'owner' } }, flash: {}, errors: {} },
    }),
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            progress: null,
            post,
            delete: remove,
            reset: vi.fn(),
            clearErrors: vi.fn(),
        }),
}));

vi.mock('../composables/logoCrop', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../composables/logoCrop')>()),
    loadImage: async () => measured,
}));

const uploader = (props: Record<string, unknown> = {}) =>
    mount(LogoUploader, { props: { hasLogo: true, ...props } as never, global: { stubs: { ConfirmDialog: true } } });
const file = (type: string, bytes = 1000, name = 'logo.png') => new File([new Uint8Array(bytes)], name, { type });

async function choose(wrapper: ReturnType<typeof uploader>, chosen: File | undefined) {
    const input = wrapper.find('[data-testid="logo-input"]');
    Object.defineProperty(input.element, 'files', { value: chosen ? [chosen] : [], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

describe('LogoUploader : choix et contrôles', () => {
    beforeEach(() => {
        post.mockReset();
        remove.mockReset();
        measured = { ...size, element: {} };
        URL.createObjectURL = vi.fn(() => 'blob:logo');
        URL.revokeObjectURL = vi.fn();
    });

    it('propose Changer et Retirer quand un logo existe, Ajouter sinon', () => {
        const text = uploader().text();
        expect(text).toContain('logo.change');
        expect(text).toContain('logo.remove');
        expect(uploader({ hasLogo: false }).text()).toContain('logo.upload');
        expect(uploader({ hasLogo: false }).text()).not.toContain('logo.remove');
    });

    it('ouvre le recadrage après le choix d’une image valide, sans rien envoyer', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));

        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(true);
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('logo.change');
    });

    it('ouvre le recadrage aussi quand on dépose l’image', async () => {
        const wrapper = uploader();
        await wrapper.find('[data-testid="logo-uploader"]').trigger('drop', { dataTransfer: { files: [file('image/jpeg', 500, 'logo.jpg')] } });
        await flushPromises();

        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(true);
        expect(wrapper.findComponent(LogoCropper).props('opaque')).toBe(true);
    });

    it.each([
        ['un format refusé', () => file('image/gif'), 'logo.errors.type'],
        ['une image trop lourde', () => file('image/png', 2 * 1024 * 1024 + 1), 'logo.errors.too_large'],
    ])('refuse %s', async (_label, make, message) => {
        const wrapper = uploader();
        await choose(wrapper, make());

        expect(wrapper.find('[role="alert"]').text()).toBe(message);
        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(false);
    });

    it('refuse une image trop petite, trop grande ou illisible', async () => {
        const wrapper = uploader();

        measured = { width: 150, height: 900, element: {} };
        await choose(wrapper, file('image/png'));
        expect(wrapper.find('[role="alert"]').text()).toBe('logo.errors.dimensions');

        measured = { width: 6001, height: 900, element: {} };
        await choose(wrapper, file('image/png'));
        expect(wrapper.find('[role="alert"]').text()).toBe('logo.errors.dimensions_max');

        measured = null;
        await choose(wrapper, file('image/png'));
        expect(wrapper.find('[role="alert"]').text()).toBe('logo.errors.invalid');
        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(false);
    });

    it('annuler ferme le recadrage et laisse le logo actuel en place', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));
        await wrapper.find('[data-testid="crop-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('logo.change');
        expect(post).not.toHaveBeenCalled();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:logo');
    });
});

describe('LogoUploader : enregistrement', () => {
    beforeEach(() => {
        post.mockReset();
        measured = { ...size, element: {} };
        URL.createObjectURL = vi.fn(() => 'blob:logo');
        URL.revokeObjectURL = vi.fn();
    });

    it('envoie l’image recadrée à l’adresse habituelle', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));
        const cropped = new File(['x'], 'logo.png', { type: 'image/png' });
        wrapper.findComponent(LogoCropper).vm.$emit('save', cropped);
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/reglages/profil/logo', expect.objectContaining({ forceFormData: true, preserveScroll: true }));
    });

    it('referme le recadrage une fois enregistré', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));
        wrapper.findComponent(LogoCropper).vm.$emit('save', new File(['x'], 'logo.png', { type: 'image/png' }));
        await flushPromises();

        (post.mock.calls[0]![1] as { onSuccess: () => void }).onSuccess();
        await flushPromises();

        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(false);
    });

    it('garde le recadrage ouvert et affiche une erreur claire si l’envoi échoue', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));
        wrapper.findComponent(LogoCropper).vm.$emit('save', new File(['x'], 'logo.png', { type: 'image/png' }));
        await flushPromises();

        const options = post.mock.calls[0]![1] as { onHttpException: () => boolean; onNetworkError: () => boolean };
        expect(options.onHttpException()).toBe(false);
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('logo.errors.failed');
        expect(wrapper.find('[data-testid="logo-cropper"]').exists()).toBe(true);

        expect(options.onNetworkError()).toBe(false);
    });

    it('signale un échec de préparation de l’image', async () => {
        const wrapper = uploader();
        await choose(wrapper, file('image/png'));
        wrapper.findComponent(LogoCropper).vm.$emit('fail');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toBe('logo.errors.export');
        expect(post).not.toHaveBeenCalled();
    });

    it('garde Retirer le logo comme avant', async () => {
        const wrapper = uploader();
        const confirm = wrapper.findComponent({ name: 'ConfirmDialog' });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'logo.remove')!
            .trigger('click');
        confirm.vm.$emit('confirm');

        expect(remove).toHaveBeenCalledWith('/reglages/profil/logo', expect.objectContaining({ preserveScroll: true }));
    });
});
