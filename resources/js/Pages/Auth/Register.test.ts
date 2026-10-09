import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import PlanPicker from '../../Components/PlanPicker.vue';
import { type Offers, limitLines, priceWithVat, summaryLines } from '../../composables/signupPlans';
import Register from './Register.vue';

const post = vi.fn();
const shared = reactive({ locale: 'fr', locales: ['fr', 'nl', 'en'], translations: {}, auth: { user: null, organization: null }, flash: {}, errors: {} });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            post: (...args: unknown[]) => post(...args),
            reset: vi.fn(),
            clearErrors: () => (form.errors = {}),
            setError: (field: string, message: string) => ((form.errors as Record<string, string>)[field] = message),
        });

        return form;
    },
}));

const t = (key: string, params: Record<string, string | number> = {}): string => `${key}${Object.keys(params).length ? `:${JSON.stringify(params)}` : ''}`;
const offers = (override: Partial<Offers> = {}): Offers => ({
    trial: {
        days: 14,
        limits: { practitioners: 5, locations: 1, conversations: 600, appointments: 380 },
        based_on: 'Business',
        payment_method_required: false,
    },
    plans: [
        {
            code: 'basic',
            name: 'Basic',
            price_cents: 2999,
            currency: 'EUR',
            limits: { practitioners: 1, locations: 1, conversations: 150, appointments: 90 },
            recommended: false,
        },
        {
            code: 'business',
            name: 'Business',
            price_cents: 4999,
            currency: 'EUR',
            limits: { practitioners: 5, locations: null, conversations: 600, appointments: 380 },
            recommended: false,
        },
    ],
    vat: { rate: 21, country: 'BE' },
    ...override,
});
const sectors = [{ value: 'hairdressing', label: 'Coiffure' }];
const mountRegister = (props: Record<string, unknown> = {}) =>
    mount(Register, {
        props: { sectors, plan: 'trial', offers: offers(), ...props } as never,
        global: { stubs: { AuthLayout: { template: '<div><slot /><slot name="footer" /></div>' } } },
    });

beforeEach(() => post.mockReset());

describe('textes du récapitulatif', () => {
    it('calcule le prix TTC au taux de TVA donné, arrondi au centime', () => {
        expect(priceWithVat(2999, 21)).toBe(3629);
        expect(priceWithVat(4999, 21)).toBe(6049);
        expect(priceWithVat(1000, 0)).toBe(1000);
    });

    it('une ligne par limite, « illimité » seulement quand la formule n’en a pas, rien sans données', () => {
        expect(limitLines({ practitioners: 1, locations: null, conversations: 150, appointments: 90 }, t)).toEqual([
            'register.limit_practitioners:{"count":1}',
            'register.limit_unlimited:{"what":"register.limit_names.locations"}',
            'register.limit_conversations:{"count":150}',
            'register.limit_appointments:{"count":90}',
        ]);
        expect(limitLines(null, t)).toEqual([]);
    });

    it('le récapitulatif de l’essai dit combien de jours, qu’aucun paiement n’est demandé et ce qui suit la fin de l’essai', () => {
        const lines = summaryLines(offers(), 'trial', t, 'fr');

        expect(lines.price).toContain('register.trial_summary_price');
        expect(lines.price).toContain('"days":14');
        expect(lines.trial).toContain('"plan":"Business"');
        expect(lines.payment).toBe('register.trial_summary_payment');
        expect(lines.after).toBe('register.trial_summary_after');
        expect(lines.vat).toContain('"rate":21');
    });

    it('le récapitulatif d’une formule payante donne nom, prix mensuel, essai, moyen de paiement, suite et TVA', () => {
        const lines = summaryLines(offers(), 'business', t, 'fr');

        expect(lines.plan).toBe('Business');
        expect(lines.price).toContain('register.paid_summary_price');
        expect(lines.price).toMatch(/49,99/);
        expect(lines.trial).toContain('"days":14');
        expect(lines.payment).toBe('register.paid_summary_payment');
        expect(lines.after).toBe('register.paid_summary_after');
        expect(lines.vat).toMatch(/60,49/);
    });
});

describe('PlanPicker', () => {
    const picker = (props: Record<string, unknown> = {}) =>
        mount(PlanPicker, {
            props: { offers: offers(), modelValue: 'trial', 'onUpdate:modelValue': () => undefined, ...props } as never,
            attachTo: document.body,
        });

    it('propose l’essai gratuit puis chaque formule payante, avec prix et limites de la configuration', () => {
        const wrapper = picker();
        const cards = wrapper.findAll('label[data-testid^="plan-"]');

        expect(cards.map((card) => card.attributes('data-testid'))).toEqual(['plan-trial', 'plan-basic', 'plan-business']);
        expect(cards[1]!.text()).toContain('Basic');
        expect(cards[1]!.text()).toContain('register.price_month');
        expect(cards[1]!.text()).toContain('register.limit_practitioners');
        expect(cards[0]!.text()).toContain('register.trial_price');
        expect(cards[0]!.text()).toContain('register.trial_after');
    });

    it('utilise des boutons radio natifs d’un même groupe, la sélection se voit sans la couleur', async () => {
        const wrapper = picker({ modelValue: 'basic' });
        const radios = wrapper.findAll('input[type="radio"]');

        expect(radios).toHaveLength(3);
        expect(new Set(radios.map((radio) => radio.attributes('name'))).size).toBe(1);
        expect(wrapper.find('legend').text()).toBe('register.plan_group');
        expect(wrapper.findAll('[data-testid="selected-mark"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="plan-basic"] [data-testid="selected-mark"]').text()).toBe('register.selected');
        expect((radios[1]!.element as HTMLInputElement).checked).toBe(true);
    });

    it('change la sélection en cliquant sur une carte', async () => {
        const wrapper = picker();

        await wrapper.find('[data-testid="plan-business"] input').setValue(true);

        expect(wrapper.emitted('update:modelValue')).toEqual([['business']]);
    });

    it('n’affiche « Recommandée » que pour la formule désignée par la plateforme', () => {
        expect(picker().findAll('[data-testid="recommended"]')).toHaveLength(0);

        const plans = offers().plans.map((plan) => ({ ...plan, recommended: plan.code === 'business' }));
        const wrapper = picker({ offers: offers({ plans }) });

        expect(wrapper.findAll('[data-testid="recommended"]')).toHaveLength(1);
        expect(wrapper.find('[data-testid="plan-business"] [data-testid="recommended"]').exists()).toBe(true);
    });

    it('sans formule payante, ne propose que l’essai', () => {
        expect(picker({ offers: offers({ plans: [] }) }).findAll('label[data-testid^="plan-"]')).toHaveLength(1);
    });

    it('annonce une erreur de choix', () => {
        expect(picker({ error: 'Choisissez une formule.' }).find('[role="alert"]').text()).toBe('Choisissez une formule.');
    });
});

describe('Register', () => {
    const fill = async (wrapper: ReturnType<typeof mountRegister>) => {
        await wrapper.find('input[type="email"]').setValue('nouveau@example.com');
        await wrapper.find('input[type="password"]').setValue('Sol1de-Phrase-Pour-Test!');
        await wrapper.find('input[type="tel"]').setValue('+32 470 12 34 56');
        await wrapper.find('select').setValue('hairdressing');
    };
    const next = (wrapper: ReturnType<typeof mountRegister>) => wrapper.findAll('button').find((button) => button.text() === 'register.continue')!;

    it('commence par les coordonnées, formule choisie ensuite', () => {
        const wrapper = mountRegister();

        expect(wrapper.find('[data-testid="plan-cards"]').isVisible()).toBe(false);
        expect(wrapper.text()).toContain('register.steps_details');
    });

    it('exige les coordonnées avant de passer au choix de la formule', async () => {
        const wrapper = mountRegister();

        await next(wrapper).trigger('click');

        expect(wrapper.findAll('[role="alert"]').length).toBeGreaterThanOrEqual(4);
        expect(wrapper.find('[data-testid="plan-cards"]').isVisible()).toBe(false);
    });

    it('passe à la formule, montre le récapitulatif de la sélection et le met à jour au changement', async () => {
        const wrapper = mountRegister();
        await fill(wrapper);
        await next(wrapper).trigger('click');

        expect(wrapper.find('[data-testid="plan-cards"]').isVisible()).toBe(true);
        expect(wrapper.find('[data-testid="summary-plan"]').text()).toBe('register.plans.trial');

        await wrapper.find('[data-testid="plan-business"] input').setValue(true);

        expect(wrapper.find('[data-testid="summary-plan"]').text()).toBe('Business');
        expect(wrapper.find('[data-testid="summary"]').text()).toContain('register.paid_summary_payment');
        expect(wrapper.find('[data-testid="summary"]').text()).toContain('register.paid_summary_after');
    });

    it('conserve les coordonnées quand on revient de la formule', async () => {
        const wrapper = mountRegister();
        await fill(wrapper);
        await next(wrapper).trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'register.back')!
            .trigger('click');

        expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('nouveau@example.com');
        expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('hairdressing');
    });

    it('envoie la formule choisie avec les coordonnées, une seule fois par validation', async () => {
        const wrapper = mountRegister({ plan: 'basic' });
        await fill(wrapper);
        await next(wrapper).trigger('click');
        await wrapper.find('form').trigger('submit');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/inscription', expect.anything());
        expect(wrapper.find('[data-testid="plan-basic"] [data-testid="selected-mark"]').exists()).toBe(true);
    });

    it('revient aux coordonnées quand le serveur en refuse une, et garde le choix de formule', async () => {
        const wrapper = mountRegister({ plan: 'business' });
        await fill(wrapper);
        await next(wrapper).trigger('click');
        const form = (wrapper.vm as unknown as { form: { errors: Record<string, string> } }).form;

        form.errors = { email: 'Adresse déjà utilisée.' };
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="plan-cards"]').isVisible()).toBe(false);
        expect(wrapper.text()).toContain('Adresse déjà utilisée.');
        expect(wrapper.find('[data-testid="plan-business"] [data-testid="selected-mark"]').exists()).toBe(true);
    });

    it('ne parle jamais d’un essai « gratuit » sans dire ce qui se passe à sa fin', async () => {
        const wrapper = mountRegister();
        await fill(wrapper);
        await next(wrapper).trigger('click');

        expect(wrapper.find('[data-testid="summary"]').text()).toContain('register.trial_summary_after');
        expect(wrapper.find('[data-testid="plan-trial"]').text()).toContain('register.trial_after');
    });
});
