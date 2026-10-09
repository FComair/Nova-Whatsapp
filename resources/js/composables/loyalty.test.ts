import { describe, expect, it } from 'vitest';
import {
    type RuleInput,
    eurosToCents,
    fillTemplate,
    memberVariant,
    pickText,
    programVariant,
    renderMessage,
    rewardValueCents,
    rewardVariant,
    ruleLines,
    unknownVariables,
} from './loyalty';

const t = (key: string, params: Record<string, string | number> = {}): string => `${key}${Object.keys(params).length ? `:${JSON.stringify(params)}` : ''}`;

const rule = (override: Partial<RuleInput> = {}): RuleInput => ({
    required_visits: 8,
    services_mode: 'all',
    earn_services: [],
    min_amount_cents: null,
    require_payment: false,
    auto_enroll: true,
    reward_type: 'free_service',
    reward_value: null,
    reward_max_cents: null,
    reward_services_mode: 'all',
    reward_services: [],
    reward_description: 'Une coupe offerte',
    redeem_timing: 'next_visit',
    combinable: false,
    requires_appointment: true,
    expiry_days: null,
    ...override,
});

describe('ruleLines', () => {
    it('résume la règle par défaut : gagner, récompense, utilisation', () => {
        const lines = ruleLines(rule(), t, 'fr');

        expect(lines.earn[0]).toBe('loyalty.rule.earn_all');
        expect(lines.earn).toContain('loyalty.rule.auto_enroll');
        expect(lines.reward[0]).toBe('loyalty.rule.after:{"count":8,"reward":"Une coupe offerte"}');
        expect(lines.reward[1]).toBe('loyalty.rule.reward_free.');
        expect(lines.use).toEqual(['loyalty.rule.timing_next', 'loyalty.rule.combinable_no', 'loyalty.rule.appointment_yes', 'loyalty.rule.no_expiry']);
    });

    it('cite les services choisis, le montant minimum, le paiement, le plafond et l’expiration', () => {
        const lines = ruleLines(
            rule({
                services_mode: 'selected',
                earn_services: ['Coupe', 'Brushing'],
                min_amount_cents: 2000,
                require_payment: true,
                reward_type: 'percent_discount',
                reward_value: 20,
                reward_max_cents: 1500,
                reward_services_mode: 'selected',
                reward_services: ['Coupe'],
                redeem_timing: 'any_time',
                combinable: true,
                requires_appointment: false,
                expiry_days: 60,
                auto_enroll: false,
            }),
            t,
            'fr',
        );

        expect(lines.earn[0]).toBe('loyalty.rule.earn_selected:{"services":"Coupe, Brushing"}');
        expect(lines.earn.some((line) => line.startsWith('loyalty.rule.min_amount'))).toBe(true);
        expect(lines.earn).toContain('loyalty.rule.payment');
        expect(lines.earn).toContain('loyalty.rule.manual_enroll');
        expect(lines.reward).toContain('loyalty.rule.reward_percent:{"value":20}.');
        expect(lines.reward).toContain('loyalty.rule.reward_services:{"services":"Coupe"}');
        expect(lines.reward.some((line) => line.startsWith('loyalty.rule.max'))).toBe(true);
        expect(lines.use).toEqual([
            'loyalty.rule.timing_any',
            'loyalty.rule.combinable_yes',
            'loyalty.rule.appointment_no',
            'loyalty.rule.expiry:{"count":60}',
        ]);
    });

    it('reste lisible tant que la récompense n’est pas décrite et qu’aucun service n’est choisi', () => {
        const lines = ruleLines(rule({ reward_description: '  ', services_mode: 'selected', earn_services: [] }), t, 'fr');

        expect(lines.earn[0]).toBe('loyalty.rule.earn_selected_none');
        expect(lines.reward[0]).toContain('loyalty.rule.reward_pending');
    });
});

describe('messages', () => {
    it('remplace les variables connues et laisse les autres visibles', () => {
        expect(renderMessage('Merci {name} : {progress} {inconnue}', { name: 'Camille', progress: '6/8' })).toBe('Merci Camille : 6/8 {inconnue}');
    });

    it('repère les variables inconnues, une seule fois chacune', () => {
        expect(unknownVariables('{name} {x} {x} {y}', ['name'])).toEqual(['x', 'y']);
        expect(unknownVariables('Bonjour', ['name'])).toEqual([]);
    });
});

describe('textes multilingues', () => {
    it('choisit la langue, sinon le français, sinon la première renseignée', () => {
        expect(pickText({ fr: 'Bonjour', nl: 'Hallo', en: '' }, 'nl')).toBe('Hallo');
        expect(pickText({ fr: 'Bonjour', nl: '', en: '' }, 'en')).toBe('Bonjour');
        expect(pickText({ fr: '', nl: '', en: 'Hello' }, 'nl')).toBe('Hello');
        expect(pickText({ fr: ' ', nl: '', en: '' }, 'fr')).toBe('');
    });

    it('remplit un modèle dans une autre langue que l’interface, avec singulier et pluriel', () => {
        expect(fillTemplate('Nog :count bezoek|Nog :count bezoeken', { count: 1 }, 'nl')).toBe('Nog 1 bezoek');
        expect(fillTemplate('Nog :count bezoek|Nog :count bezoeken', { count: 2 }, 'nl')).toBe('Nog 2 bezoeken');
        expect(fillTemplate('Encore :count visite|Encore :count visites', { count: 0 }, 'fr')).toBe('Encore 0 visite');
        expect(fillTemplate(':stamps sur :required', { stamps: 6, required: 8 }, 'fr')).toBe('6 sur 8');
    });
});

describe('montants', () => {
    it('lit les montants saisis en euros', () => {
        expect(eurosToCents('12,50')).toBe(1250);
        expect(eurosToCents('12.5')).toBe(1250);
        expect(eurosToCents('7')).toBe(700);
        expect(eurosToCents('1 200 €')).toBe(120000);
        expect(eurosToCents('')).toBeNull();
        expect(eurosToCents('abc')).toBeNull();
        expect(eurosToCents('1,234')).toBeNull();
        expect(eurosToCents('-3')).toBeNull();
    });

    it.each([
        ['free_service', null, null, 2500],
        ['free_service', null, 1500, 1500],
        ['percent_discount', 10, null, 250],
        ['percent_discount', 50, 1000, 1000],
        ['fixed_amount', 1000, null, 1000],
        ['fixed_amount', 9000, null, 2500],
        ['custom', null, null, 0],
    ])('calcule la valeur d’une récompense %s (valeur %s, plafond %s) : %s', (type, value, max, expected) => {
        expect(rewardValueCents({ type, value, max_cents: max }, 2500)).toBe(expected);
    });
});

describe('états', () => {
    it('donne une couleur à chaque état du programme, des clients et des récompenses', () => {
        expect(programVariant('active')).toBe('success');
        expect(programVariant('paused')).toBe('warning');
        expect(programVariant('draft')).toBe('neutral');
        expect(memberVariant('reward_available')).toBe('success');
        expect(memberVariant('inactive')).toBe('warning');
        expect(memberVariant('inconnu')).toBe('neutral');
        expect(rewardVariant('expired')).toBe('warning');
        expect(rewardVariant('void')).toBe('danger');
    });
});
