import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TipFields from './TipFields.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

const practitioners = [
    { id: 'p1', name: 'Anna' },
    { id: 'p2', name: 'Ben' },
];

function fields(props: Record<string, unknown> = {}) {
    return mount(TipFields, {
        props: {
            tip: '',
            split: false,
            shares: {},
            practitioners,
            assigned: { id: 'p1', name: 'Anna', active: true },
            currency: 'EUR',
            paymentStatus: 'paid_onsite',
            'onUpdate:tip': () => undefined,
            'onUpdate:split': () => undefined,
            'onUpdate:shares': () => undefined,
            ...props,
        } as never,
    });
}

describe('TipFields', () => {
    it('ne demande rien de plus tant qu’il n’y a pas de pourboire (vide ou zéro)', () => {
        for (const tip of ['', '0', '0,00']) {
            const wrapper = fields({ tip });

            expect(wrapper.find('[data-testid="tip-recipient"]').exists()).toBe(false);
            expect(wrapper.find('[data-testid="tip-shares"]').exists()).toBe(false);
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        }
    });

    it('attribue par défaut le pourboire au praticien du rendez-vous', () => {
        const wrapper = fields({ tip: '5' });

        expect(wrapper.find('[data-testid="tip-recipient"]').text()).toBe('planning.tip.goes_to');
        expect(wrapper.find('[data-testid="tip-shares"]').exists()).toBe(false);
    });

    it('propose de répartir entre plusieurs praticiens, et dit si les parts totalisent le pourboire', async () => {
        const wrapper = fields({ tip: '10', split: true, shares: { p1: '6', p2: '3' } });
        const balance = () => wrapper.find('[data-testid="tip-balance"]').text();

        expect(wrapper.find('[data-testid="tip-shares"]').findAll('input')).toHaveLength(2);
        expect(balance()).toContain('planning.tip.remaining');

        await wrapper.setProps({ shares: { p1: '6', p2: '4' } });
        expect(balance()).toContain('planning.tip.balanced');

        await wrapper.setProps({ shares: { p1: '6', p2: '5' } });
        expect(balance()).toContain('planning.tip.over');
    });

    it('remonte la saisie d’une part', async () => {
        const wrapper = fields({ tip: '10', split: true, shares: { p1: '6' } });
        await wrapper.find('[data-testid="tip-shares"]').findAll('input')[1]!.setValue('4');

        expect(wrapper.emitted('update:shares')?.at(-1)).toEqual([{ p1: '6', p2: '4' }]);
    });

    it('exige de choisir le bénéficiaire quand aucun praticien actif n’est attribué', () => {
        const wrapper = fields({ tip: '5', assigned: { id: 'gone', name: null, active: false } });

        expect(wrapper.find('[data-testid="tip-no-practitioner"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tip-shares"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="tip-recipient"]').exists()).toBe(false);
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
    });

    it('prévient qu’un pourboire suppose un paiement reçu', () => {
        expect(fields({ tip: '5', paymentStatus: 'unpaid' }).find('[data-testid="tip-needs-payment"]').exists()).toBe(true);
        expect(fields({ tip: '5', paymentStatus: 'written_off' }).find('[data-testid="tip-needs-payment"]').exists()).toBe(true);
        expect(fields({ tip: '5', paymentStatus: 'waived' }).find('[data-testid="tip-needs-payment"]').exists()).toBe(false);
        expect(fields({ tip: '', paymentStatus: 'unpaid' }).find('[data-testid="tip-needs-payment"]').exists()).toBe(false);
    });

    it('signale un montant invalide ou une erreur du serveur', () => {
        expect(fields({ tip: 'abc' }).text()).toContain('planning.tip.invalid_amount');
        expect(fields({ tip: '-5' }).text()).toContain('planning.tip.invalid_amount');
        expect(fields({ tip: '5', errors: { tip: 'Server says no' } }).text()).toContain('Server says no');
    });

    it('ne propose pas de répartition quand il n’y a qu’un praticien', () => {
        const wrapper = fields({ tip: '5', practitioners: [practitioners[0]] });

        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
    });
});
