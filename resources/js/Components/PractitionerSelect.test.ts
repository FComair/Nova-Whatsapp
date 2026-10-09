import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import PractitionerSelect from './PractitionerSelect.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

const options = [
    { value: 'p1', label: 'Anna Dupont', photo_version: 1760000000 },
    { value: 'p2', label: 'Ben Martin', photo_version: null },
];

const select = (props: Record<string, unknown> = {}) =>
    mount(PractitionerSelect, { props: { label: 'Praticien', options, modelValue: '', placeholder: 'Choisir', ...props } as never });

describe('PractitionerSelect', () => {
    it('est un combobox accessible, fermé au départ, qui affiche le placeholder', () => {
        const wrapper = select();
        const box = wrapper.find('[role="combobox"]');

        expect(box.attributes('aria-haspopup')).toBe('listbox');
        expect(box.attributes('aria-expanded')).toBe('false');
        expect(box.text()).toContain('Choisir');
        expect(wrapper.find('[role="combobox"]').attributes('aria-expanded')).toBe('false');
    });

    it('liste les praticiens avec leur photo ou leurs initiales', async () => {
        const wrapper = select();
        await wrapper.find('[role="combobox"]').trigger('click');

        const rows = wrapper.findAll('[role="option"]');
        expect(rows).toHaveLength(2);
        expect(rows[0]!.find('img').exists()).toBe(true);
        expect(rows[1]!.find('[data-testid="practitioner-initials"]').text()).toBe('BM');
    });

    it('émet la valeur choisie et ferme la liste', async () => {
        const wrapper = select();
        await wrapper.find('[role="combobox"]').trigger('click');
        await wrapper.findAll('[role="option"]')[1]!.trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['p2']);
        expect(wrapper.find('[role="combobox"]').attributes('aria-expanded')).toBe('false');
    });

    it('se pilote au clavier', async () => {
        const wrapper = select();
        const box = wrapper.find('[role="combobox"]');

        await box.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.find('[role="combobox"]').attributes('aria-expanded')).toBe('true');

        await box.trigger('keydown', { key: 'ArrowDown' });
        await box.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('update:modelValue')?.length).toBe(1);

        await box.trigger('keydown', { key: 'ArrowDown' });
        await box.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="combobox"]').attributes('aria-expanded')).toBe('false');
    });

    it('affiche le praticien choisi avec sa photo', () => {
        const wrapper = select({ modelValue: 'p1' });
        const box = wrapper.find('[role="combobox"]');

        expect(box.text()).toContain('Anna Dupont');
        expect(box.find('img').attributes('src')).toBe('/praticiens/p1/photo/64?v=1760000000');
    });

    it('n’ouvre rien quand il n’y a aucune option', async () => {
        const wrapper = select({ options: [], emptyLabel: 'Aucun praticien' });
        await wrapper.find('[role="combobox"]').trigger('click');

        expect(wrapper.text()).toContain('Aucun praticien');
        expect(wrapper.findAll('[role="option"]')).toHaveLength(0);
    });
});
