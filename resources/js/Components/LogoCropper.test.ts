import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LogoCropper from './LogoCropper.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

const fill = vi.fn();
const draw = vi.fn();
let nextBlob: Blob | null = null;
const blobs: (Blob | null)[] = [];

beforeEach(() => {
    fill.mockReset();
    draw.mockReset();
    blobs.length = 0;
    nextBlob = new Blob(['png'], { type: 'image/png' });
    HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
        fillRect: fill,
        drawImage: draw,
        set fillStyle(_value: string) {},
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'low',
    })) as never;
    HTMLCanvasElement.prototype.toBlob = vi.fn(function (callback: BlobCallback) {
        callback(blobs.length > 0 ? (blobs.shift() ?? null) : nextBlob);
    }) as never;
});

afterEach(() => vi.restoreAllMocks());

const cropper = (props: Record<string, unknown> = {}) =>
    mount(LogoCropper, { props: { src: 'blob:logo', width: 800, height: 400, name: 'Salon Aurore', opaque: false, ...props } as never });

// jsdom n'a pas de PointerEvent : on fabrique l'événement avec les mêmes champs.
function pointer(target: Element, type: string, init: { pointerId: number; clientX?: number; clientY?: number }): void {
    const event = new MouseEvent(type, { bubbles: true, clientX: init.clientX ?? 0, clientY: init.clientY ?? 0 });
    Object.defineProperty(event, 'pointerId', { value: init.pointerId });
    target.dispatchEvent(event);
}

const left = (wrapper: ReturnType<typeof cropper>) => parseFloat((wrapper.find('[data-testid="crop-image"]').element as HTMLElement).style.left);
const percent = (wrapper: ReturnType<typeof cropper>) => wrapper.find('[data-testid="zoom-value"]').text();

describe('LogoCropper : zone de recadrage', () => {
    it('montre l’image dans une zone accessible, avec le guide et les trois aperçus', () => {
        const wrapper = cropper();
        const viewport = wrapper.find('[data-testid="crop-viewport"]');

        expect(viewport.attributes('tabindex')).toBe('0');
        expect(viewport.attributes('aria-label')).toBe('logo.crop.viewport');
        expect(viewport.attributes('aria-describedby')).toBeTruthy();
        expect(wrapper.findAll('[data-testid="crop-previews"] li')).toHaveLength(3);
        expect(wrapper.find('[data-testid="preview-menu"]').attributes('style')).toContain('width: 32px');
        expect(wrapper.find('[data-testid="preview-profile"]').attributes('style')).toContain('width: 88px');
        expect(wrapper.find('[data-testid="preview-qr"]').classes()).toContain('rounded-lg');
        expect(wrapper.text()).toContain('Salon Aurore');
    });

    it('cadre l’image sans la déformer : le petit côté remplit la zone', () => {
        const image = wrapper().find('[data-testid="crop-image"]').element as HTMLElement;

        // Zone de 288 px (jsdom) : hauteur 288, largeur 576, soit les proportions 2 : 1 de l'image 800 × 400.
        expect(parseFloat(image.style.height)).toBe(288);
        expect(parseFloat(image.style.width)).toBe(576);

        function wrapper() {
            return cropper();
        }
    });

    it('zoome avec les boutons + et −, et la valeur suit', async () => {
        const wrapper = cropper();

        expect(percent(wrapper)).toBe('logo.crop.zoom_value');
        await wrapper.find('[data-testid="zoom-in"]').trigger('click');
        await wrapper.find('[data-testid="zoom-in"]').trigger('click');
        expect((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value).toBe('1.2');

        await wrapper.find('[data-testid="zoom-out"]').trigger('click');
        expect((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value).toBe('1.1');
    });

    it('zoome avec le curseur dans ses bornes (image entière à ×4)', async () => {
        const wrapper = cropper();
        const range = wrapper.find('[data-testid="zoom-range"]');

        expect(range.attributes('min')).toBe('0.5');
        expect(range.attributes('max')).toBe('4');

        await range.setValue('2');
        expect((range.element as HTMLInputElement).value).toBe('2');
        await range.setValue('9');
        expect((range.element as HTMLInputElement).value).toBe('4');
    });

    it('désactive − au zoom minimal et + au zoom maximal', async () => {
        const wrapper = cropper();

        await wrapper.find('[data-testid="zoom-range"]').setValue('0.1');
        expect(wrapper.find('[data-testid="zoom-out"]').attributes('disabled')).toBeDefined();

        await wrapper.find('[data-testid="zoom-range"]').setValue('9');
        expect(wrapper.find('[data-testid="zoom-in"]').attributes('disabled')).toBeDefined();
    });

    it('déplace l’image en la faisant glisser', async () => {
        const wrapper = cropper();
        const viewport = wrapper.find('[data-testid="crop-viewport"]');
        const before = left(wrapper);

        pointer(viewport.element, 'pointerdown', { pointerId: 1, clientX: 100, clientY: 100 });
        pointer(viewport.element, 'pointermove', { pointerId: 1, clientX: 70, clientY: 100 });
        pointer(viewport.element, 'pointerup', { pointerId: 1 });
        await wrapper.vm.$nextTick();

        expect(left(wrapper)).toBeCloseTo(before - 30, 4);
    });

    it('zoome en pinçant avec deux doigts', async () => {
        const wrapper = cropper();
        const viewport = wrapper.find('[data-testid="crop-viewport"]');

        pointer(viewport.element, 'pointerdown', { pointerId: 1, clientX: 100, clientY: 100 });
        pointer(viewport.element, 'pointerdown', { pointerId: 2, clientX: 140, clientY: 100 });
        pointer(viewport.element, 'pointermove', { pointerId: 2, clientX: 180, clientY: 100 });
        await wrapper.vm.$nextTick();

        expect(Number((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value)).toBeGreaterThan(1.5);
    });

    it('se pilote au clavier : flèches, + / −, 0', async () => {
        const wrapper = cropper();
        const viewport = wrapper.find('[data-testid="crop-viewport"]');
        const before = left(wrapper);

        await viewport.trigger('keydown', { key: 'ArrowLeft' });
        expect(left(wrapper)).toBeLessThan(before);

        await viewport.trigger('keydown', { key: '+' });
        expect((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value).toBe('1.1');
        await viewport.trigger('keydown', { key: '-' });
        expect((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value).toBe('1');

        await viewport.trigger('keydown', { key: 'ArrowLeft', shiftKey: true });
        await viewport.trigger('keydown', { key: '0' });
        expect(left(wrapper)).toBe(before);
    });

    it('réinitialise le cadrage', async () => {
        const wrapper = cropper();
        const reset = wrapper.find('[data-testid="crop-reset"]');

        expect(reset.attributes('disabled')).toBeDefined();
        await wrapper.find('[data-testid="zoom-in"]').trigger('click');
        expect(reset.attributes('disabled')).toBeUndefined();

        await reset.trigger('click');
        expect((wrapper.find('[data-testid="zoom-range"]').element as HTMLInputElement).value).toBe('1');
        expect(reset.attributes('disabled')).toBeDefined();
    });

    it('met à jour les aperçus avec le cadrage', async () => {
        const wrapper = cropper();
        const style = () => wrapper.find('[data-testid="preview-profile"] img').attributes('style');
        const before = style();

        await wrapper.find('[data-testid="zoom-in"]').trigger('click');
        expect(style()).not.toBe(before);
    });
});

describe('LogoCropper : enregistrement', () => {
    it('produit un PNG carré avec le cadrage choisi, sans déformation', async () => {
        const wrapper = cropper();
        await wrapper.find('[data-testid="zoom-range"]').setValue('1.5');
        await wrapper.find('[data-testid="crop-save"]').trigger('click');
        await flushPromises();

        const file = wrapper.emitted('save')![0]![0] as File;
        expect(file.name).toBe('logo.png');
        expect(file.type).toBe('image/png');

        const [, x, y, width, height] = draw.mock.calls[0]!;
        // 512 px de côté ; zoom 1,5 sur une image 2 : 1 → 1536 × 768 : mêmes proportions, centrée.
        expect(width / height).toBeCloseTo(2, 6);
        expect(height).toBeCloseTo(768, 6);
        expect(x).toBeCloseTo(-512, 6);
        expect(y).toBeCloseTo(-128, 6);
        expect(fill).not.toHaveBeenCalled();
    });

    it('remplit les marges de blanc pour un JPEG', async () => {
        const wrapper = cropper({ opaque: true });
        await wrapper.find('[data-testid="crop-save"]').trigger('click');
        await flushPromises();

        expect(fill).toHaveBeenCalledWith(0, 0, 512, 512);
    });

    it('réduit la taille du fichier s’il dépasse 2 Mo', async () => {
        const huge = new Blob([new Uint8Array(2 * 1024 * 1024 + 1)]);
        blobs.push(huge);
        const wrapper = cropper();
        await wrapper.find('[data-testid="crop-save"]').trigger('click');
        await flushPromises();

        expect(draw).toHaveBeenCalledTimes(2);
        expect(wrapper.emitted('save')).toHaveLength(1);
    });

    it('signale un échec quand l’image ne peut pas être préparée', async () => {
        blobs.push(null);
        const wrapper = cropper();
        await wrapper.find('[data-testid="crop-save"]').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('fail')).toHaveLength(1);
        expect(wrapper.emitted('save')).toBeUndefined();
    });

    it('annule sans rien produire', async () => {
        const wrapper = cropper();
        await wrapper.find('[data-testid="crop-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(draw).not.toHaveBeenCalled();
    });

    it('bloque les actions pendant l’envoi et affiche la progression', () => {
        const wrapper = cropper({ processing: true, progress: 40 });

        expect(wrapper.find('[data-testid="crop-cancel"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="zoom-in"]').attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="crop-save"]').text()).toBe('logo.uploading');
        expect(wrapper.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('40');
    });
});
