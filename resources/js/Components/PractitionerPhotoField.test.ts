import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { PhotoError } from '../composables/practitionerPhoto';
import PractitionerPhotoField from './PractitionerPhotoField.vue';

const post = vi.fn();
const prepare = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
    router: { delete: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {} as Record<string, string>, processing: false, progress: null, post, reset: vi.fn(), clearErrors: vi.fn() }),
}));

vi.mock('../composables/practitionerPhoto', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../composables/practitionerPhoto')>()),
    prepareImage: (file: File) => prepare(file),
}));

const field = (props: Record<string, unknown> = {}) =>
    mount(PractitionerPhotoField, {
        props: { practitioner: { id: 'p1', name: 'Anna Dupont', photo_version: null }, ...props } as never,
    });

async function choose(wrapper: ReturnType<typeof field>) {
    const input = wrapper.find('[data-testid="photo-input"]');
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.png', { type: 'image/png' })], configurable: true });
    await input.trigger('change');
    await flushPromises();
}

describe('PractitionerPhotoField', () => {
    beforeEach(() => {
        post.mockReset();
        prepare.mockReset();
        URL.createObjectURL = vi.fn(() => 'blob:preview');
        URL.revokeObjectURL = vi.fn();
    });

    it('propose « Ajouter » sans photo, « Changer » et « Supprimer » avec une photo', () => {
        expect(field({ uploadUrl: '/u', removeUrl: '/r' }).text()).toContain('practitioner_photo.add');

        const withPhoto = field({ uploadUrl: '/u', removeUrl: '/r', practitioner: { id: 'p1', name: 'Anna', photo_version: 5 } });
        expect(withPhoto.text()).toContain('practitioner_photo.change');
        expect(withPhoto.text()).toContain('practitioner_photo.remove');
    });

    it('affiche l’erreur du contrôle côté navigateur sans rien envoyer', async () => {
        prepare.mockRejectedValue(new PhotoError('too_large'));
        const wrapper = field({ uploadUrl: '/u' });
        await choose(wrapper);

        expect(wrapper.find('[role="alert"]').text()).toBe('practitioner_photo.errors.too_large');
        expect(wrapper.text()).not.toContain('practitioner_photo.save');
        expect(post).not.toHaveBeenCalled();
    });

    it('montre un aperçu avec Enregistrer / Annuler, puis envoie la photo', async () => {
        prepare.mockResolvedValue(new File(['y'], 'photo.jpg', { type: 'image/jpeg' }));
        const wrapper = field({ uploadUrl: '/configuration/praticiens/p1/photo' });
        await choose(wrapper);

        expect(wrapper.find('img').attributes('src')).toBe('blob:preview');
        expect(wrapper.text()).toContain('practitioner_photo.save');
        expect(wrapper.text()).toContain('practitioner_photo.cancel');

        const save = wrapper.findAll('button').find((button) => button.text() === 'practitioner_photo.save')!;
        await save.trigger('click');
        expect(post).toHaveBeenCalledWith('/configuration/praticiens/p1/photo', expect.objectContaining({ forceFormData: true }));
    });

    it('annule l’aperçu sans rien envoyer', async () => {
        prepare.mockResolvedValue(new File(['y'], 'photo.jpg', { type: 'image/jpeg' }));
        const wrapper = field({ uploadUrl: '/u' });
        await choose(wrapper);

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'practitioner_photo.cancel')!
            .trigger('click');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('à la création, garde la photo préparée dans le v-model sans bouton Enregistrer', async () => {
        const prepared = new File(['y'], 'photo.jpg', { type: 'image/jpeg' });
        prepare.mockResolvedValue(prepared);
        const wrapper = field({ practitioner: { name: 'Nouveau', photo_version: null } });
        await choose(wrapper);

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([prepared]);
        expect(wrapper.text()).not.toContain('practitioner_photo.save');
        expect(wrapper.text()).toContain('practitioner_photo.pending');
    });
});
