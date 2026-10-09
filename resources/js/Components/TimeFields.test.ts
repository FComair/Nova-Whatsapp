import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import TimeFields from './TimeFields.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: reactive({ locale: 'fr', translations: {} }) }),
}));

function fields(value = '', error?: string) {
    return mount(TimeFields, { props: { modelValue: value, label: 'Heure', required: true, error } });
}

const optionValues = (select: ReturnType<ReturnType<typeof fields>['findAll']>[number]) => select.findAll('option').map((option) => option.element.value);

describe('heure en deux listes', () => {
    it('propose exactement 24 heures et 60 minutes, sans champ « heure » du navigateur', () => {
        const wrapper = fields();
        const [hours, minutes] = wrapper.findAll('select');

        expect(wrapper.findAll('input[type="time"]')).toHaveLength(0);
        expect(optionValues(hours!).filter((value) => value !== '')).toHaveLength(24);
        expect(optionValues(hours!).at(0)).toBe('');
        expect(optionValues(hours!).at(1)).toBe('00');
        expect(optionValues(hours!).at(-1)).toBe('23');
        expect(optionValues(minutes!).filter((value) => value !== '')).toHaveLength(60);
        expect(optionValues(minutes!).at(-1)).toBe('59');
    });

    it("affiche l'heure reçue", () => {
        const wrapper = fields('14:35');
        const [hours, minutes] = wrapper.findAll('select');

        expect((hours!.element as HTMLSelectElement).value).toBe('14');
        expect((minutes!.element as HTMLSelectElement).value).toBe('35');
    });

    it("est vide tant que rien n'est choisi", () => {
        const wrapper = fields();

        expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('');
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it("choisir l'heure suffit : les minutes passent à 00", async () => {
        const wrapper = fields();

        await wrapper.findAll('select')[0]!.setValue('09');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['09:00']);
        expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('00');
    });

    it("combine l'heure et les minutes choisies", async () => {
        const wrapper = fields('09:00');

        await wrapper.findAll('select')[1]!.setValue('45');
        await wrapper.findAll('select')[0]!.setValue('17');

        expect(wrapper.emitted('update:modelValue')?.map((event) => event[0])).toEqual(['09:45', '17:45']);
    });

    it('se vide quand le parent remet la valeur à zéro (formulaire réinitialisé)', async () => {
        const wrapper = fields('09:45');

        await wrapper.setProps({ modelValue: '' });

        expect((wrapper.findAll('select')[0]!.element as HTMLSelectElement).value).toBe('');
        expect((wrapper.findAll('select')[1]!.element as HTMLSelectElement).value).toBe('');
    });

    it("montre l'erreur et marque les deux listes", () => {
        const wrapper = fields('', 'Heure invalide');

        expect(wrapper.find('[role="alert"]').text()).toBe('Heure invalide');
        expect(wrapper.findAll('select').every((select) => select.attributes('aria-invalid') === 'true')).toBe(true);
    });

    describe('heures passées', () => {
        const withMin = (value: string, minMinutes: number | null) => mount(TimeFields, { props: { modelValue: value, label: 'Heure', minMinutes } });
        const enabledValues = (select: ReturnType<ReturnType<typeof fields>['findAll']>[number]) =>
            select
                .findAll('option')
                .filter((option) => option.element.value !== '' && !option.element.disabled)
                .map((option) => option.element.value);

        it('ne grise rien sans limite', () => {
            const [hours, minutes] = withMin('', null).findAll('select');

            expect(enabledValues(hours!)).toHaveLength(24);
            expect(enabledValues(minutes!)).toHaveLength(60);
        });

        it("grise les heures entièrement passées et garde l'heure en cours", () => {
            const [hours] = withMin('', 14 * 60 + 20).findAll('select');

            expect(enabledValues(hours!)[0]).toBe('14');
            expect(enabledValues(hours!)).toHaveLength(10);
        });

        it('grise les minutes passées de l’heure en cours, pas des suivantes', async () => {
            const wrapper = withMin('', 14 * 60 + 20);
            const [hours, minutes] = wrapper.findAll('select');
            await hours!.setValue('14');

            expect(enabledValues(minutes!)[0]).toBe('20');
            expect((minutes!.element as HTMLSelectElement).value).toBe('20');

            await hours!.setValue('15');
            expect(enabledValues(minutes!)).toHaveLength(60);
        });

        it('grise tout un jour passé', () => {
            const [hours] = withMin('', 24 * 60).findAll('select');

            expect(enabledValues(hours!)).toHaveLength(0);
        });
    });
});
