<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '../../composables/money';
import { bucketLabel, bucketTitle, formatCount, SERIES_STYLE } from '../../composables/reports';
import { useT } from '../../composables/useT';
import type { FinancialReportData, MoneyRow, ReportPeriod } from '../../types/reports';
import AlertBox from '../AlertBox.vue';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import type { SeriesDef } from './chartTypes';
import HBarList from './HBarList.vue';
import InsightList from './InsightList.vue';
import KpiCard from './KpiCard.vue';

/**
 * Rapport « Finances » : la valeur des rendez-vous (prix enregistrés) n'est jamais confondue avec l'argent encaissé. Chaque montant
 * est dans la devise de l'établissement ; ce que la plateforme n'enregistre pas est signalé, jamais estimé.
 */
const props = defineProps<{ report: FinancialReportData; period: ReportPeriod }>();

const { t, locale } = useT();
const money = (cents: number): string => formatPrice(cents, locale.value, props.report.currency);
const m = (key: string) => props.report.metrics[key] ?? null;

const series = computed<SeriesDef[]>(() => [
    { key: 'value', label: t('reports.metric.booked_value'), ...SERIES_STYLE.value },
    { key: 'collected', label: t('reports.metric.collected'), ...SERIES_STYLE.collected },
]);
const timeItems = computed(() =>
    props.report.series.buckets.map((bucket) => ({
        key: bucket.key,
        label: bucketLabel(bucket.key, props.report.series.granularity),
        title: bucketTitle(bucket.key, props.report.series.granularity),
        values: [bucket.value, bucket.collected],
    })),
);
const timeTable = computed(() => ({
    columns: [
        { key: 'period', label: t('reports.col.period') },
        { key: 'value', label: `${t('reports.metric.booked_value')} (${props.report.currency})`, align: 'right' as const },
        { key: 'collected', label: `${t('reports.metric.collected')} (${props.report.currency})`, align: 'right' as const },
    ],
    rows: props.report.series.buckets.map((bucket) => ({
        period: bucketTitle(bucket.key, props.report.series.granularity),
        value: money(bucket.value),
        collected: money(bucket.collected),
    })),
}));

const rankItems = (rows: MoneyRow[]) =>
    rows.map((row) => ({
        key: row.id,
        label: row.name,
        value: row.value,
        text: money(row.value),
        sub: `${t('reports.metric.collected')} : ${money(row.collected)} · ${t('reports.col.appointments')} : ${formatCount(row.count, locale.value)}`,
    }));
const rankTable = (label: string, rows: MoneyRow[]) => ({
    columns: [
        { key: 'name', label },
        { key: 'count', label: t('reports.col.appointments'), align: 'right' as const },
        { key: 'value', label: `${t('reports.metric.booked_value')} (${props.report.currency})`, align: 'right' as const },
        { key: 'collected', label: `${t('reports.metric.collected')} (${props.report.currency})`, align: 'right' as const },
    ],
    rows: rows.map((row) => ({ name: row.name, count: row.count, value: money(row.value), collected: money(row.collected) })),
});

const methodLabel = (key: string): string => (['cash', 'card', 'other'].includes(key) ? t(`planning.payment.${key}`) : t('reports.method_unspecified'));
const methodItems = computed(() =>
    props.report.methods.map((row) => ({ key: row.key, label: methodLabel(row.key), value: row.collected, text: money(row.collected) })),
);
const methodTable = computed(() => ({
    columns: [
        { key: 'method', label: t('reports.col.method') },
        { key: 'collected', label: `${t('reports.metric.collected')} (${props.report.currency})`, align: 'right' as const },
    ],
    rows: props.report.methods.map((row) => ({ method: methodLabel(row.key), collected: money(row.collected) })),
}));
const statusTable = computed(() => ({
    columns: [
        { key: 'status', label: t('reports.col.payment_status') },
        { key: 'count', label: t('reports.col.appointments'), align: 'right' as const },
        { key: 'price', label: `${t('reports.col.price')} (${props.report.currency})`, align: 'right' as const },
        { key: 'collected', label: `${t('reports.metric.collected')} (${props.report.currency})`, align: 'right' as const },
    ],
    rows: props.report.payment_statuses.map((row) => ({
        status: t(`planning.payment.${row.key}`),
        count: row.count,
        price: money(row.price),
        collected: money(row.collected),
    })),
}));
const statusItems = computed(() =>
    props.report.payment_statuses.map((row) => ({
        key: row.key,
        label: t(`planning.payment.${row.key}`),
        value: row.count,
        text: formatCount(row.count, locale.value),
    })),
);
</script>

<template>
    <div class="space-y-6" data-testid="financial-report">
        <div class="space-y-2">
            <p class="text-sm text-neutral-800">{{ t('reports.note.currency', { currency: report.currency }) }} {{ t('reports.note.collected_basis') }}</p>
            <AlertBox v-if="report.excluded_other_currency > 0" variant="warning">
                {{ t('reports.note.other_currency', { count: report.excluded_other_currency }) }}
            </AlertBox>
        </div>

        <section :aria-label="t('reports.section.highlights')">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                <KpiCard
                    :label="t('reports.metric.booked_value')"
                    :help="t('reports.help.booked_value')"
                    :metric="m('booked_value')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.collected')"
                    :help="t('reports.help.collected')"
                    :metric="m('collected')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.outstanding')"
                    :help="t('reports.help.outstanding')"
                    :metric="m('outstanding')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.avg_value')"
                    :help="t('reports.help.avg_value')"
                    :metric="m('avg_value')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.completed_value')"
                    :help="t('reports.help.completed_value')"
                    :metric="m('completed_value')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.collection_rate')"
                    :help="t('reports.help.collection_rate')"
                    :metric="m('collection_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.waived')"
                    :help="t('reports.help.waived')"
                    :metric="m('waived')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.no_show_value')"
                    :help="t('reports.help.no_show_value')"
                    :metric="m('no_show_value')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.cancelled_value')"
                    :help="t('reports.help.cancelled_value')"
                    :metric="m('cancelled_value')"
                    :currency="report.currency"
                    :previous="period.previous"
                />
                <KpiCard :label="t('reports.metric.refunds')" :help="t('reports.help.unavailable')" :metric="null" unavailable />
                <KpiCard :label="t('reports.metric.discounts')" :help="t('reports.help.unavailable')" :metric="null" unavailable />
            </dl>
            <p class="mt-2 text-xs text-neutral-700">{{ t('reports.note.unavailable') }}</p>
        </section>

        <InsightList :insights="report.insights" :currency="report.currency" />

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard :title="t('reports.section.revenue_over_time')" class="lg:col-span-2">
                <template #chart><BarChart :items="timeItems" :series="series" :stacked="false" :format="(value) => money(value)" /></template>
                <template #table><DataTable :caption="t('reports.section.revenue_over_time')" :columns="timeTable.columns" :rows="timeTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.revenue_services')" :empty="report.services.length === 0">
                <template #chart><HBarList :items="rankItems(report.services)" /></template>
                <template #table>
                    <DataTable :caption="t('reports.section.revenue_services')" v-bind="rankTable(t('reports.col.service'), report.services)" />
                </template>
            </ChartCard>

            <ChartCard v-if="report.practitioners" :title="t('reports.section.revenue_practitioners')" :empty="report.practitioners.length === 0">
                <template #chart><HBarList :items="rankItems(report.practitioners)" /></template>
                <template #table>
                    <DataTable :caption="t('reports.section.revenue_practitioners')" v-bind="rankTable(t('reports.col.practitioner'), report.practitioners)" />
                </template>
            </ChartCard>

            <ChartCard v-if="report.locations" :title="t('reports.section.revenue_locations')">
                <template #chart><HBarList :items="rankItems(report.locations)" /></template>
                <template #table>
                    <DataTable :caption="t('reports.section.revenue_locations')" v-bind="rankTable(t('reports.col.location'), report.locations)" />
                </template>
            </ChartCard>

            <ChartCard :title="t('reports.section.methods')" :empty="methodItems.length === 0">
                <template #chart><HBarList :items="methodItems" /></template>
                <template #table><DataTable :caption="t('reports.section.methods')" :columns="methodTable.columns" :rows="methodTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.payment_status')" :empty="statusItems.length === 0">
                <template #chart><HBarList :items="statusItems" /></template>
                <template #table><DataTable :caption="t('reports.section.payment_status')" :columns="statusTable.columns" :rows="statusTable.rows" /></template>
            </ChartCard>
        </div>
    </div>
</template>
