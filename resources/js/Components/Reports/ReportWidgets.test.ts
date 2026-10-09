import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import HBarList from './HBarList.vue';
import Heatmap from './Heatmap.vue';
import InfoTip from './InfoTip.vue';
import KpiCard from './KpiCard.vue';
import StackedBar from './StackedBar.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

/** v-show masque avec « display: none » sur le parent direct : on lit l'élément et son parent, sans dépendre du rattachement au document. */
const shown = (wrapper: { element: Element }): boolean =>
    ![wrapper.element, wrapper.element.parentElement].some((node) => (node as HTMLElement | null)?.style?.display === 'none');
const metric = (partial: Record<string, unknown>) => ({ value: 0, previous: null, delta: null, pct: null, kind: 'count', note: null, ...partial });
const series = [
    { key: 'completed', label: 'Completed', color: '#0a5656', pattern: 'solid' as const },
    { key: 'cancelled', label: 'Cancelled', color: '#9db8b4', pattern: 'cross' as const },
];

describe('KpiCard', () => {
    const mountKpi = (props: Record<string, unknown>) => mount(KpiCard, { props: { label: 'Appointments', ...props } as never });

    it('affiche la valeur et une comparaison neutre (jamais vert ou rouge)', () => {
        const wrapper = mountKpi({ metric: metric({ value: 12, previous: 10, delta: 2, pct: 20 }), previous: { from: '2026-05-01', to: '2026-05-31' } });

        expect(wrapper.find('[data-testid="kpi-value"]').text()).toBe('12');
        expect(wrapper.find('[data-testid="kpi-change"]').text()).toContain('+2');
        expect(wrapper.find('[data-testid="kpi-change"]').text()).toContain('+20');
        expect(wrapper.html()).not.toMatch(/text-(success|danger)|bg-(success|danger)/);
    });

    it('explique l’absence de pourcentage sur une base nulle ou petite', () => {
        const zero = mountKpi({ metric: metric({ value: 4, previous: 0, delta: 4, note: 'zero_baseline' }) });
        const small = mountKpi({ metric: metric({ value: 4, previous: 2, delta: 2, note: 'small_baseline' }) });

        expect(zero.text()).toContain('reports.compare.zero_baseline');
        expect(small.text()).toContain('reports.compare.small_baseline');
        expect(zero.text()).not.toContain('%');
    });

    it('ne compare pas un chiffre tourné vers l’avenir', () => {
        const wrapper = mountKpi({ metric: metric({ value: 5 }), forward: true });

        expect(wrapper.text()).toContain('reports.compare.forward');
    });

    it('affiche un tiret pour un chiffre non disponible', () => {
        expect(mountKpi({ metric: null, unavailable: true }).find('[data-testid="kpi-value"]').text()).toBe('—');
    });

    it('annonce la direction aux lecteurs d’écran', () => {
        const wrapper = mountKpi({ metric: metric({ value: 8, previous: 10, delta: -2, pct: -20 }) });

        expect(wrapper.find('.sr-only').text()).toBe('reports.compare.decrease');
    });
});

describe('InfoTip', () => {
    it('affiche la définition au clic et la referme avec Échap', async () => {
        const wrapper = mount(InfoTip, { props: { text: 'Cancelled ÷ booked' } });
        const button = wrapper.find('button');

        expect(shown(wrapper.find('[role="tooltip"]'))).toBe(false);
        await button.trigger('click');
        expect(shown(wrapper.find('[role="tooltip"]'))).toBe(true);
        expect(wrapper.find('[role="tooltip"]').text()).toBe('Cancelled ÷ booked');
        await button.trigger('keydown', { key: 'Escape' });
        expect(shown(wrapper.find('[role="tooltip"]'))).toBe(false);
    });
});

describe('ChartCard', () => {
    const card = (props: Record<string, unknown> = {}) =>
        mount(ChartCard, {
            props: { title: 'Over time', ...props },
            slots: { chart: '<div data-testid="the-chart" />', table: '<div data-testid="the-table" />' },
        });

    it('bascule entre graphique et tableau équivalent', async () => {
        const wrapper = card();

        expect(shown(wrapper.find('[data-testid="the-chart"]'))).toBe(true);
        expect(shown(wrapper.find('[data-testid="the-table"]'))).toBe(false);

        await wrapper.find('[data-testid="view-table"]').trigger('click');
        expect(shown(wrapper.find('[data-testid="the-chart"]'))).toBe(false);
        expect(shown(wrapper.find('[data-testid="the-table"]'))).toBe(true);
        expect(wrapper.find('[data-testid="view-table"]').attributes('aria-pressed')).toBe('true');
    });

    it('affiche un état vide au lieu d’un graphique vide', () => {
        const wrapper = card({ empty: true });

        expect(wrapper.find('[data-testid="chart-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="the-chart"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="view-table"]').exists()).toBe(false);
    });

    it('imprime graphique et tableau ensemble, sans bouton de bascule', () => {
        const wrapper = mount(ChartCard, {
            props: { title: 'Over time' },
            slots: { chart: '<div data-testid="the-chart" />', table: '<div data-testid="the-table" />' },
            global: { provide: { reportPrint: true } },
        });

        expect(wrapper.find('[data-testid="view-chart"]').exists()).toBe(false);
        expect(shown(wrapper.find('[data-testid="the-chart"]'))).toBe(true);
        expect(shown(wrapper.find('[data-testid="the-table"]'))).toBe(true);
    });
});

describe('BarChart', () => {
    const items = [
        { key: 'a', label: '01/06', title: '01/06/2026', values: [3, 1] },
        { key: 'b', label: '02/06', title: '02/06/2026', values: [0, 2] },
    ];

    it('décrit chaque groupe de barres au clavier et aux lecteurs d’écran', () => {
        const wrapper = mount(BarChart, { props: { items, series } });
        const groups = wrapper.findAll('g[role="img"]');

        expect(groups).toHaveLength(2);
        expect(groups[0]!.attributes('tabindex')).toBe('0');
        expect(groups[0]!.attributes('aria-label')).toBe('01/06/2026: Completed 3, Cancelled 1');
    });

    it('affiche une légende avec une trame par série et une info-bulle au focus', async () => {
        const wrapper = mount(BarChart, { props: { items, series } });

        expect(wrapper.findAll('[data-testid="legend"] li')).toHaveLength(2);
        expect(wrapper.find('defs pattern').exists()).toBe(true);

        await wrapper.findAll('g[role="img"]')[1]!.trigger('focus');
        expect(wrapper.find('[data-testid="chart-tooltip"]').text()).toContain('02/06/2026');
    });

    it('n’empile pas les barres en mode groupé', () => {
        const stacked = mount(BarChart, { props: { items, series, stacked: true } });
        const grouped = mount(BarChart, { props: { items, series, stacked: false } });

        expect(stacked.findAll('g[role="img"]')[0]!.findAll('rect').length).toBe(grouped.findAll('g[role="img"]')[0]!.findAll('rect').length);
        expect(stacked.html()).not.toBe(grouped.html());
    });
});

describe('StackedBar, HBarList, Heatmap, DataTable', () => {
    it('écrit les chiffres et pourcentages de chaque part', () => {
        const wrapper = mount(StackedBar, {
            props: {
                parts: [
                    { key: 'completed', label: 'Completed', value: 3, series: series[0]! },
                    { key: 'cancelled', label: 'Cancelled', value: 1, series: series[1]! },
                ],
            },
        });

        expect(wrapper.text()).toContain('Completed');
        expect(wrapper.text()).toContain('3');
        expect(wrapper.text()).toContain('75 %');
    });

    it('classe en barres avec libellé et valeur toujours écrits', () => {
        const wrapper = mount(HBarList, {
            props: {
                items: [
                    { key: 'a', label: 'Cut', value: 10, text: '10' },
                    { key: 'b', label: 'Beard', value: 5, text: '5', sub: 'Collected: 25' },
                ],
            },
        });

        expect(wrapper.findAll('li')).toHaveLength(2);
        expect(wrapper.text()).toContain('Cut');
        expect(wrapper.text()).toContain('Collected: 25');
        expect(wrapper.findAll('li')[1]!.find('[style]').attributes('style')).toContain('width: 50%');
    });

    it('construit la carte de chaleur comme un vrai tableau lisible', () => {
        const cells = Object.fromEntries(
            Array.from({ length: 7 }, (_unused, index) => [String(index + 1), Array.from({ length: 24 }, (_x, hour) => (index === 0 && hour === 10 ? 4 : 0))]),
        );
        const wrapper = mount(Heatmap, { props: { data: { cells, max: 4, total: 4, first_hour: 9, last_hour: 11 } } });

        expect(wrapper.findAll('thead th')).toHaveLength(13);
        expect(wrapper.findAll('tbody tr')).toHaveLength(7);
        expect(wrapper.findAll('tbody tr')[0]!.findAll('td')[2]!.text()).toContain('4');
        expect(wrapper.find('[role="region"]').attributes('tabindex')).toBe('0');
    });

    it('trie un tableau et annonce le tri', async () => {
        const wrapper = mount(DataTable, {
            props: {
                caption: 'Comparison',
                columns: [
                    { key: 'name', label: 'Name', sortable: true },
                    { key: 'n', label: 'Count', align: 'right', sortable: true },
                ],
                rows: [
                    { name: 'B', n: { text: '2', sort: 2 } },
                    { name: 'A', n: { text: '10', sort: 10 } },
                ],
            },
        });

        const names = () => wrapper.findAll('tbody tr').map((row) => row.find('th').text());
        expect(names()).toEqual(['B', 'A']);

        await wrapper.findAll('thead button')[1]!.trigger('click');
        expect(names()).toEqual(['B', 'A']);
        expect(wrapper.findAll('thead th')[1]!.attributes('aria-sort')).toBe('ascending');

        await wrapper.findAll('thead button')[1]!.trigger('click');
        expect(names()).toEqual(['A', 'B']);
        expect(wrapper.findAll('thead th')[1]!.attributes('aria-sort')).toBe('descending');
        expect(wrapper.find('caption').text()).toBe('Comparison');
    });
});
