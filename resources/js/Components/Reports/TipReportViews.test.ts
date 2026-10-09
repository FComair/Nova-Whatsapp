import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import FinancialView from './FinancialView.vue';
import OverviewView from './OverviewView.vue';
import PractitionersView from './PractitionersView.vue';
import ReportToolbar from './ReportToolbar.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: {}, flash: {}, errors: {} } }),
    router: { get: vi.fn(), on: vi.fn(() => vi.fn()) },
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
}));

const period = {
    from: '2026-06-01',
    to: '2026-06-30',
    days: 30,
    previous: { from: '2026-05-01', to: '2026-05-31' },
    in_progress: false,
    timezone: 'Europe/Brussels',
};
const metric = (value: number, extra: Record<string, unknown> = {}) => ({ value, previous: null, delta: null, pct: null, kind: 'money', note: null, ...extra });

const financial = (overrides: Record<string, unknown> = {}) => ({
    currency: 'EUR',
    excluded_other_currency: 0,
    metrics: {
        booked_value: metric(10000),
        completed_value: metric(7500),
        collected: metric(7500),
        outstanding: metric(2500),
        waived: metric(0),
        no_show_value: metric(0),
        cancelled_value: metric(0),
        avg_value: metric(2500),
        collection_rate: metric(1, { kind: 'rate' }),
        tips: metric(1700),
        tips_count: metric(3, { kind: 'count' }),
        tips_avg: metric(567),
        total_paid: metric(9200),
    },
    series: { granularity: 'day', buckets: [{ key: '2026-06-02', value: 2500, collected: 2500, tips: 500 }] },
    services: [{ id: 's1', name: 'Coupe', count: 4, value: 10000, collected: 7500, tips: 1700 }],
    practitioners: [{ id: 'p1', name: 'Léa', count: 3, value: 7500, collected: 5000 }],
    locations: null,
    methods: [{ key: 'card', collected: 7500 }],
    payment_statuses: [{ key: 'paid_onsite', count: 3, price: 7500, collected: 7500 }],
    tips_by_practitioner: [
        { id: 'p1', name: 'Léa', tips: 1100, count: 2, share: 0.647, photo_version: null },
        { id: 'p2', name: 'Marc', tips: 600, count: 2, share: 0.353, photo_version: null },
    ],
    tips_split: 1,
    unavailable: ['refunds'],
    insights: [],
    ...overrides,
});

describe('rapport financier : pourboires', () => {
    const view = (overrides: Record<string, unknown> = {}, props: Record<string, unknown> = {}) =>
        mount(FinancialView, { props: { report: financial(overrides), period, ...props } as never });

    it('sépare trois groupes : montants du service, pourboires, total payé', () => {
        const wrapper = view();
        const service = wrapper.find('section[aria-label="reports.section.service_amounts"]');
        const tips = wrapper.find('[data-testid="tips-section"]');
        const total = wrapper.find('[data-testid="total-paid-section"]');

        expect(service.text()).toContain('reports.metric.collected');
        expect(service.text()).not.toContain('reports.metric.tips');
        expect(service.text()).not.toContain('reports.metric.total_paid');
        expect(tips.findAll('[data-testid="kpi"]')).toHaveLength(3);
        expect(tips.text()).toContain('reports.metric.tips_avg');
        expect(total.text()).toContain('reports.metric.total_paid');
    });

    it('montre les montants du service sans pourboire et le total payé avec', () => {
        const wrapper = view();
        const values = (selector: string) =>
            wrapper
                .find(selector)
                .findAll('[data-testid="kpi-value"]')
                .map((node) => node.text());

        expect(values('section[aria-label="reports.section.service_amounts"]')).toContain('€75.00');
        expect(values('[data-testid="tips-section"]')).toEqual(['€17.00', '3', '€5.67']);
        expect(values('[data-testid="total-paid-section"]')).toEqual(['€92.00']);
    });

    it('explique que les pourboires sont comptés à part', () => {
        const wrapper = view();

        expect(wrapper.find('[data-testid="tips-note"]').text()).toBe('reports.note.tips');
        expect(wrapper.find('[data-testid="tips-section"]').text()).toContain('reports.note.tips_basis');
        expect(wrapper.find('[data-testid="tips-section"]').text()).toContain('reports.note.tips_split');
    });

    it('affiche les pourboires par praticien, avec leur tableau équivalent', () => {
        const wrapper = view();
        const card = wrapper.findAll('[data-testid="chart-card"]').find((node) => node.text().includes('reports.section.tips_by_practitioner'))!;

        expect(card.text()).toContain('Léa');
        expect(card.text()).toContain('€11.00');
        expect(card.text()).toContain('€6.00');
        expect(card.find('[data-testid="data-table"]').text()).toContain('reports.col.tip_count');
    });

    it('ajoute la colonne pourboires aux services sans toucher à la valeur ni à l’encaissé', () => {
        const wrapper = view();
        const card = wrapper.findAll('[data-testid="chart-card"]').find((node) => node.text().includes('reports.section.revenue_services'))!;
        const header = card.find('thead').text();

        expect(header).toContain('reports.metric.booked_value');
        expect(header).toContain('reports.metric.collected');
        expect(header).toContain('reports.metric.tips');
        expect(card.find('tbody').text()).toContain('€100.00');
        expect(card.find('tbody').text()).toContain('€75.00');
        expect(card.find('tbody').text()).toContain('€17.00');
    });

    it('ajoute les pourboires à la courbe dans le temps, comme troisième série', () => {
        const wrapper = view();
        const chart = wrapper.find('[data-testid="bar-chart"]');

        expect(chart.findAll('[data-testid="legend"] li')).toHaveLength(3);
        expect(chart.find('g[role="img"]').attributes('aria-label')).toContain('reports.metric.tips');
    });

    it('n’affiche pas de pourboires par praticien sans le droit de comparer l’équipe', () => {
        const wrapper = view({ tips_by_practitioner: null, practitioners: null });

        expect(wrapper.text()).not.toContain('reports.section.tips_by_practitioner');
        expect(wrapper.find('[data-testid="tips-section"]').exists()).toBe(true);
    });

    it('affiche zéro sans erreur quand il n’y a aucun pourboire', () => {
        const wrapper = view({
            metrics: { ...financial().metrics, tips: metric(0), tips_count: metric(0, { kind: 'count' }), tips_avg: metric(0), total_paid: metric(7500) },
            tips_by_practitioner: [],
            tips_split: 0,
        });

        expect(
            wrapper
                .find('[data-testid="tips-section"]')
                .findAll('[data-testid="kpi-value"]')
                .map((node) => node.text()),
        ).toEqual(['€0.00', '0', '€0.00']);
        expect(wrapper.text()).not.toContain('reports.note.tips_split');
        expect(wrapper.find('[data-testid="chart-empty"]').exists()).toBe(true);
    });

    it('signale un filtre de paiement actif', () => {
        expect(view({}, { paymentFiltered: true }).text()).toContain('reports.note.payment_filter');
        expect(view().text()).not.toContain('reports.note.payment_filter');
    });
});

describe('autres rapports', () => {
    it('ajoute la colonne des pourboires reçus au tableau des praticiens, séparée de la valeur et de l’encaissé', () => {
        const row = {
            id: 'p1',
            name: 'Léa',
            photo_version: null,
            assigned: 3,
            previous_assigned: 0,
            completed: 3,
            cancelled: 0,
            no_show: 0,
            cancellation_rate: 0,
            no_show_rate: 0,
            scheduled_minutes: 600,
            booked_minutes: 90,
            completed_minutes: 90,
            available_minutes: 510,
            utilization: 0.15,
            working_days: 2,
            days_off: 0,
            non_working_days: 0,
            value_cents: 7500,
            collected_cents: 5000,
            tips_cents: 1100,
            services: [],
        };
        const heat = {
            cells: Object.fromEntries(Array.from({ length: 7 }, (_u, i) => [String(i + 1), Array(24).fill(0)])),
            max: 0,
            total: 0,
            first_hour: 9,
            last_hour: 17,
        };
        const wrapper = mount(PractitionersView, {
            props: {
                report: { rows: [row], metrics: {}, heatmap: heat, time_off: [], insights: [] },
                period,
                currency: 'EUR',
                showValues: true,
                showCompare: true,
            } as never,
        });
        const table = wrapper.findAll('[data-testid="data-table"]')[0]!;

        expect(table.find('thead').text()).toContain('reports.metric.tips');
        expect(table.find('thead').text()).toContain('reports.metric.collected');
        expect(table.find('tbody').text()).toContain('€11.00');
        expect(table.find('tbody').text()).toContain('€50.00');

        const without = mount(PractitionersView, {
            props: {
                report: {
                    rows: [{ ...row, value_cents: null, collected_cents: null, tips_cents: null }],
                    metrics: {},
                    heatmap: heat,
                    time_off: [],
                    insights: [],
                },
                period,
                currency: 'EUR',
                showValues: false,
                showCompare: true,
            } as never,
        });
        expect(without.findAll('[data-testid="data-table"]')[0]!.find('thead').text()).not.toContain('reports.metric.tips');
    });

    it('ajoute les pourboires et le total payé aux chiffres clés financiers de la vue d’ensemble', () => {
        const overview = {
            appointments: null,
            customers: null,
            practitioners: null,
            financial: { metrics: financial().metrics, currency: 'EUR', series: financial().series },
        };
        const wrapper = mount(OverviewView, {
            props: { overview, period, links: { appointments: '/a', customers: '/c', financial: '/f', practitioners: '/p' } } as never,
        });
        const labels = wrapper.findAll('[data-testid="kpi"] dt').map((node) => node.text());

        expect(labels.join(' ')).toContain('reports.metric.tips');
        expect(labels.join(' ')).toContain('reports.metric.total_paid');
        expect(labels.join(' ')).toContain('reports.metric.collected');
    });
});

describe('filtre de statut de paiement', () => {
    const filters = {
        preset: 'this_month',
        from: '2026-06-01',
        to: '2026-06-30',
        location: null,
        practitioner: null,
        service: null,
        status: [] as string[],
        source: null,
        payment: null as string | null,
    };
    const options = {
        locations: [],
        practitioners: [],
        services: [],
        statuses: ['completed'],
        sources: ['dashboard'],
        payment_statuses: ['unpaid', 'paid_onsite'],
        presets: ['this_month'],
    };
    const access = {
        categories: ['financial'],
        financial: true,
        export: true,
        staff: true,
        own_only: false,
        can_configure: false,
        managers_financial: false,
        role: 'owner',
    };
    const toolbar = (category: string, payment: string | null = null) =>
        mount(ReportToolbar, { props: { category, filters: { ...filters, payment }, period, options, access, statusApplies: true } as never });

    it('n’apparaît que dans le rapport financier', () => {
        expect(toolbar('financial').find('[data-testid="payment-filter"]').exists()).toBe(true);
        expect(toolbar('appointments').find('[data-testid="payment-filter"]').exists()).toBe(false);
        expect(toolbar('practitioners').find('[data-testid="payment-filter"]').exists()).toBe(false);
    });

    it('propose tous les statuts de paiement et résume le filtre actif', () => {
        const wrapper = toolbar('financial', 'unpaid');
        const select = wrapper.find('[data-testid="payment-filter"] select, select[data-testid="payment-filter"]');

        expect(wrapper.text()).toContain('reports.filter.payment');
        expect(wrapper.find('[data-testid="report-summary"]').text()).toContain('reports.filter.active');
        expect(select.exists() || wrapper.findAll('select').length > 0).toBe(true);
    });
});
