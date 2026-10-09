import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { h, reactive } from 'vue';
import BookingDetails from './BookingDetails.vue';

/**
 * Paiement d'un rendez-vous et pourboire : trois lignes distinctes (service, pourboire, total payé), un pourboire envoyé à part avec sa
 * répartition, et des contrôles avant l'envoi.
 */
const calls: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: { organization: { role: 'owner' } }, flash: { suggestions: [] }, errors: {} } }),
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
    useForm: (data: Record<string, unknown>) => {
        let transformer = (value: Record<string, unknown>): Record<string, unknown> => value;
        const form: Record<string, unknown> = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            transform(callback: typeof transformer) {
                transformer = callback;

                return form;
            },
            put(url: string) {
                calls.push({ url, payload: JSON.parse(JSON.stringify(transformer(Object.fromEntries(Object.keys(data).map((key) => [key, form[key]]))))) });
            },
            post: vi.fn(),
            reset: vi.fn(),
            clearErrors() {
                form.errors = {};
            },
            setError(key: string, message: string) {
                (form.errors as Record<string, string>)[key] = message;
            },
        });

        return form;
    },
}));

const lea = { id: 'p1', name: 'Léa', color: '#111111', schedule: {}, photo_version: null };
const marc = { id: 'p2', name: 'Marc', color: '#222222', schedule: {}, photo_version: null };

const booking = (overrides: Record<string, unknown> = {}) => ({
    id: 'b1',
    practitioner_id: 'p1',
    practitioner_name: 'Léa',
    service_id: 's1',
    date: '2026-06-02',
    customer: { id: 'c1', name: 'Client', phone: '+32470000000' },
    service: 'Coupe',
    starts: '10:00',
    ends: '10:30',
    start_minute: 600,
    end_minute: 630,
    status: 'completed',
    price_cents: 5000,
    currency: 'EUR',
    payment_status: 'paid_onsite',
    amount_collected_cents: 5000,
    tip: null,
    notes: null,
    is_past: true,
    ...overrides,
});

const details = (bookingOverrides: Record<string, unknown> = {}, practitioners = [lea, marc]) =>
    mount(BookingDetails, {
        props: {
            booking: booking(bookingOverrides),
            date: '2026-06-02',
            practitioners,
            services: [],
            canReassign: false,
            timezone: 'Europe/Brussels',
        } as never,
    });

const openPayment = async (wrapper: ReturnType<typeof details>) => {
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'planning.actions.payment')!
        .trigger('click');
};

describe('résumé du paiement', () => {
    it('sépare le prix du service, l’encaissé, le pourboire et le total payé', () => {
        const wrapper = details({
            tip: {
                amount_cents: 500,
                paid_at: '2026-06-02T10:00:00+00:00',
                allocations: [{ practitioner_id: 'p1', practitioner_name: 'Léa', amount_cents: 500 }],
            },
        });

        expect(wrapper.find('[data-testid="pay-price"]').text()).toBe('€50.00');
        expect(wrapper.find('[data-testid="pay-collected"]').text()).toBe('€50.00');
        expect(wrapper.find('[data-testid="pay-tip"]').text()).toContain('€5.00');
        expect(wrapper.find('[data-testid="pay-tip"]').text()).toContain('planning.tip.to');
        expect(wrapper.find('[data-testid="pay-total"]').text()).toContain('€55.00');
        expect(wrapper.find('[data-testid="pay-total"]').text()).toContain('planning.tip.includes_tip');
    });

    it('ne compte pas de pourboire quand il n’y en a pas', () => {
        const wrapper = details();

        expect(wrapper.find('[data-testid="pay-tip"]').text()).toBe('planning.tip.none');
        expect(wrapper.find('[data-testid="pay-total"]').text()).toContain('€50.00');
    });

    it('n’affiche pas de total payé tant que rien n’a été encaissé', () => {
        const wrapper = details({ payment_status: 'unpaid', amount_collected_cents: 0, status: 'confirmed' });

        expect(wrapper.find('[data-testid="pay-total"]').exists()).toBe(false);
    });

    it('utilise la devise du rendez-vous', () => {
        const wrapper = details({ currency: 'USD' });

        expect(wrapper.find('[data-testid="pay-price"]').text()).toBe('$50.00');
    });

    it('montre la répartition entre praticiens', () => {
        const wrapper = details({
            tip: {
                amount_cents: 1000,
                paid_at: '2026-06-02T10:00:00+00:00',
                allocations: [
                    { practitioner_id: 'p1', practitioner_name: 'Léa', amount_cents: 600 },
                    { practitioner_id: 'p2', practitioner_name: 'Marc', amount_cents: 400 },
                ],
            },
        });

        expect(wrapper.find('[data-testid="pay-tip"]').text()).toContain('€10.00');
    });
});

describe('formulaire de paiement avec pourboire', () => {
    beforeEach(() => {
        calls.length = 0;
    });

    const tipInput = (wrapper: ReturnType<typeof details>) => wrapper.find('[data-testid="tip-fields"] input');

    it('envoie le pourboire à part, sans répartition par défaut', async () => {
        const wrapper = details();
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('5');
        await wrapper.find('form').trigger('submit');

        expect(calls).toHaveLength(1);
        expect(calls[0]!.url).toBe('/planning/rendez-vous/b1/paiement');
        expect(calls[0]!.payload).toMatchObject({ payment_status: 'paid_onsite', tip: '5', tip_allocations: [] });
    });

    it('reprend le pourboire existant : l’enregistrer tel quel ne le modifie pas', async () => {
        const wrapper = details({
            tip: {
                amount_cents: 550,
                paid_at: '2026-06-02T10:00:00+00:00',
                allocations: [{ practitioner_id: 'p1', practitioner_name: 'Léa', amount_cents: 550 }],
            },
        });
        await openPayment(wrapper);

        expect((tipInput(wrapper).element as HTMLInputElement).value).toBe('5.50');
        await wrapper.find('form').trigger('submit');
        expect(calls[0]!.payload).toMatchObject({ tip: '5.50', tip_allocations: [] });
    });

    it('envoie un pourboire vide pour le supprimer', async () => {
        const wrapper = details({
            tip: {
                amount_cents: 500,
                paid_at: '2026-06-02T10:00:00+00:00',
                allocations: [{ practitioner_id: 'p1', practitioner_name: 'Léa', amount_cents: 500 }],
            },
        });
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('');
        await wrapper.find('form').trigger('submit');

        expect(calls[0]!.payload).toMatchObject({ tip: '', tip_allocations: [] });
    });

    it('bloque un montant invalide avant l’envoi', async () => {
        const wrapper = details();
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('abc');
        await wrapper.find('form').trigger('submit');

        expect(calls).toHaveLength(0);
        expect(wrapper.text()).toContain('planning.tip.invalid_amount');
    });

    it('envoie une répartition qui totalise le pourboire', async () => {
        const wrapper = details();
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('10');
        await wrapper.find('[data-testid="tip-fields"] input[type="checkbox"]').setValue(true);
        await flushPromises();

        const shares = wrapper.find('[data-testid="tip-shares"]').findAll('input');
        // Le total est d'abord proposé au praticien du rendez-vous.
        expect((shares[0]!.element as HTMLInputElement).value).toBe('10');

        await shares[0]!.setValue('6');
        await shares[1]!.setValue('4');
        await wrapper.find('form').trigger('submit');

        expect(calls[0]!.payload.tip_allocations).toEqual([
            { practitioner_id: 'p1', amount: '6' },
            { practitioner_id: 'p2', amount: '4' },
        ]);
    });

    it('bloque une répartition qui ne totalise pas le pourboire', async () => {
        const wrapper = details();
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('10');
        await wrapper.find('[data-testid="tip-fields"] input[type="checkbox"]').setValue(true);
        const shares = wrapper.find('[data-testid="tip-shares"]').findAll('input');
        await shares[0]!.setValue('6');
        await shares[1]!.setValue('3');
        await wrapper.find('form').trigger('submit');

        expect(calls).toHaveLength(0);
        expect(wrapper.text()).toContain('planning.errors.tip_allocation_mismatch');
    });

    it('demande de choisir le bénéficiaire quand le praticien du rendez-vous n’est plus actif', async () => {
        const wrapper = details({ practitioner_id: 'gone', practitioner_name: 'Ancien' });
        await openPayment(wrapper);
        await tipInput(wrapper).setValue('5');

        expect(wrapper.find('[data-testid="tip-no-practitioner"]').exists()).toBe(true);

        await wrapper.find('form').trigger('submit');
        expect(calls).toHaveLength(0);

        await wrapper.find('[data-testid="tip-shares"]').findAll('input')[1]!.setValue('5');
        await wrapper.find('form').trigger('submit');
        expect(calls[0]!.payload.tip_allocations).toEqual([{ practitioner_id: 'p2', amount: '5' }]);
    });
});
