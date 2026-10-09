import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Customers from './Customers.vue';
import Index from './Index.vue';
import Messages from './Messages.vue';
import Redeem from './Redeem.vue';
import Report from './Report.vue';
import Setup from './Setup.vue';

const put = vi.fn();
const post = vi.fn();
const get = vi.fn();
const shared = reactive({
    locale: 'fr',
    locales: ['fr', 'nl', 'en'],
    translations: {},
    auth: { user: { id: 'u1' }, organization: { id: 'o1', name: 'Salon', role: 'owner', logo_version: null } },
    flash: { status: null, warning: null, first_login: false, suggestions: [] },
    errors: {} as Record<string, string>,
    support: null,
    settings: null,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared, url: '/clients/fidelite' }),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...args: unknown[]) => get(...args), post: (...args: unknown[]) => post(...args), on: vi.fn() },
    useForm: (data: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...data,
            errors: {},
            processing: false,
            isDirty: false,
            put: (...args: unknown[]) => put(...args),
            post: (...args: unknown[]) => post(...args),
            reset: vi.fn(),
            defaults: vi.fn(),
            data: () => ({ ...data }),
        });
        form.transform = () => form;

        return form;
    },
}));

const stubs = { AppLayout: { template: '<div><slot /></div>' }, ConfirmDialog: true };
const can = { view: true, configure: true, redeem: true, adjust: true };
const program = {
    id: 'p1',
    status: 'active',
    name: 'Fidélité',
    reward_description: 'Une coupe offerte',
    terms: 'Non cumulable.',
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
    redeem_timing: 'next_visit',
    combinable: false,
    requires_appointment: true,
    expiry_days: null,
    activated_at: '2026-06-01T10:00:00Z',
};
const mountPage = (component: unknown, props: Record<string, unknown>) => mount(component as never, { props: props as never, global: { stubs } });

beforeEach(() => {
    put.mockReset();
    post.mockReset();
    get.mockReset();
    shared.errors = {};
    shared.auth = { user: { id: 'u1' }, organization: { id: 'o1', name: 'Salon', role: 'owner', logo_version: null } };
});

describe('Index', () => {
    it('invite à configurer le programme tant qu’il n’existe pas, et dit au personnel qui peut le faire', () => {
        const owner = mountPage(Index, { can, program: null, overview: null, recent: [], report: null });
        expect(owner.find('[data-testid="loyalty-empty"]').text()).toContain('loyalty.empty.not_set_up');
        expect(owner.text()).toContain('loyalty.empty.set_up');

        const staff = mountPage(Index, { can: { ...can, configure: false }, program: null, overview: null, recent: [], report: null });
        expect(staff.text()).toContain('loyalty.empty.not_set_up_staff');
        expect(staff.text()).not.toContain('loyalty.empty.set_up');
    });

    it('montre la règle, les chiffres, les clients proches de leur récompense et l’activité', () => {
        const overview = {
            enrolled: 12,
            opted_out: 1,
            in_progress: 7,
            rewards_earned: 4,
            rewards_redeemed: 3,
            rewards_outstanding: 1,
            rewards_expired: 0,
            stamps_in_circulation: 30,
            close: [{ customer_id: 'c1', name: 'Camille', phone: '+32470000001', stamps: 7, remaining: 1 }],
        };
        const recent = [
            {
                id: 'e1',
                kind: 'reward_issued',
                delta: -8,
                balance: 0,
                reason: null,
                detail: null,
                at: '2026-06-05T10:00:00Z',
                customer_id: 'c1',
                customer: 'Camille',
                actor: null,
            },
        ];
        const wrapper = mountPage(Index, { can, program, overview, recent, report: null });

        expect(wrapper.find('[data-testid="kpi-enrolled"]').text()).toBe('12');
        expect(wrapper.find('[data-testid="kpi-rewards_outstanding"]').text()).toBe('1');
        expect(wrapper.find('[data-testid="rule-summary"]').text()).toContain('loyalty.rule.earn_all');
        expect(wrapper.text()).toContain('Camille');
        expect(wrapper.find('[data-testid="activity-list"]').text()).toContain('loyalty.kind.reward_issued');
        expect(wrapper.find('[data-testid="no-progress"]').exists()).toBe(false);
    });

    it('affiche l’état vide « aucun client n’a encore de progression »', () => {
        const overview = {
            enrolled: 0,
            opted_out: 0,
            in_progress: 0,
            rewards_earned: 0,
            rewards_redeemed: 0,
            rewards_outstanding: 0,
            rewards_expired: 0,
            stamps_in_circulation: 0,
            close: [],
        };

        expect(mountPage(Index, { can, program, overview, recent: [], report: null }).find('[data-testid="no-progress"]').text()).toContain(
            'loyalty.empty.no_progress',
        );
    });
});

describe('Customers', () => {
    const base = {
        can,
        program,
        pagination: { page: 1, last: 1, total: 2 },
        filters: { status: '', q: '' },
        statuses: ['not_enrolled', 'in_progress', 'reward_available', 'redeemed', 'inactive'],
    };
    const rows = [
        {
            customer_id: 'c1',
            name: 'Camille',
            phone: '+32470000001',
            stamps: 3,
            required: 8,
            rewards_available: 1,
            last_visit_at: '2026-06-05T10:00:00Z',
            status: 'reward_available',
        },
        { customer_id: 'c2', name: null, phone: '+32470000002', stamps: null, required: 8, rewards_available: 0, last_visit_at: null, status: 'not_enrolled' },
    ];

    it('liste progression, récompenses, dernière visite et statut, et propose d’utiliser une récompense disponible', () => {
        const wrapper = mountPage(Customers, { ...base, customers: rows });
        const lines = wrapper.findAll('tbody tr');

        expect(lines).toHaveLength(2);
        expect(lines[0]!.text()).toContain('loyalty.progress.of');
        expect(lines[0]!.text()).toContain('05/06/2026');
        expect(lines[0]!.text()).toContain('loyalty.member_status.reward_available');
        expect(lines[0]!.find('a[href*="utiliser?customer=c1"]').exists()).toBe(true);
        expect(lines[1]!.text()).toContain('loyalty.customers.unnamed');
        expect(lines[1]!.text()).toContain('loyalty.customers.never');
        expect(lines[1]!.text()).toContain('loyalty.member_status.not_enrolled');
    });

    it('affiche l’état vide quand aucun client ne correspond', () => {
        expect(
            mountPage(Customers, { ...base, customers: [], pagination: { page: 1, last: 1, total: 0 } })
                .find('[data-testid="no-customers"]')
                .exists(),
        ).toBe(true);
    });

    it('filtre par statut côté serveur', async () => {
        const wrapper = mountPage(Customers, { ...base, customers: rows });

        await wrapper.find('select').setValue('inactive');

        expect(get).toHaveBeenCalledWith('/clients/fidelite/clients', expect.objectContaining({ status: 'inactive' }), expect.anything());
    });
});

describe('Setup', () => {
    const values = {
        name: { fr: 'Fidélité', nl: '', en: '' },
        reward_description: { fr: 'Une coupe offerte', nl: '', en: '' },
        terms: { fr: 'Non cumulable.', nl: '', en: '' },
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
        redeem_timing: 'next_visit',
        combinable: false,
        requires_appointment: true,
        expiry_days: null,
        expiry_reminder_days: 14,
    };
    const emptyValues = { ...values, name: { fr: '', nl: '', en: '' }, reward_description: { fr: '', nl: '', en: '' }, terms: { fr: '', nl: '', en: '' } };
    const i18n = (language: string) => ({
        of: ':stamps sur :required',
        remaining: ':count',
        ready: 'prêt',
        title: language,
        terms: 'T',
        data_title: 'D',
        data: 'd',
        marketing: 'm',
        expires: 'e',
        reward_label: 'r',
    });
    const props = (override: Record<string, unknown> = {}) => ({
        program: { status: 'draft', activated_at: null },
        values,
        problems: [],
        incomplete_languages: ['nl', 'en'],
        has_progress: false,
        impact: null,
        services: [
            { id: 's1', name: 'Coupe', price_cents: 2500 },
            { id: 's2', name: 'Couleur', price_cents: 6000 },
        ],
        locales: ['fr', 'nl', 'en'],
        preview_i18n: { fr: i18n('fr'), nl: i18n('nl'), en: i18n('en') },
        ...override,
    });

    it('guide en cinq étapes, avec la règle toujours résumée à côté', async () => {
        const wrapper = mountPage(Setup, props());
        const sections = () =>
            wrapper
                .findAll('section[aria-labelledby^="step-"]')
                .filter((section) => section.attributes('style') === undefined || !section.attributes('style')!.includes('display: none'));

        expect(wrapper.findAll('ol button')).toHaveLength(5);
        expect(sections()).toHaveLength(1);
        expect(wrapper.find('[data-testid="rule-summary"]').exists()).toBe(true);

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'loyalty.setup.next')!
            .trigger('click');
        expect(wrapper.find('#step-earn').isVisible()).toBe(true);
    });

    it('liste en direct ce qui manque avant l’activation, et rien quand tout est renseigné', async () => {
        const incomplete = mountPage(Setup, props({ values: emptyValues }));
        await incomplete.findAll('ol button')[4]!.trigger('click');
        const text = incomplete.find('[data-testid="problems"]').text();

        for (const code of ['name', 'reward_description', 'terms']) {
            expect(text).toContain(`loyalty.setup.problems.${code}`);
        }

        const complete = mountPage(Setup, props());
        await complete.findAll('ol button')[4]!.trigger('click');
        expect(complete.find('[data-testid="problems"]').exists()).toBe(false);
        expect(complete.text()).toContain('loyalty.setup.checklist_ok');
    });

    it('montre l’aperçu côté client et signale les langues incomplètes', async () => {
        const wrapper = mountPage(Setup, props());
        await wrapper.findAll('ol button')[4]!.trigger('click');

        expect(wrapper.find('[data-testid="loyalty-card"]').text()).toContain('Fidélité');
        expect(wrapper.text()).toContain('loyalty.setup.language_missing');
    });

    it('active seulement un brouillon valide et enregistré', () => {
        const draft = mountPage(Setup, props());
        const activate = draft.findAll('button').find((button) => button.text() === 'loyalty.setup.activate')!;
        expect(activate.attributes('disabled')).toBeUndefined();

        const invalid = mountPage(Setup, props({ problems: ['name'] }));
        expect(
            invalid
                .findAll('button')
                .find((button) => button.text() === 'loyalty.setup.activate')!
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('sans programme, ne propose ni activation ni pause', () => {
        const wrapper = mountPage(Setup, props({ program: null }));

        expect(wrapper.text()).not.toContain('loyalty.setup.activate');
        expect(wrapper.text()).not.toContain('loyalty.setup.pause');
    });

    it('un programme actif propose pause et archivage', () => {
        const wrapper = mountPage(Setup, props({ program: { status: 'active', activated_at: '2026-06-01T10:00:00Z' } }));

        expect(wrapper.text()).toContain('loyalty.setup.pause');
        expect(wrapper.text()).toContain('loyalty.setup.archive');
        expect(wrapper.text()).not.toContain('loyalty.setup.activate');
    });

    it('présente l’effet d’un changement avant de l’appliquer, et ne l’applique qu’à la confirmation', async () => {
        const impact = {
            fields: ['required_visits'],
            members: 12,
            in_progress: 5,
            required_from: 8,
            required_to: 6,
            rewards_now: 2,
            further_away: 0,
            available_rewards: 3,
            reward_changed: false,
            earn_rule_changed: false,
            active: true,
        };
        const wrapper = mountPage(Setup, props({ program: { status: 'active', activated_at: '2026-06-01T10:00:00Z' }, has_progress: true, impact }));
        const panel = wrapper.find('[data-testid="impact"]');

        expect(panel.text()).toContain('loyalty.setup.impact.lowered');
        expect(panel.text()).toContain('loyalty.setup.impact.rewards_kept');
        expect(put).not.toHaveBeenCalled();

        await panel
            .findAll('button')
            .find((button) => button.text() === 'loyalty.setup.impact.confirm')!
            .trigger('click');
        expect(put).toHaveBeenCalledWith('/clients/fidelite/programme', expect.objectContaining({ preserveState: true }));
    });

    it('ne montre pas de panneau d’effet sans changement à confirmer', () => {
        expect(mountPage(Setup, props()).find('[data-testid="impact"]').exists()).toBe(false);
    });
});

describe('Messages', () => {
    const settings = Object.fromEntries(
        ['joined', 'progress', 'reward_earned', 'reward_redeemed', 'expiry_reminder'].map((kind) => [
            kind,
            { enabled: false, texts: { fr: '', nl: '', en: '' } },
        ]),
    );
    const props = (override: Record<string, unknown> = {}) => ({
        program,
        settings,
        kinds: Object.keys(settings),
        variables: ['name', 'business', 'program', 'progress', 'stamps', 'required', 'remaining', 'reward', 'expiry_date'],
        max_length: 600,
        defaults: Object.fromEntries(
            Object.keys(settings).map((kind) => [kind, { fr: `Merci {name} ! ${kind} {progress}`, nl: 'Bedankt {name}', en: 'Thanks {name}' }]),
        ),
        samples: { fr: { name: 'Camille', progress: '6/8' }, nl: { name: 'Camille', progress: '6/8' }, en: { name: 'Camille', progress: '6/8' } },
        locales: ['fr', 'nl', 'en'],
        has_expiry: false,
        ...override,
    });

    it('propose cinq messages, tous désactivés au départ', () => {
        const wrapper = mountPage(Messages, props());
        const toggles = wrapper.findAll('input[type="checkbox"]');

        expect(wrapper.findAll('section[data-testid^="message-"]')).toHaveLength(5);
        expect(toggles).toHaveLength(5);
        expect(toggles.every((toggle) => !(toggle.element as HTMLInputElement).checked)).toBe(true);
    });

    it('aperçoit le texte proposé avec les valeurs d’exemple, dans la langue choisie', async () => {
        const wrapper = mountPage(Messages, props());

        expect(wrapper.find('[data-testid="message-progress"] [data-testid="preview"]').text()).toBe('Merci Camille ! progress 6/8');

        await wrapper
            .findAll('button[aria-pressed]')
            .find((button) => button.text() === 'common.languages.nl')!
            .trigger('click');
        expect(wrapper.find('[data-testid="message-progress"] [data-testid="preview"]').text()).toBe('Bedankt Camille');
    });

    it('insère une variable dans le texte et l’aperçoit', async () => {
        const wrapper = mountPage(Messages, props());
        const section = wrapper.find('[data-testid="message-joined"]');

        await section.find('textarea').setValue('Bienvenue ');
        await section
            .findAll('ul button')
            .find((button) => button.text() === '{name}')!
            .trigger('click');

        expect((section.find('textarea').element as HTMLTextAreaElement).value).toBe('Bienvenue {name}');
        expect(section.find('[data-testid="preview"]').text()).toBe('Bienvenue Camille');
    });

    it('signale une variable inconnue et bloque l’enregistrement', async () => {
        const wrapper = mountPage(Messages, props());

        await wrapper.find('[data-testid="message-joined"] textarea').setValue('Bonjour {prenom}');

        expect(wrapper.find('[role="alert"]').text()).toContain('loyalty.messages.unknown_variable');
        expect(wrapper.find('form button[type="submit"]').attributes('disabled')).toBeDefined();
    });

    it('rappelle que participer n’est pas un accord marketing, et que le rappel d’expiration suppose des récompenses qui expirent', () => {
        const wrapper = mountPage(Messages, props());

        expect(wrapper.text()).toContain('loyalty.messages.consent');
        expect(wrapper.text()).toContain('loyalty.messages.needs_expiry');
        expect(mountPage(Messages, props({ has_expiry: true })).text()).not.toContain('loyalty.messages.needs_expiry');
    });

    it('sans programme, demande d’abord de le configurer', () => {
        expect(
            mountPage(Messages, props({ program: null }))
                .find('[data-testid="no-program"]')
                .exists(),
        ).toBe(true);
    });
});

describe('Redeem', () => {
    const reward = (override: Record<string, unknown> = {}) => ({
        id: 'r1',
        status: 'available',
        description: 'Une coupe offerte',
        terms: 'Une seule fois.',
        type: 'free_service',
        earned_at: '2026-06-01T10:00:00Z',
        expires_at: '2026-12-31T10:00:00Z',
        redeemed_at: null,
        applied_value_cents: null,
        booking_id: null,
        earned_booking_id: 'b-earning',
        value: null,
        max_cents: null,
        service_ids: ['s1'],
        redeem_timing: 'next_visit',
        requires_appointment: true,
        combinable: false,
        ...override,
    });
    const booking = (override: Record<string, unknown> = {}) => ({
        id: 'b1',
        date: '2026-06-10',
        time: '10:00',
        service_id: 's1',
        service: 'Coupe',
        price_cents: 2500,
        status: 'confirmed',
        payment_status: 'unpaid',
        has_reward: false,
        earning: false,
        ...override,
    });
    const props = (override: Record<string, unknown> = {}) => ({
        can,
        program,
        q: 'Cam',
        results: [],
        customer: { id: 'c1', name: 'Camille', phone: '+32470123456', language: 'fr' },
        panel: {
            program: { id: 'p1', status: 'active', name: 'Fidélité', required_visits: 8, reward_description: 'x', terms: 't', auto_enroll: true },
            member: {
                status: 'active',
                stamps: 0,
                required: 8,
                remaining: 8,
                lifetime: 8,
                rewards_earned: 1,
                last_visit_at: null,
                joined_at: '2026-05-01T10:00:00Z',
                joined_via: 'auto',
            },
            rewards: [reward()],
            history: [],
        },
        bookings: [
            booking(),
            booking({ id: 'b2', service_id: 's2', service: 'Couleur' }),
            booking({ id: 'b-earning' }),
            booking({ id: 'b3', has_reward: true }),
        ],
        done: null,
        ...override,
    });

    it('fait vérifier l’identité du client et montre la récompense, ses conditions et son expiration', () => {
        const wrapper = mountPage(Redeem, props());

        expect(wrapper.find('[data-testid="identity"]').text()).toContain('loyalty.redeem.identity');
        expect(wrapper.text()).toContain('Une coupe offerte');
        expect(wrapper.text()).toContain('Une seule fois.');
        expect(wrapper.text()).toContain('loyalty.redeem.expires');
    });

    it('grise les rendez-vous qui ne peuvent pas la recevoir, avec la raison', () => {
        const wrapper = mountPage(Redeem, props());
        const radios = wrapper.findAll('input[name="booking"]');
        const disabled = radios.map((radio) => (radio.element as HTMLInputElement).disabled);

        // b1 libre ; b2 (service non visé), b-earning (même visite) et b3 (déjà une récompense) refusés ; « sans rendez-vous » refusé (récompense liée à un rendez-vous).
        expect(disabled).toEqual([false, true, true, true, true]);
        expect(wrapper.text()).toContain('loyalty.errors.redeem.service_not_eligible');
        expect(wrapper.text()).toContain('loyalty.errors.redeem.same_visit');
        expect(wrapper.text()).toContain('loyalty.errors.redeem.already_applied');
    });

    it('indique le montant à encaisser sans jamais modifier le prix, et ne s’active qu’une fois un rendez-vous choisi', async () => {
        const wrapper = mountPage(Redeem, props({ panel: { ...props().panel, rewards: [reward({ type: 'percent_discount', value: 20 })] } }));
        const apply = () => wrapper.findAll('button').find((button) => button.text() === 'loyalty.redeem.apply')!;

        expect(apply().attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-testid="amounts"]').exists()).toBe(false);

        await wrapper.find('input[name="booking"][value="b1"]').setValue(true);
        const amounts = wrapper.find('[data-testid="amounts"]').text();

        expect(amounts).toContain('25,00');
        expect(amounts).toContain('5,00');
        expect(amounts).toContain('20,00');
        expect(wrapper.text()).toContain('loyalty.redeem.pricing_note');
        expect(apply().attributes('disabled')).toBeUndefined();
    });

    it('refuse « autre offre » quand la récompense n’est pas cumulable', async () => {
        const wrapper = mountPage(Redeem, props());

        await wrapper.find('input[name="booking"][value="b1"]').setValue(true);
        await wrapper.find('input[type="checkbox"]').setValue(true);

        expect(wrapper.text()).toContain('loyalty.redeem.not_combinable');
        expect(
            wrapper
                .findAll('button')
                .find((button) => button.text() === 'loyalty.redeem.apply')!
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('sans récompense disponible, le dit clairement', () => {
        const wrapper = mountPage(Redeem, props({ panel: { ...props().panel, rewards: [reward({ status: 'redeemed' })] } }));

        expect(wrapper.find('[data-testid="no-reward"]').exists()).toBe(true);
    });

    it('confirme le succès et rappelle que le prix du rendez-vous ne change pas', () => {
        const wrapper = mountPage(
            Redeem,
            props({ done: { reward_id: 'r1', value_cents: 2500, price_cents: 2500, booking_id: 'b1', description: 'Une coupe offerte' } }),
        );
        const done = wrapper.find('[data-testid="redeem-done"]');

        expect(done.text()).toContain('loyalty.redeem.done_title');
        expect(done.text()).toContain('loyalty.redeem.pricing_note');
        expect(done.text()).toContain('loyalty.redeem.done_value');
    });

    it('envoie le rendez-vous choisi à la confirmation', async () => {
        const wrapper = mountPage(Redeem, props());

        await wrapper.find('input[name="booking"][value="b1"]').setValue(true);
        await wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/clients/fidelite/recompenses/r1/utiliser', { booking_id: 'b1', other_offer: false }, expect.anything());
    });

    it('invite à chercher un client, et dit quand personne ne correspond', () => {
        const none = mountPage(Redeem, props({ customer: null, panel: null, bookings: [], q: 'zzz', results: [] }));
        expect(none.text()).toContain('loyalty.empty.no_results');

        const found = mountPage(
            Redeem,
            props({ customer: null, panel: null, bookings: [], results: [{ customer_id: 'c1', name: 'Camille', phone: '+32470123456', rewards: 1 }] }),
        );
        expect(found.text()).toContain('Camille');
        expect(found.find('a[href*="utiliser?customer=c1"]').exists()).toBe(true);
    });
});

describe('Report', () => {
    const report = (override: Record<string, unknown> = {}) => ({
        period: { from: '2026-06-01', to: '2026-06-30', granularity: 'day', timezone: 'Europe/Brussels' },
        filters: { service: null, location: null, program: null },
        scoped: false,
        metrics: {
            enrolled: 10,
            stamps_earned: 42,
            stamps_reversed: 2,
            stamps_net: 40,
            rewards_earned: 5,
            rewards_redeemed: 3,
            rewards_outstanding: 2,
            redemption_rate: 0.6,
            redemption_rate_base: 5,
        },
        series: [
            { key: '2026-06-01', earned: 4, redeemed: 0 },
            { key: '2026-06-02', earned: 2, redeemed: 1 },
        ],
        returning: { enrolled: { customers: 10, repeat: 6, rate: 0.6 }, others: { customers: 20, repeat: 6, rate: 0.3 } },
        programs: [{ id: 'p1', status: 'active', name: 'Fidélité', created_at: '2026-05-01' }],
        ...override,
    });
    const props = (override: Record<string, unknown> = {}) => ({
        can,
        program,
        report: report(override),
        services: [{ id: 's1', name: 'Coupe' }],
        locations: [{ id: 'l1', name: 'Bruxelles' }],
    });

    it('montre les chiffres, le taux d’utilisation et sa base', () => {
        const wrapper = mountPage(Report, props());

        expect(wrapper.find('[data-testid="metric-stamps_earned"]').text()).toBe('42');
        expect(wrapper.find('[data-testid="metric-rewards_redeemed"]').text()).toBe('3');
        expect(wrapper.find('[data-testid="metric-redemption_rate"]').text()).toContain('60');
        expect(wrapper.text()).toContain('loyalty.report.rate_base');
    });

    it('ne prétend jamais que le programme a causé le retour des clients', () => {
        const wrapper = mountPage(Report, props());

        expect(wrapper.find('[data-testid="causal-note"]').text()).toBe('loyalty.report.returning_note');
        expect(wrapper.find('[data-testid="returning-enrolled"]').text()).toContain('loyalty.report.returning_line');
    });

    it('affiche un tiret au lieu d’un taux sans récompense, et prévient quand un filtre est actif', () => {
        const wrapper = mountPage(Report, props({ metrics: { ...report().metrics, redemption_rate: null, redemption_rate_base: 0 }, scoped: true }));

        expect(wrapper.find('[data-testid="metric-redemption_rate"]').text()).toBe('—');
        expect(wrapper.text()).toContain('loyalty.report.no_rate');
        expect(wrapper.find('[data-testid="scoped-note"]').exists()).toBe(true);
    });

    it('envoie les filtres choisis', async () => {
        const wrapper = mountPage(Report, props());

        await wrapper.find('form').trigger('submit');

        expect(get).toHaveBeenCalledWith('/clients/fidelite/rapport', expect.objectContaining({ from: '2026-06-01', to: '2026-06-30' }), expect.anything());
    });
});
