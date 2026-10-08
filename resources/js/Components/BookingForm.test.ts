import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, reactive } from 'vue';
import BookingForm from './BookingForm.vue';

/**
 * Liste des praticiens du formulaire « nouveau rendez-vous » : elle ne doit jamais s'ouvrir « à blanc » sans expliquer pourquoi.
 * (Les praticiens arrivent avec la page : il n'y a pas d'appel réseau, donc pas d'état de chargement ni d'erreur d'API.)
 */
const shared = reactive({
    locale: 'fr',
    translations: {} as Record<string, unknown>,
    auth: { user: { id: 'u1', email: 'a@b.c', locale: 'fr' }, organization: { id: 'o1', name: 'Salon', status: 'active', role: 'owner' as string } },
    flash: { status: null, warning: null, first_login: false, suggestions: [] },
    errors: {} as Record<string, string>,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
    useForm: (data: Record<string, unknown>) => reactive({ ...data, errors: {}, processing: false, post: vi.fn(), reset: vi.fn() }),
}));

const anna = { id: 'p1', name: 'Anna', color: '#111111', schedule: {} };
const ben = { id: 'p2', name: 'Ben', color: '#222222', schedule: {} };
const services = [
    { id: 's1', name: 'Coupe', duration_minutes: 30, practitioner_ids: ['p1', 'p2'] },
    { id: 's2', name: 'Couleur', duration_minutes: 60, practitioner_ids: ['p2'] },
    { id: 's3', name: 'Brushing', duration_minutes: 30, practitioner_ids: [] },
];

function form(practitioners: unknown[]) {
    return mount(BookingForm, { props: { locationId: 'l1', date: '2026-10-08', practitioners, services, seesNotes: false } as never });
}

const practitionerSelect = (wrapper: ReturnType<typeof form>) => wrapper.findAll('select')[3]!;
const optionLabels = (wrapper: ReturnType<typeof form>) =>
    practitionerSelect(wrapper)
        .findAll('option')
        .map((option) => option.text());

describe('liste des praticiens du nouveau rendez-vous', () => {
    beforeEach(() => {
        shared.auth.organization.role = 'owner';
    });

    it("propose tous les praticiens tant qu'aucun service n'est choisi", () => {
        const wrapper = form([anna, ben]);

        expect(optionLabels(wrapper)).toEqual(['planning.form.choose', 'Anna', 'Ben']);
        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it('ne propose que les praticiens qui réalisent le service choisi', async () => {
        const wrapper = form([anna, ben]);

        await wrapper.findAll('select')[0]!.setValue('s2');

        expect(optionLabels(wrapper)).toEqual(['planning.form.choose', 'Ben']);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it("explique quand personne ne propose le service choisi, au lieu d'une liste vide muette", async () => {
        const wrapper = form([anna, ben]);

        await wrapper.findAll('select')[0]!.setValue('s3');

        expect(optionLabels(wrapper)).toEqual(['planning.form.choose', 'planning.form.practitioner_empty']);
        const notice = wrapper.find('[data-testid="nobody-offers"]');
        expect(notice.exists()).toBe(true);
        expect(notice.find('a').attributes('href')).toBe('/configuration/assistant/praticiens');
    });

    it("explique quand il n'y a aucun praticien actif", () => {
        const wrapper = form([]);

        expect(optionLabels(wrapper)).toEqual(['planning.form.choose', 'planning.form.practitioner_empty']);
        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it("ne propose le lien de configuration qu'au propriétaire", () => {
        shared.auth.organization.role = 'receptionist';
        const wrapper = form([]);

        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="no-practitioners"] a').exists()).toBe(false);
    });

    it("choisit d'office l'unique praticien", () => {
        const wrapper = form([anna]);

        expect((practitionerSelect(wrapper).element as HTMLSelectElement).value).toBe('p1');
    });
});
