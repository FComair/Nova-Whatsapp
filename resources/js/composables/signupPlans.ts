import { formatPrice } from './money';

/** Choix de la formule à l'inscription (docs/10) : types venus du serveur et textes du récapitulatif. Pures et testées. */

type Translate = (key: string, params?: Record<string, string | number>) => string;

export type LimitKey = 'practitioners' | 'locations' | 'conversations' | 'appointments';
export type Limits = Record<LimitKey, number | null>;

export interface PaidOffer {
    code: string;
    name: string;
    price_cents: number;
    currency: string;
    limits: Limits;
    recommended: boolean;
}

export interface Offers {
    trial: { days: number; limits: Limits | null; based_on: string | null; payment_method_required: boolean };
    plans: PaidOffer[];
    vat: { rate: number; country: string };
}

export const TRIAL = 'trial';
export const LIMIT_KEYS: LimitKey[] = ['practitioners', 'locations', 'conversations', 'appointments'];

/** « 1 praticien », « Jusqu'à 5 praticiens », « Rendez-vous : illimité » : une ligne par limite, jamais inventée (null = illimité côté formule). */
export function limitLines(limits: Limits | null, t: Translate): string[] {
    if (limits === null) {
        return [];
    }

    return LIMIT_KEYS.map((key) => {
        const value = limits[key];

        return value === null ? t('register.limit_unlimited', { what: t(`register.limit_names.${key}`) }) : t(`register.limit_${key}`, { count: value });
    });
}

/** Prix mensuel TTC au taux de TVA belge (arrondi comme le serveur : au centime le plus proche). */
export function priceWithVat(cents: number, rate: number): number {
    return cents + Math.round((cents * rate) / 100);
}

export interface SummaryLines {
    plan: string;
    price: string;
    trial: string;
    payment: string;
    after: string;
    vat: string;
}

/** Récapitulatif avant validation : formule, prix, essai, moyen de paiement, après l'essai, TVA. Jamais « gratuit » sans dire jusqu'à quand. */
export function summaryLines(offers: Offers, selected: string, t: Translate, locale: string): SummaryLines {
    const days = offers.trial.days;
    const plan = offers.plans.find((row) => row.code === selected);

    if (plan === undefined) {
        return {
            plan: t('register.plans.trial'),
            price: t('register.trial_summary_price', { count: days, days }),
            trial: t('register.trial_summary_trial', { count: days, days, plan: offers.trial.based_on ?? '' }),
            payment: t('register.trial_summary_payment'),
            after: t('register.trial_summary_after'),
            vat: t('register.trial_summary_vat', { rate: offers.vat.rate }),
        };
    }

    return {
        plan: plan.name,
        price: t('register.paid_summary_price', { price: formatPrice(plan.price_cents, locale, plan.currency) }),
        trial: t('register.paid_summary_trial', { count: days, days }),
        payment: t('register.paid_summary_payment'),
        after: t('register.paid_summary_after'),
        vat: t('register.paid_summary_vat', {
            rate: offers.vat.rate,
            total: formatPrice(priceWithVat(plan.price_cents, offers.vat.rate), locale, plan.currency),
        }),
    };
}
