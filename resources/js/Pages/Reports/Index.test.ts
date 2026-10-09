import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import Index from './Index.vue';

const handlers: Record<string, (event?: unknown) => unknown> = {};
const reload = vi.fn();
const get = vi.fn();
const put = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            locale: 'en',
            translations: {},
            auth: { organization: { id: 'o1', name: 'Salon', role: 'owner', logo_version: null } },
            flash: {},
            errors: {},
        },
    }),
    Head: { render: () => null },
    router: {
        get: (...args: unknown[]) => get(...args),
        put: (...args: unknown[]) => put(...args),
        reload: () => reload(),
        on: (name: string, callback: (event?: unknown) => unknown) => {
            handlers[name] = callback;

            return vi.fn();
        },
    },
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
}));

const filters = {
    preset: 'this_month',
    from: '2026-06-01',
    to: '2026-06-30',
    location: null,
    practitioner: null,
    service: null,
    status: [] as string[],
    source: null,
};
const period = {
    from: '2026-06-01',
    to: '2026-06-30',
    days: 30,
    previous: { from: '2026-05-01', to: '2026-05-31' },
    in_progress: false,
    timezone: 'Europe/Brussels',
};
const options = {
    locations: [{ id: 'l1', name: 'Main' }],
    practitioners: [],
    services: [],
    statuses: ['completed'],
    sources: ['dashboard'],
    presets: ['this_month', 'custom'],
};
const access = {
    categories: ['appointments', 'customers'],
    financial: false,
    export: true,
    staff: true,
    own_only: false,
    can_configure: false,
    managers_financial: false,
    role: 'site_manager',
};
const metric = (value: number) => ({ value, previous: null, delta: null, pct: null, kind: 'count', note: null });

const Layout = defineComponent({
    props: { title: { type: String, default: '' } },
    render() {
        return h('main', this.$slots.default?.());
    },
});

function page(props: Record<string, unknown> = {}) {
    return mount(Index, {
        props: { category: 'appointments', filters, period, options, access, locale: 'en', report: { metrics: { total: metric(0) } }, ...props } as never,
        global: { stubs: { AppLayout: Layout, ReportBody: { template: '<div data-testid="report-body" />' }, ExportDialog: true } },
    });
}

describe('Reports/Index', () => {
    beforeEach(() => {
        Object.keys(handlers).forEach((key) => delete handlers[key]);
        reload.mockReset();
        get.mockReset();
        put.mockReset();
    });

    it('affiche le titre et les onglets des catégories permises, en gardant période et filtres', () => {
        const wrapper = page({ filters: { ...filters, practitioner: 'p1' } });

        expect(wrapper.find('[data-testid="reports-title"]').text()).toBe('reports.title');
        expect(wrapper.findAll('nav a').map((link) => link.attributes('href'))).toEqual([
            '/rapports?preset=this_month&practitioner=p1',
            '/rapports?preset=this_month&practitioner=p1&category=appointments',
            '/rapports?preset=this_month&practitioner=p1&category=customers',
        ]);
        expect(wrapper.find('[data-testid="tab-appointments"]').attributes('aria-current')).toBe('page');
        expect(wrapper.find('[data-testid="tab-financial"]').exists()).toBe(false);
    });

    it('affiche l’état « aucune donnée » quand la période est vide', () => {
        const wrapper = page();

        expect(wrapper.find('[data-testid="reports-empty"]').text()).toContain('reports.empty.no_data_title');
        expect(wrapper.find('[data-testid="report-body"]').exists()).toBe(false);
    });

    it('distingue « aucun résultat avec ces filtres » et propose de les réinitialiser', async () => {
        const wrapper = page({ filters: { ...filters, source: 'whatsapp' } });

        expect(wrapper.find('[data-testid="reports-empty"]').text()).toContain('reports.empty.filtered_title');
        await wrapper.find('[data-testid="reports-empty"] button').trigger('click');
        expect(get.mock.calls[0]![1]).toEqual({ preset: 'this_month', category: 'appointments' });
    });

    it('montre le rapport quand il y a des données', () => {
        const wrapper = page({ report: { metrics: { total: metric(4) } } });

        expect(wrapper.find('[data-testid="report-body"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="reports-empty"]').exists()).toBe(false);
    });

    it('indique que le rapport est en cours de calcul pendant un rechargement', async () => {
        const wrapper = page({ report: { metrics: { total: metric(4) } } });

        handlers.start!({ detail: { visit: { url: new URL('http://x/rapports?preset=today') } } });
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="reports-loading"]').exists()).toBe(true);
        expect(wrapper.find('[aria-busy="true"]').exists()).toBe(true);

        handlers.finish!();
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="reports-loading"]').exists()).toBe(false);
    });

    it('n’affiche pas le chargement pour une autre page', async () => {
        const wrapper = page({ report: { metrics: { total: metric(4) } } });

        handlers.start!({ detail: { visit: { url: new URL('http://x/clients') } } });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="reports-loading"]').exists()).toBe(false);
    });

    it('affiche une erreur claire avec « Réessayer » quand le calcul échoue', async () => {
        const wrapper = page({ report: { metrics: { total: metric(4) } } });

        expect(handlers.httpException!()).toBe(false);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="reports-error"]').text()).toContain('reports.error.title');
        expect(wrapper.find('[data-testid="report-body"]').exists()).toBe(false);

        await wrapper.find('[data-testid="reports-error"] button').trigger('click');
        expect(reload).toHaveBeenCalled();
        expect(wrapper.find('[data-testid="reports-error"]').exists()).toBe(false);
    });

    it('réserve le réglage des montants au propriétaire', async () => {
        expect(page().find('[data-testid="reports-settings"]').exists()).toBe(false);

        const owner = page({ access: { ...access, can_configure: true } });
        await owner.find('[data-testid="reports-settings"] input').setValue(true);

        expect(put).toHaveBeenCalledWith('/rapports/reglages', { managers_financial: true }, expect.anything());
    });
});
