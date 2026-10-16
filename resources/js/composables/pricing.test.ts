import { describe, expect, it } from 'vitest';
import { type PricedPlan, periodLabel, planPriceLines } from './pricing';

const t = (key: string, params: Record<string, string | number> = {}): string => `${key}${Object.keys(params).length > 0 ? JSON.stringify(params) : ''}`;
const format = (cents: number): string => `${(cents / 100).toFixed(2)} €`;

const plan = (over: Partial<PricedPlan> = {}): PricedPlan => ({
    code: 'pro',
    name: 'Pro',
    description: null,
    type: 'monthly',
    price_cents: 2999,
    currency: 'EUR',
    tax_mode: 'excluded',
    interval_months: 1,
    setup_fee_cents: 0,
    trial_days: null,
    limits: { practitioners: 1, locations: 1, conversations: 100, appointments: 50 },
    features: [],
    recommended: false,
    purchasable: true,
    cta: { type: 'choose_plan', url: '/inscription?plan=pro' },
    ...over,
});

describe('période de facturation', () => {
    it('nomme les périodes usuelles et compte les autres en mois', () => {
        expect(periodLabel(1, t)).toBe('billing.period.1');
        expect(periodLabel(3, t)).toBe('billing.period.3');
        expect(periodLabel(12, t)).toBe('billing.period.12');
        expect(periodLabel(6, t)).toBe('billing.period.custom{"count":6}');
    });
});

describe('prix affiché', () => {
    it('montre le prix, sa période, la TVA et les frais éventuels', () => {
        expect(planPriceLines(plan({ setup_fee_cents: 5000, trial_days: 14 }), t, format)).toEqual({
            main: '29.99 €',
            period: 'billing.period.1',
            tax: 'pricing.tax_excluded',
            setup: 'pricing.setup_fee{"price":"50.00 €"}',
            trial: 'pricing.trial_days{"days":14}',
        });
    });

    it("dit « TVA comprise » quand le prix l'est", () => {
        expect(planPriceLines(plan({ tax_mode: 'included' }), t, format).tax).toBe('pricing.tax_included');
    });

    it("n'invente ni prix ni période pour une formule gratuite, sur devis ou à paiement unique", () => {
        expect(planPriceLines(plan({ price_cents: 0 }), t, format)).toMatchObject({ main: 'pricing.free', period: null, tax: null });
        expect(planPriceLines(plan({ type: 'custom', price_cents: 0 }), t, format)).toMatchObject({ main: 'pricing.on_quote', period: null, tax: null });
        expect(planPriceLines(plan({ type: 'one_time' }), t, format)).toMatchObject({ main: '29.99 €', period: null });
    });
});
