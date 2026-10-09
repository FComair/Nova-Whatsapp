<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '../../composables/money';
import { bucketLabel, bucketTitle, formatCount, formatRate, heatmapTable, SERIES_STYLE } from '../../composables/reports';
import { useT } from '../../composables/useT';
import type { AppointmentsReportData, ReportPeriod } from '../../types/reports';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import type { SeriesDef } from './chartTypes';
import HBarList from './HBarList.vue';
import Heatmap from './Heatmap.vue';
import InsightList from './InsightList.vue';
import KpiCard from './KpiCard.vue';
import StackedBar from './StackedBar.vue';

/** Rapport « Rendez-vous » : chiffres clés, issues, demande dans le temps, jours et heures, origines et services. */
const props = defineProps<{ report: AppointmentsReportData; period: ReportPeriod; currency: string; showValues: boolean }>();

const { t, locale } = useT();
const style = (key: string, label: string): SeriesDef => ({ key, label, ...SERIES_STYLE[key] });
const statusSeries = computed(() => ['completed', 'upcoming', 'awaiting', 'no_show', 'cancelled'].map((key) => style(key, t(`reports.status.${key}`))));
const sourceSeries = computed(() => ['dashboard', 'whatsapp', 'staff_whatsapp', 'api'].map((key) => style(key, t(`reports.source.${key}`))));
const m = (key: string) => props.report.metrics[key] ?? null;

const timeItems = computed(() =>
    props.report.series.buckets.map((bucket) => ({
        key: bucket.key,
        label: bucketLabel(bucket.key, props.report.series.granularity),
        title: bucketTitle(bucket.key, props.report.series.granularity),
        values: [bucket.completed, bucket.upcoming, bucket.awaiting, bucket.no_show, bucket.cancelled],
    })),
);
const timeTable = computed(() => ({
    columns: [
        { key: 'period', label: t('reports.col.period') },
        ...statusSeries.value.map((series) => ({ key: series.key, label: series.label, align: 'right' as const })),
        { key: 'total', label: t('reports.chart.total'), align: 'right' as const },
    ],
    rows: props.report.series.buckets.map((bucket) => ({ period: bucketTitle(bucket.key, props.report.series.granularity), ...bucket })),
}));

const parts = computed(() =>
    props.report.statuses.map((status) => ({
        key: status.key,
        label: t(`reports.status.${status.key}`),
        value: status.count,
        series: style(status.key, t(`reports.status.${status.key}`)),
    })),
);
const total = computed(() => props.report.statuses.reduce((sum, status) => sum + status.count, 0));
const statusTable = computed(() => ({
    columns: [
        { key: 'status', label: t('reports.col.status') },
        { key: 'count', label: t('reports.col.appointments'), align: 'right' as const },
        { key: 'share', label: t('reports.col.share'), align: 'right' as const },
    ],
    rows: props.report.statuses.map((status) => ({
        status: t(`reports.status.${status.key}`),
        count: status.count,
        share: formatRate(total.value > 0 ? status.count / total.value : null, locale.value),
    })),
}));

const heat = computed(() => heatmapTable(props.report.heatmap, { day: t('reports.col.weekday'), weekday: (day) => t(`reports.weekday.${day}`) }));

const sourceItems = computed(() =>
    props.report.sources.map((source) => ({
        key: source.key,
        label: t(`reports.source.${source.key}`),
        value: source.count,
        text: `${formatCount(source.count, locale.value)} (${formatRate(source.share, locale.value)})`,
    })),
);
const sourceTable = computed(() => ({
    columns: [
        { key: 'source', label: t('reports.col.source') },
        { key: 'count', label: t('reports.col.appointments'), align: 'right' as const },
        { key: 'share', label: t('reports.col.share'), align: 'right' as const },
    ],
    rows: props.report.sources.map((source) => ({
        source: t(`reports.source.${source.key}`),
        count: source.count,
        share: formatRate(source.share, locale.value),
    })),
}));

const serviceName = (name: string | null): string => name ?? t('reports.others');
const serviceItems = computed(() =>
    props.report.services.map((service, index) => ({
        key: service.id ?? `others-${index}`,
        label: serviceName(service.name),
        value: service.count,
        text: formatCount(service.count, locale.value),
        sub:
            props.showValues && service.value_cents !== null
                ? `${t('reports.metric.booked_value')} : ${formatPrice(service.value_cents, locale.value, props.currency)}`
                : undefined,
    })),
);
const serviceTable = computed(() => ({
    columns: [
        { key: 'service', label: t('reports.col.service') },
        { key: 'count', label: t('reports.col.appointments'), align: 'right' as const },
        { key: 'cancelled', label: t('reports.status.cancelled'), align: 'right' as const },
        ...(props.showValues ? [{ key: 'value', label: t('reports.metric.booked_value'), align: 'right' as const }] : []),
    ],
    rows: props.report.services.map((service) => ({
        service: serviceName(service.name),
        count: service.count,
        cancelled: service.cancelled,
        value: service.value_cents === null ? null : formatPrice(service.value_cents, locale.value, props.currency),
    })),
}));

const crossItems = computed(() =>
    props.report.service_sources.map((service) => ({
        key: service.id,
        label: service.name.length > 12 ? `${service.name.slice(0, 11)}…` : service.name,
        title: service.name,
        values: sourceSeries.value.map((series) => service.sources[series.key] ?? 0),
    })),
);
const crossTable = computed(() => ({
    columns: [
        { key: 'service', label: t('reports.col.service') },
        ...sourceSeries.value.map((series) => ({ key: series.key, label: series.label, align: 'right' as const })),
    ],
    rows: props.report.service_sources.map((service) => ({
        service: service.name,
        ...Object.fromEntries(sourceSeries.value.map((series) => [series.key, service.sources[series.key] ?? 0])),
    })),
}));
</script>

<template>
    <div class="space-y-6" data-testid="appointments-report">
        <section :aria-label="t('reports.section.highlights')">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                <KpiCard :label="t('reports.metric.total')" :help="t('reports.help.total')" :metric="m('total')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.completed')" :help="t('reports.help.completed')" :metric="m('completed')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.upcoming')" :help="t('reports.help.upcoming')" :metric="m('upcoming')" forward />
                <KpiCard :label="t('reports.metric.awaiting')" :help="t('reports.help.awaiting')" :metric="m('awaiting')" forward />
                <KpiCard :label="t('reports.metric.cancelled')" :help="t('reports.help.cancelled')" :metric="m('cancelled')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.no_show')" :help="t('reports.help.no_show')" :metric="m('no_show')" :previous="period.previous" />
                <KpiCard
                    :label="t('reports.metric.rescheduled')"
                    :help="t('reports.help.rescheduled')"
                    :metric="m('rescheduled')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.cancellation_rate')"
                    :help="t('reports.help.cancellation_rate')"
                    :metric="m('cancellation_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.no_show_rate')"
                    :help="t('reports.help.no_show_rate')"
                    :metric="m('no_show_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.completion_rate')"
                    :help="t('reports.help.completion_rate')"
                    :metric="m('completion_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.avg_per_day')"
                    :help="t('reports.help.avg_per_day')"
                    :metric="m('avg_per_day')"
                    :previous="period.previous"
                    :digits="1"
                />
            </dl>
        </section>

        <InsightList :insights="report.insights" />

        <div class="grid gap-4 lg:grid-cols-2">
            <ChartCard :title="t('reports.section.over_time')" class="lg:col-span-2">
                <template #chart><BarChart :items="timeItems" :series="statusSeries" :format="(value) => formatCount(value, locale)" /></template>
                <template #table><DataTable :caption="t('reports.section.over_time')" :columns="timeTable.columns" :rows="timeTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.statuses')" :empty="total === 0">
                <template #chart><StackedBar :parts="parts" /></template>
                <template #table><DataTable :caption="t('reports.section.statuses')" :columns="statusTable.columns" :rows="statusTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.sources')" :empty="sourceItems.length === 0">
                <template #chart><HBarList :items="sourceItems" /></template>
                <template #table><DataTable :caption="t('reports.section.sources')" :columns="sourceTable.columns" :rows="sourceTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.heatmap')" :empty="report.heatmap.total === 0" class="lg:col-span-2">
                <template #chart><Heatmap :data="report.heatmap" /></template>
                <template #table><DataTable :caption="t('reports.section.heatmap')" :columns="heat.columns" :rows="heat.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.services')" :empty="serviceItems.length === 0">
                <template #chart><HBarList :items="serviceItems" /></template>
                <template #table><DataTable :caption="t('reports.section.services')" :columns="serviceTable.columns" :rows="serviceTable.rows" /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.service_sources')" :empty="crossItems.length === 0">
                <template #chart><BarChart :items="crossItems" :series="sourceSeries" :format="(value) => formatCount(value, locale)" /></template>
                <template #table><DataTable :caption="t('reports.section.service_sources')" :columns="crossTable.columns" :rows="crossTable.rows" /></template>
            </ChartCard>
        </div>
    </div>
</template>
