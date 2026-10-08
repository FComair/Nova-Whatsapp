import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import WeekEditor from './WeekEditor.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: reactive({ locale: 'fr', translations: {} }) }),
}));

const week = (ranges: { start: string; end: string }[]) =>
    Array.from({ length: 7 }, (_unused, index) => ({ weekday: index + 1, open: index === 0, ranges: index === 0 ? ranges : [] }));

function editor(ranges: { start: string; end: string }[]) {
    return mount(WeekEditor, { props: { modelValue: week(ranges) } });
}

describe('plages horaires d’une journée', () => {
    it('utilise des listes fermées et non des champs « heure » du navigateur', () => {
        const wrapper = editor([{ start: '09:00', end: '18:00' }]);

        expect(wrapper.findAll('input[type="time"]')).toHaveLength(0);
        expect(wrapper.findAll('select')).toHaveLength(2);
    });

    it('limite le début aux heures avant la fin et la fin aux heures après le début', () => {
        const wrapper = editor([{ start: '09:00', end: '18:00' }]);
        const [start, end] = wrapper.findAll('select');
        const values = (select: typeof start) => select!.findAll('option').map((option) => option.element.value);

        expect(values(start)[0]).toBe('00:00');
        expect(values(start).at(-1)).toBe('17:45');
        expect(values(end)[0]).toBe('09:15');
        expect(values(end).at(-1)).toBe('23:45');
        expect(values(start)).not.toContain('18:00');
        expect(values(end)).not.toContain('09:00');
    });

    it('garde l’horaire enregistré affiché et sélectionné', () => {
        const wrapper = editor([{ start: '09:10', end: '17:50' }]);
        const [start, end] = wrapper.findAll('select');

        expect((start!.element as HTMLSelectElement).value).toBe('09:10');
        expect((end!.element as HTMLSelectElement).value).toBe('17:50');
    });

    it('signale une plage dont la fin n’est pas après le début', () => {
        const wrapper = editor([{ start: '18:00', end: '09:00' }]);

        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
        expect(wrapper.find('select').attributes('aria-invalid')).toBe('true');
    });

    it('ne signale rien pour une plage valide', () => {
        const wrapper = editor([{ start: '09:00', end: '18:00' }]);

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.find('select').attributes('aria-invalid')).toBeUndefined();
    });

    it('met à jour la plage quand on choisit une autre heure', async () => {
        const wrapper = editor([{ start: '09:00', end: '18:00' }]);

        await wrapper.findAll('select')[1]!.setValue('12:30');

        const days = wrapper.props('modelValue') as { ranges: { start: string; end: string }[] }[];
        expect(days[0]!.ranges[0]).toEqual({ start: '09:00', end: '12:30' });
    });
});
