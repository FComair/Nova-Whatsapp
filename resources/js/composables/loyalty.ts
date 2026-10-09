import type { BadgeVariant } from './supportTickets';
import { formatPrice } from './money';
import type { MemberStatus, ProgramStatus, RewardStatus, RewardType } from '../types/loyalty';

/** Aides du programme de fidélité : couleurs d'état, phrase de règle, valeurs de messages. Pures et testées. */

type Translate = (key: string, params?: Record<string, string | number>) => string;

/** Jamais la couleur seule : chaque badge porte aussi une icône et un libellé. */
export function programVariant(status: ProgramStatus | string): BadgeVariant {
    return ({ draft: 'neutral', active: 'success', paused: 'warning', archived: 'neutral' } as Record<string, BadgeVariant>)[status] ?? 'neutral';
}

export function memberVariant(status: MemberStatus | string): BadgeVariant {
    return (
        (
            { not_enrolled: 'neutral', in_progress: 'info', reward_available: 'success', redeemed: 'neutral', inactive: 'warning' } as Record<
                string,
                BadgeVariant
            >
        )[status] ?? 'neutral'
    );
}

export function rewardVariant(status: RewardStatus | string): BadgeVariant {
    return ({ available: 'success', redeemed: 'neutral', expired: 'warning', void: 'danger' } as Record<string, BadgeVariant>)[status] ?? 'neutral';
}

/** Règle telle qu'elle se lit dans l'écran de configuration et dans l'aperçu (noms de services déjà résolus). */
export interface RuleInput {
    required_visits: number;
    services_mode: 'all' | 'selected';
    earn_services: string[];
    min_amount_cents: number | null;
    require_payment: boolean;
    auto_enroll: boolean;
    reward_type: RewardType;
    reward_value: number | null;
    reward_max_cents: number | null;
    reward_services_mode: 'all' | 'selected';
    reward_services: string[];
    reward_description: string;
    redeem_timing: 'next_visit' | 'any_time';
    combinable: boolean;
    requires_appointment: boolean;
    expiry_days: number | null;
}

export interface RuleLines {
    earn: string[];
    reward: string[];
    use: string[];
}

/** « Les clients gagnent 1 tampon… Après 8 tampons, ils reçoivent : une coupe offerte. » en trois groupes de phrases. */
export function ruleLines(rule: RuleInput, t: Translate, locale: string): RuleLines {
    const money = (cents: number): string => formatPrice(cents, locale);
    const reward = rule.reward_description.trim() !== '' ? rule.reward_description.trim() : t('loyalty.rule.reward_pending');
    const earn: string[] = [];

    if (rule.services_mode === 'selected') {
        earn.push(
            rule.earn_services.length > 0 ? t('loyalty.rule.earn_selected', { services: rule.earn_services.join(', ') }) : t('loyalty.rule.earn_selected_none'),
        );
    } else {
        earn.push(t('loyalty.rule.earn_all'));
    }

    if (rule.min_amount_cents !== null && rule.min_amount_cents > 0) {
        earn.push(t('loyalty.rule.min_amount', { amount: money(rule.min_amount_cents) }));
    }

    if (rule.require_payment) {
        earn.push(t('loyalty.rule.payment'));
    }

    earn.push(t(rule.auto_enroll ? 'loyalty.rule.auto_enroll' : 'loyalty.rule.manual_enroll'));

    const lines: RuleLines = { earn, reward: [t('loyalty.rule.after', { count: rule.required_visits, reward })], use: [] };
    const kind = rewardKind(rule, t, money);

    if (kind !== '') {
        lines.reward.push(`${kind}.`);
    }

    if (rule.reward_services_mode === 'selected' && rule.reward_services.length > 0) {
        lines.reward.push(t('loyalty.rule.reward_services', { services: rule.reward_services.join(', ') }));
    }

    if (rule.reward_max_cents !== null && rule.reward_max_cents > 0) {
        lines.reward.push(t('loyalty.rule.max', { amount: money(rule.reward_max_cents) }));
    }

    lines.use.push(t(rule.redeem_timing === 'next_visit' ? 'loyalty.rule.timing_next' : 'loyalty.rule.timing_any'));
    lines.use.push(t(rule.combinable ? 'loyalty.rule.combinable_yes' : 'loyalty.rule.combinable_no'));
    lines.use.push(t(rule.requires_appointment ? 'loyalty.rule.appointment_yes' : 'loyalty.rule.appointment_no'));
    lines.use.push(rule.expiry_days === null ? t('loyalty.rule.no_expiry') : t('loyalty.rule.expiry', { count: rule.expiry_days }));

    return lines;
}

function rewardKind(rule: RuleInput, t: Translate, money: (cents: number) => string): string {
    switch (rule.reward_type) {
        case 'free_service':
            return t('loyalty.rule.reward_free');
        case 'percent_discount':
            return rule.reward_value === null ? '' : t('loyalty.rule.reward_percent', { value: rule.reward_value });
        case 'fixed_amount':
            return rule.reward_value === null ? '' : t('loyalty.rule.reward_fixed', { amount: money(rule.reward_value) });
        default:
            return '';
    }
}

/** Remplace les {variables} d'un texte de message ; une variable inconnue reste telle quelle (l'enregistrement les refuse côté serveur). */
export function renderMessage(text: string, values: Record<string, string>): string {
    return text.replace(/\{([a-z_]+)\}/gi, (match, name: string) => values[name] ?? match).trim();
}

/** Variables inconnues d'un texte, pour le signaler pendant la saisie. */
export function unknownVariables(text: string, known: string[]): string[] {
    const found = [...text.matchAll(/\{([a-z_]+)\}/gi)].map((match) => match[1] ?? '');

    return [...new Set(found.filter((name) => !known.includes(name)))];
}

/** Texte d'une langue, à défaut français puis la première renseignée (même chaîne que côté serveur). */
export function pickText(values: Record<string, string>, locale: string): string {
    for (const candidate of [locale, 'fr', ...Object.keys(values)]) {
        const value = (values[candidate] ?? '').trim();

        if (value !== '') {
            return value;
        }
    }

    return '';
}

/** « 12,50 » ou « 12.5 » → centimes ; null si ce n'est pas un montant valide. */
export function eurosToCents(input: string): number | null {
    const match = /^(\d{1,7})(?:[.,](\d{1,2}))?$/.exec(input.replace(/[\s €]/g, ''));

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0'));
}

/** Nombre de tampons affichés un par un ; au-delà, une barre de progression. */
export const MAX_STAMPS_SHOWN = 12;

/**
 * Texte d'un modèle venu du serveur dans une AUTRE langue que celle de l'interface (aperçu côté client) : choisit le singulier ou le pluriel
 * (« a|b », d'après `count`) puis remplace les :paramètres. Même règle que useT.
 */
export function fillTemplate(template: string, params: Record<string, string | number>, language: string): string {
    const forms = template.split('|');
    const count = typeof params.count === 'number' ? params.count : null;
    const text = forms.length > 1 && count !== null ? (((language === 'fr' ? count < 2 : count === 1) ? forms[0] : forms[1]) ?? template) : template;

    return text.replace(/:(\w+)/g, (_match, name: string) => String(params[name] ?? `:${name}`));
}

/**
 * Valeur d'une récompense pour un prix de service, en centimes (même calcul que LoyaltyRedemptions::valueFor) : service offert, pourcentage,
 * montant fixe, tous plafonnés au prix et au plafond de la récompense ; une récompense personnalisée n'a pas de valeur chiffrée.
 */
export function rewardValueCents(reward: { type: string; value: number | null; max_cents: number | null }, priceCents: number): number {
    const value = reward.value ?? 0;
    const amount =
        reward.type === 'free_service'
            ? priceCents
            : reward.type === 'percent_discount'
              ? Math.floor((priceCents * Math.min(100, value)) / 100)
              : reward.type === 'fixed_amount'
                ? Math.min(value, priceCents)
                : 0;

    return Math.max(0, Math.min(amount, priceCents, reward.max_cents ?? amount));
}
