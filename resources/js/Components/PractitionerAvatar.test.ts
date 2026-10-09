import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import PractitionerAvatar from './PractitionerAvatar.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

const avatar = (props: Record<string, unknown> = {}) => mount(PractitionerAvatar, { props: { id: 'p1', name: 'Anna Dupont', ...props } as never });

describe('PractitionerAvatar', () => {
    it('montre les initiales quand il n’y a pas de photo', () => {
        const wrapper = avatar();

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[data-testid="practitioner-initials"]').text()).toBe('AD');
    });

    it('montre la photo, avec un texte alternatif et des dimensions fixes', () => {
        const wrapper = avatar({ version: 1760000000, size: 'md' });
        const image = wrapper.find('img');

        expect(image.attributes('src')).toBe('/praticiens/p1/photo/256?v=1760000000');
        expect(image.attributes('alt')).toBe('practitioner_photo.alt');
        expect(image.attributes('width')).toBe('36');
        expect(image.attributes('height')).toBe('36');
        expect(image.attributes('loading')).toBe('lazy');
    });

    it('utilise la petite image pour les petites tailles', () => {
        expect(avatar({ version: 1, size: 'xs' }).find('img').attributes('src')).toContain('/photo/64?');
        expect(avatar({ version: 1, size: 'sm' }).find('img').attributes('src')).toContain('/photo/64?');
        expect(avatar({ version: 1, size: 'xl' }).find('img').attributes('src')).toContain('/photo/256?');
    });

    it('retombe sur les initiales si l’image ne se charge pas, et réessaie avec une nouvelle version', async () => {
        const wrapper = avatar({ version: 1 });
        await wrapper.find('img').trigger('error');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[data-testid="practitioner-initials"]').exists()).toBe(true);

        await wrapper.setProps({ version: 2 });
        expect(wrapper.find('img').attributes('src')).toBe('/praticiens/p1/photo/64?v=2');
    });

    it('préfère l’aperçu local à la photo enregistrée', () => {
        const wrapper = avatar({ version: 1, preview: 'blob:preview' });

        expect(wrapper.find('img').attributes('src')).toBe('blob:preview');
    });
});
