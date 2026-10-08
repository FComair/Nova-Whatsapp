import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import PractitionerField from './PractitionerField.vue';

/**
 * Praticien d'un rendez-vous dans la fenêtre de détail : affichage simple, « Modifier » seulement pour qui en a le droit,
 * liste des praticiens qui réalisent le service, message clair quand il n'y en a aucun.
 */
const put = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {} as Record<string, string>, processing: false, put, reset: vi.fn(), clearErrors: vi.fn() }),
}));

const booking = (name: string | null) => ({ id: 'b1', practitioner_id: name ? 'p1' : '', practitioner_name: name, service_id: 's1' });
const practitioner = (id: string, name: string) => ({ id, name, color: '#000000', schedule: {} });
const anna = practitioner('p1', 'Anna Dupont');
const ben = practitioner('p2', 'Ben Martin');

function field(options: { name?: string | null; eligible?: unknown[]; canChange?: boolean } = {}) {
    return mount(PractitionerField, {
        props: {
            booking: booking(options.name === undefined ? 'Anna Dupont' : options.name),
            eligible: options.eligible ?? [anna, ben],
            canChange: options.canChange ?? true,
        } as never,
    });
}

describe('PractitionerField', () => {
    it('montre le nom et les initiales du praticien actuel', () => {
        const wrapper = field();

        expect(wrapper.text()).toContain('Anna Dupont');
        expect(wrapper.text()).toContain('AD');
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it("n'offre aucune modification sans le droit de le faire", () => {
        const wrapper = field({ canChange: false });

        expect(wrapper.text()).toContain('Anna Dupont');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it("annonce le praticien et l'action pour les lecteurs d'écran", () => {
        expect(field().find('button').attributes('aria-label')).toBe('planning.practitioner.current');
    });

    it("affiche « aucun praticien assigné » et garde l'action Modifier", () => {
        const wrapper = field({ name: null });

        expect(wrapper.text()).toContain('planning.practitioner.none');
        expect(wrapper.find('button').attributes('aria-label')).toBe('planning.practitioner.change_label');
    });

    it('ouvre la liste sur place avec les praticiens proposés, Enregistrer désactivé tant que rien ne change', async () => {
        const wrapper = field();
        await wrapper.find('button').trigger('click');

        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['planning.practitioner.select', 'Anna Dupont', 'Ben Martin']);
        const [save, cancel] = wrapper.findAll('button');
        expect(save!.attributes('disabled')).toBeDefined();

        await wrapper.find('select').setValue('p2');
        expect(wrapper.findAll('button')[0]!.attributes('disabled')).toBeUndefined();

        await cancel!.trigger('click');
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.text()).toContain('Anna Dupont');
    });

    it("envoie le nouveau praticien à l'enregistrement", async () => {
        const wrapper = field();
        await wrapper.find('button').trigger('click');
        await wrapper.find('select').setValue('p2');
        await wrapper.find('form').trigger('submit');

        expect(put).toHaveBeenCalledWith('/planning/rendez-vous/b1/praticien', expect.objectContaining({ preserveScroll: true }));
    });

    it("explique quand aucun praticien n'est disponible", async () => {
        const wrapper = field({ eligible: [] });
        await wrapper.find('button').trigger('click');

        expect(wrapper.text()).toContain('planning.practitioner.none_available');
        expect(wrapper.find('select').exists()).toBe(false);
    });

    it('propose une recherche seulement quand la liste est longue', async () => {
        const many = Array.from({ length: 9 }, (_, index) => practitioner(`x${index}`, `Personne ${index}`));
        const wrapper = field({ eligible: many });
        await wrapper.find('button').trigger('click');

        expect(wrapper.find('input[type="text"]').exists()).toBe(true);

        await wrapper.find('input[type="text"]').setValue('personne 3');
        expect(wrapper.findAll('option').map((option) => option.text())).toEqual(['planning.practitioner.select', 'Personne 3']);
    });
});
