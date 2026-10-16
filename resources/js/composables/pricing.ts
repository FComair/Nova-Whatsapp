import type { Limits } from './signupPlans';

/** Formules telles que la page tarifs publique et l'inscription les reçoivent (voir PlanCatalog::present). Fonctions pures et testées. */

type Translate = (key: string, params?: Record<string, string | number>) => string;

export type FeatureState = 'included' | 'limited' | 'unavailable';
export type PlanType = 'free_trial' | 'monthly' | 'annual' | 'custom' | 'one_time';

export interface PricedPlan {
    code: string;
    name: string;
    description: string | null;
    type: PlanType;
    price_cents: number;
    currency: string;
    tax_mode: 'excluded' | 'included';
    interval_months: number;
    setup_fee_cents: number;
    trial_days: number | null;
    limits: Limits;
    features: { label: string; state: FeatureState; value: string | null }[];
    recommended: boolean;
    purchasable: boolean;
    cta: { type: 'choose_plan' | 'start_trial' | 'contact_sales'; url: string };
}

export interface PriceLines {
    /** « 29,99 € », « Gratuit » ou « Sur devis ». */
    main: string;
    /** « mois », « trimestre », « an » ou « 6 mois » ; null quand il n'y a pas de prix récurrent à afficher. */
    period: string | null;
    tax: string | null;
    setup: string | null;
    trial: string | null;
}

/** Période de facturation en toutes lettres : jamais un prix sans sa période. */
export function periodLabel(months: number, t: Translate): string {
    return [1, 3, 12].includes(months) ? t(`billing.period.${months}`) : t('billing.period.custom', { count: months });
}

export function planPriceLines(plan: PricedPlan, t: Translate, format: (cents: number) => string): PriceLines {
    const priced = plan.price_cents > 0 && plan.type !== 'custom';

    return {
        main: plan.type === 'custom' ? t('pricing.on_quote') : plan.price_cents === 0 ? t('pricing.free') : format(plan.price_cents),
        period: priced && plan.type !== 'one_time' ? periodLabel(plan.interval_months, t) : null,
        tax: priced ? t(plan.tax_mode === 'included' ? 'pricing.tax_included' : 'pricing.tax_excluded') : null,
        setup: plan.setup_fee_cents > 0 ? t('pricing.setup_fee', { price: format(plan.setup_fee_cents) }) : null,
        trial: plan.trial_days ? t('pricing.trial_days', { days: plan.trial_days }) : null,
    };
}
