import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import ActivityList from './ActivityList.vue';
import CustomersTabs from './CustomersTabs.vue';
import LoyaltyCard from './LoyaltyCard.vue';
import LoyaltyPanel from './LoyaltyPanel.vue';
import LoyaltyTrend from './LoyaltyTrend.vue';
import StampRow from './StampRow.vue';

const post = vi.fn();
const shared = reactive({ locale: 'fr', translations: {}, auth: { organization: { role: 'owner' } }, flash: {}, errors: {} as Record<string, string> });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    router: { post: (...args: unknown[]) => post(...args) },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {},
            processing: false,
            post: (...args: unknown[]) => post(...args),
            transform: () => ({ post: (...args: unknown[]) => post(...args) }),
            reset: vi.fn(),
        }),
}));

beforeEach(() => {
    post.mockReset();
    shared.auth = { organization: { role: 'owner' } };
});

describe('StampRow', () => {
    it('montre un tampon par visite, plein quand il est obtenu, avec une seule image lisible', () => {
        const wrapper = mount(StampRow, { props: { stamps: 3, required: 8 } });
        const stamps = wrapper.findAll('li');

        expect(stamps).toHaveLength(8);
        expect(stamps.filter((stamp) => stamp.attributes('data-done') === 'true')).toHaveLength(3);
        expect(wrapper.find('[role="img"]').attributes('aria-label')).toBe('loyalty.progress.label');
        expect(wrapper.find('ol').attributes('aria-hidden')).toBe('true');
    });

    it('ne dépasse jamais le nombre de visites, et ne compte pas de tampon négatif', () => {
        expect(mount(StampRow, { props: { stamps: 12, required: 5 } }).findAll('[data-done="true"]')).toHaveLength(5);
        expect(mount(StampRow, { props: { stamps: -2, required: 5 } }).findAll('[data-done="true"]')).toHaveLength(0);
    });

    it('passe en barre de progression au-delà de 12 visites', () => {
        const wrapper = mount(StampRow, { props: { stamps: 10, required: 20 } });

        expect(wrapper.find('[data-testid="stamp-bar"]').attributes('style')).toContain('width: 50%');
        expect(wrapper.findAll('li')).toHaveLength(0);
    });

    it('accepte un texte déjà traduit (aperçu dans la langue du client)', () => {
        expect(
            mount(StampRow, { props: { stamps: 1, required: 4, label: '1 sur 4' } })
                .find('[role="img"]')
                .attributes('aria-label'),
        ).toBe('1 sur 4');
    });
});

describe('LoyaltyCard', () => {
    const texts = {
        of: ':stamps van :required bezoeken',
        remaining: 'Nog :count bezoek|Nog :count bezoeken',
        ready: 'Uw beloning staat klaar!',
        title: 'Uw loyaliteitskaart',
        terms: 'Voorwaarden',
        data_title: 'Uw gegevens',
        data: 'We noteren alleen uw bezoeken.',
        marketing: 'Geen marketing.',
        expires: 'Geldig tot :date.',
        reward_label: 'Uw beloning',
    };
    const card = (props: Record<string, unknown> = {}) =>
        mount(LoyaltyCard, {
            props: {
                language: 'nl',
                texts,
                program: { name: 'Spaarkaart', reward: 'Gratis knipbeurt', terms: 'Niet combineerbaar.', required: 8 },
                stamps: 6,
                ...props,
            } as never,
        });

    it('montre nom, progression, visites restantes, récompense, conditions et usage des données dans la langue du client', () => {
        const text = card().text();

        for (const expected of [
            'Spaarkaart',
            '6 van 8 bezoeken',
            'Nog 2 bezoeken',
            'Gratis knipbeurt',
            'Niet combineerbaar.',
            'Uw gegevens',
            'Geen marketing.',
        ]) {
            expect(text).toContain(expected);
        }

        expect(card().attributes('lang')).toBe('nl');
    });

    it('annonce la récompense prête, et l’expiration si elle existe', () => {
        const ready = card({ stamps: 8, expiry: '31/12/2026' });

        expect(ready.text()).toContain('Uw beloning staat klaar!');
        expect(ready.text()).toContain('Geldig tot 31/12/2026.');
        expect(card().text()).not.toContain('Geldig tot');
    });

    it('n’affiche pas de bloc de conditions vide', () => {
        expect(card({ program: { name: 'Spaarkaart', reward: 'Gratis', terms: '', required: 8 } }).text()).not.toContain('Voorwaarden');
    });
});

describe('CustomersTabs', () => {
    it.each([
        ['owner', 2],
        ['site_manager', 2],
        ['receptionist', 2],
        ['practitioner', 1],
    ])('propose la fidélité selon le rôle : %s', (role, count) => {
        shared.auth = { organization: { role } };
        const wrapper = mount(CustomersTabs, { props: { active: 'clients' } });

        expect(wrapper.findAll('a').length || 1).toBe(count === 1 ? 1 : count);
        expect(wrapper.text().includes('loyalty.tabs.loyalty')).toBe(count === 2);
    });

    it('marque l’onglet courant', () => {
        const wrapper = mount(CustomersTabs, { props: { active: 'loyalty' } });

        expect(wrapper.findAll('a').map((link) => link.attributes('aria-current'))).toEqual([undefined, 'page']);
    });
});

describe('LoyaltyTrend', () => {
    it('dit qu’il n’y a pas assez de données plutôt que de tracer un graphique sur un seul point', () => {
        const wrapper = mount(LoyaltyTrend, { props: { series: [{ key: '2026-06-01', earned: 3, redeemed: 0 }], granularity: 'day', tableLabel: 'Tableau' } });

        expect(wrapper.text()).toContain('loyalty.index.performance.not_enough');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('trace des barres et propose le même contenu en tableau', () => {
        const wrapper = mount(LoyaltyTrend, {
            props: {
                series: [
                    { key: '2026-06-01', earned: 3, redeemed: 0 },
                    { key: '2026-06-02', earned: 1, redeemed: 1 },
                ],
                granularity: 'day',
                tableLabel: 'Tableau',
            },
        });

        expect(wrapper.findAll('tbody tr')).toHaveLength(2);
        expect(wrapper.text()).toContain('01/06/2026');
        expect(wrapper.find('summary').text()).toBe('Tableau');
    });
});

describe('ActivityList', () => {
    const entry = (override: Record<string, unknown>) => ({
        id: 'e1',
        kind: 'stamp_earned',
        delta: 1,
        balance: 1,
        reason: null,
        detail: null,
        at: '2026-06-05T12:00:00Z',
        customer_id: null,
        customer: null,
        actor: null,
        ...override,
    });

    it('montre le type, la variation, le motif et l’auteur, jamais de donnée personnelle dans le détail', () => {
        const wrapper = mount(ActivityList, {
            props: { entries: [entry({ kind: 'manual_adjustment', delta: -1, reason: 'Erreur de saisie', actor: 'proprio@example.com' })] },
        });
        const text = wrapper.text();

        expect(text).toContain('loyalty.kind.manual_adjustment');
        expect(text).toContain('-1');
        expect(text).toContain('Erreur de saisie');
        expect(text).toContain('loyalty.index.activity.by');
    });

    it('lie le client quand l’écran le demande', () => {
        const wrapper = mount(ActivityList, { props: { entries: [entry({ customer_id: 'c1', customer: 'Camille' })], showCustomer: true } });

        expect(wrapper.find('a').attributes('href')).toBe('/clients/c1');
    });
});

describe('LoyaltyPanel', () => {
    const member = {
        status: 'active',
        stamps: 2,
        required: 3,
        remaining: 1,
        lifetime: 5,
        rewards_earned: 1,
        last_visit_at: '2026-06-05T10:00:00Z',
        joined_at: '2026-05-01T10:00:00Z',
        joined_via: 'auto',
    };
    const reward = (override: Record<string, unknown>) => ({
        id: 'r1',
        status: 'available',
        description: 'Une coupe offerte',
        terms: 'Non cumulable.',
        type: 'free_service',
        earned_at: '2026-06-01T10:00:00Z',
        expires_at: null,
        redeemed_at: null,
        applied_value_cents: null,
        booking_id: null,
        earned_booking_id: null,
        value: null,
        max_cents: null,
        service_ids: null,
        redeem_timing: 'next_visit',
        requires_appointment: true,
        combinable: false,
        ...override,
    });
    const panel = (override: Record<string, unknown> = {}) => ({
        program: {
            id: 'p1',
            status: 'active',
            name: 'Fidélité',
            required_visits: 3,
            reward_description: 'Une coupe offerte',
            terms: 'Non cumulable.',
            auto_enroll: true,
        },
        member,
        rewards: [reward({})],
        history: [],
        ...override,
    });
    const abilities = (override: Record<string, boolean> = {}) => ({ view: true, configure: false, redeem: true, adjust: true, ...override });
    const mountPanel = (props: Record<string, unknown> = {}) =>
        mount(LoyaltyPanel, { props: { customerId: 'c1', panel: panel(), can: abilities(), ...props } as never, global: { stubs: { ConfirmDialog: true } } });

    it('montre progression, récompense disponible, conditions et la distinction programme / marketing', () => {
        const text = mountPanel().text();

        expect(text).toContain('loyalty.progress.of');
        expect(text).toContain('Une coupe offerte');
        expect(text).toContain('Non cumulable.');
        expect(text).toContain('loyalty.customer.marketing_note');
        expect(text).toContain('loyalty.customer.data_use');
    });

    it('propose d’utiliser la récompense à qui peut, et rien à un praticien', () => {
        expect(mountPanel().find('a[href^="/clients/fidelite/utiliser"]').exists()).toBe(true);
        expect(
            mountPanel({ can: abilities({ redeem: false, adjust: false }) })
                .find('a[href^="/clients/fidelite/utiliser"]')
                .exists(),
        ).toBe(false);
    });

    it('réserve l’ajustement avec motif à ceux qui peuvent corriger', () => {
        expect(mountPanel().text()).toContain('loyalty.customer.adjust_submit');
        expect(mountPanel({ can: abilities({ adjust: false }) }).text()).not.toContain('loyalty.customer.adjust_submit');
    });

    it('envoie l’ajustement avec son motif', async () => {
        const wrapper = mountPanel();
        const inputs = wrapper.findAll('input');

        await inputs[0]!.setValue('2');
        await inputs[1]!.setValue('Ancienne carte');
        await wrapper.find('form').trigger('submit');

        expect(post).toHaveBeenCalledWith('/clients/fidelite/membres/c1/ajuster', expect.objectContaining({ preserveScroll: true }));
    });

    it('propose d’inscrire un client non inscrit, ou de le réinscrire après son départ', async () => {
        const unenrolled = mountPanel({ panel: panel({ member: null }) });
        expect(unenrolled.text()).toContain('loyalty.customer.not_enrolled');
        await unenrolled
            .findAll('button')
            .find((button) => button.text() === 'loyalty.customer.enroll')!
            .trigger('click');
        expect(post).toHaveBeenCalledWith('/clients/fidelite/membres/c1/inscrire', {}, expect.anything());

        post.mockReset();
        const left = mountPanel({ panel: panel({ member: { ...member, status: 'opted_out' } }) });
        expect(left.text()).toContain('loyalty.customer.opted_out');
        await left
            .findAll('button')
            .find((button) => button.text() === 'loyalty.customer.rejoin')!
            .trigger('click');
        expect(post).toHaveBeenCalledWith('/clients/fidelite/membres/c1/reprendre', {}, expect.anything());
    });

    it('l’annulation d’une utilisation passe par un motif, pas par un simple clic', async () => {
        const wrapper = mountPanel({
            panel: panel({ rewards: [reward({ status: 'redeemed', redeemed_at: '2026-06-05T10:00:00Z', applied_value_cents: 2500 })] }),
        });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'loyalty.customer.reverse')!
            .trigger('click');
        expect(wrapper.text()).toContain('loyalty.customer.reverse_body');
        expect(post).not.toHaveBeenCalled();

        await wrapper.find('textarea').setValue('Mauvais client');
        await wrapper.find('li form').trigger('submit');
        expect(post).toHaveBeenCalledWith('/clients/fidelite/recompenses/r1/annuler', expect.anything());
    });

    it('distingue une récompense expirée (même si le serveur la dit encore disponible) et sans action possible', () => {
        const wrapper = mountPanel({ panel: panel({ rewards: [reward({ status: 'expired' })] }) });

        expect(wrapper.text()).toContain('loyalty.customer.reward_status.expired');
        expect(wrapper.find('a[href^="/clients/fidelite/utiliser"]').exists()).toBe(false);
    });

    it('sans programme, l’explique', () => {
        expect(mountPanel({ panel: panel({ program: null, member: null, rewards: [] }) }).text()).toContain('loyalty.customer.no_program');
    });
});
