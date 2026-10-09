<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { bucketLabel, bucketTitle, formatCount, SERIES_STYLE } from '../../composables/reports';
import { useT } from '../../composables/useT';
import { routes } from '../../routes';
import type { CustomersReportData, ReportPeriod } from '../../types/reports';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import type { SeriesDef } from './chartTypes';
import HBarList from './HBarList.vue';
import InsightList from './InsightList.vue';
import KpiCard from './KpiCard.vue';

/**
 * Rapport « Clients » : uniquement des chiffres agrégés. Aucun nom, numéro ni adresse : la fiche d'un client s'ouvre dans la liste des
 * clients, avec ses propres droits.
 */
const props = defineProps<{ report: CustomersReportData; period: ReportPeriod; canOpenCustomers: boolean }>();

const { t, locale } = useT();
const m = (key: string) => props.report.metrics[key] ?? null;
const series = computed<SeriesDef[]>(() => [
    { key: 'new', label: t('reports.metric.new'), ...SERIES_STYLE.new },
    { key: 'active', label: t('reports.metric.active'), ...SERIES_STYLE.active },
]);
const timeItems = computed(() =>
    props.report.series.buckets.map((bucket) => ({
        key: bucket.key,
        label: bucketLabel(bucket.key, props.report.series.granularity),
        title: bucketTitle(bucket.key, props.report.series.granularity),
        values: [bucket.new, bucket.active],
    })),
);
const timeTable = computed(() => ({
    columns: [
        { key: 'period', label: t('reports.col.period') },
        { key: 'new', label: t('reports.metric.new'), align: 'right' as const },
        { key: 'active', label: t('reports.metric.active'), align: 'right' as const },
    ],
    rows: props.report.series.buckets.map((bucket) => ({
        period: bucketTitle(bucket.key, props.report.series.granularity),
        new: bucket.new,
        active: bucket.active,
    })),
}));

const list = (rows: { key: string; label: string; count: number }[]) =>
    rows.map((row) => ({ key: row.key, label: row.label, value: row.count, text: formatCount(row.count, locale.value) }));
const serviceItems = computed(() => list(props.report.by_service.map((row) => ({ key: row.id, label: row.name, count: row.count }))));
const sourceItems = computed(() => list(props.report.by_source.map((row) => ({ key: row.key, label: t(`reports.source.${row.key}`), count: row.count }))));
const locationItems = computed(() => list((props.report.by_location ?? []).map((row) => ({ key: row.id, label: row.name, count: row.count }))));
const table = (label: string, rows: { label: string; value: number }[]) => ({
    columns: [
        { key: 'label', label },
        { key: 'count', label: t('reports.col.customers'), align: 'right' as const },
    ],
    rows: rows.map((row) => ({ label: row.label, count: row.value })),
});
</script>

<template>
    <div class="space-y-6" data-testid="customers-report">
        <p class="text-sm text-neutral-700">{{ t('reports.note.privacy') }}</p>

        <section :aria-label="t('reports.section.highlights')">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                <KpiCard :label="t('reports.metric.customers_total')" :help="t('reports.help.customers_total')" :metric="m('customers_total')" forward />
                <KpiCard :label="t('reports.metric.new')" :help="t('reports.help.new')" :metric="m('new')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.active')" :help="t('reports.help.active')" :metric="m('active')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.returning')" :help="t('reports.help.returning')" :metric="m('returning')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.with_upcoming')" :help="t('reports.help.with_upcoming')" :metric="m('with_upcoming')" forward />
                <KpiCard
                    :label="t('reports.metric.inactive')"
                    :help="t('reports.help.inactive', { days: report.inactive_days })"
                    :metric="m('inactive')"
                    forward
                />
                <KpiCard
                    :label="t('reports.metric.avg_per_customer')"
                    :help="t('reports.help.avg_per_customer')"
                    :metric="m('avg_per_customer')"
                    :previous="period.previous"
                    :digits="1"
                />
                <KpiCard
                    :label="t('reports.metric.returning_rate')"
                    :help="t('reports.help.returning_rate')"
                    :metric="m('returning_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.retention')"
                    :help="t('reports.help.retention')"
                    :metric="report.retention_base > 0 ? m('retention') : null"
                    :unavailable="report.retention_base === 0"
                />
            </dl>
        </section>

        <InsightList :insights="report.insights" />

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard :title="t('reports.section.customers_over_time')" class="lg:col-span-2">
                <template #chart><BarChart :items="timeItems" :series="series" :stacked="false" :format="(value) => formatCount(value, locale)" /></template>
                <template #table>
                    <DataTable :caption="t('reports.section.customers_over_time')" :columns="timeTable.columns" :rows="timeTable.rows" />
                </template>
            </ChartCard>
            <ChartCard :title="t('reports.section.preferred_service')" :empty="serviceItems.length === 0">
                <template #chart><HBarList :items="serviceItems" /></template>
                <template #table>
                    <DataTable :caption="t('reports.section.preferred_service')" v-bind="table(t('reports.col.service'), serviceItems)" />
                </template>
            </ChartCard>
            <ChartCard :title="t('reports.section.first_source')" :empty="sourceItems.length === 0">
                <template #chart><HBarList :items="sourceItems" /></template>
                <template #table><DataTable :caption="t('reports.section.first_source')" v-bind="table(t('reports.col.source'), sourceItems)" /></template>
            </ChartCard>
            <ChartCard v-if="report.by_location" :title="t('reports.section.main_location')" :empty="locationItems.length === 0">
                <template #chart><HBarList :items="locationItems" /></template>
                <template #table><DataTable :caption="t('reports.section.main_location')" v-bind="table(t('reports.col.location'), locationItems)" /></template>
            </ChartCard>
        </div>

        <p v-if="canOpenCustomers" class="print:hidden">
            <Link :href="routes.customers" class="text-sm font-semibold text-primary-700 underline">{{ t('reports.note.customers_link') }}</Link>
        </p>
    </div>
</template>
