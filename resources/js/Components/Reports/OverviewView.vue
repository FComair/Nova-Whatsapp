<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { bucketLabel, bucketTitle, formatCount, SERIES_STYLE } from '../../composables/reports';
import { useT } from '../../composables/useT';
import type { OverviewData, ReportCategory, ReportPeriod } from '../../types/reports';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import type { SeriesDef } from './chartTypes';
import InsightList from './InsightList.vue';
import KpiCard from './KpiCard.vue';

/** Vue d'ensemble : les chiffres les plus importants de chaque catégorie permise, puis l'évolution des rendez-vous. */
const props = defineProps<{ overview: OverviewData; period: ReportPeriod; links: Record<ReportCategory, string> }>();

const { t, locale } = useT();
const appointments = computed(() => props.overview.appointments);
const m = (group: Record<string, import('../../types/reports').ReportMetric> | undefined, key: string) => group?.[key] ?? null;

const series = computed<SeriesDef[]>(() =>
    ['completed', 'upcoming', 'awaiting', 'no_show', 'cancelled'].map((key) => ({ key, label: t(`reports.status.${key}`), ...SERIES_STYLE[key] })),
);
const items = computed(() =>
    (appointments.value?.series.buckets ?? []).map((bucket) => ({
        key: bucket.key,
        label: bucketLabel(bucket.key, appointments.value!.series.granularity),
        title: bucketTitle(bucket.key, appointments.value!.series.granularity),
        values: [bucket.completed, bucket.upcoming, bucket.awaiting, bucket.no_show, bucket.cancelled],
    })),
);
const table = computed(() => ({
    columns: [
        { key: 'period', label: t('reports.col.period') },
        ...series.value.map((item) => ({ key: item.key, label: item.label, align: 'right' as const })),
        { key: 'total', label: t('reports.chart.total'), align: 'right' as const },
    ],
    rows: (appointments.value?.series.buckets ?? []).map((bucket) => ({ period: bucketTitle(bucket.key, appointments.value!.series.granularity), ...bucket })),
}));
const empty = computed(() => (appointments.value?.metrics.total?.value ?? 0) === 0);
</script>

<template>
    <div class="space-y-6" data-testid="overview-report">
        <section v-if="overview.appointments" :aria-label="t('reports.overview.appointments_lead')">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.category.appointments') }}</h3>
                <Link :href="links.appointments" class="text-sm font-semibold text-primary-700 underline print:hidden">{{ t('reports.overview.go') }}</Link>
            </div>
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    :label="t('reports.metric.total')"
                    :help="t('reports.help.total')"
                    :metric="m(overview.appointments.metrics, 'total')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.completed')"
                    :help="t('reports.help.completed')"
                    :metric="m(overview.appointments.metrics, 'completed')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.cancellation_rate')"
                    :help="t('reports.help.cancellation_rate')"
                    :metric="m(overview.appointments.metrics, 'cancellation_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.no_show_rate')"
                    :help="t('reports.help.no_show_rate')"
                    :metric="m(overview.appointments.metrics, 'no_show_rate')"
                    :previous="period.previous"
                />
            </dl>
        </section>

        <section v-if="overview.customers" :aria-label="t('reports.overview.customers_lead')">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.category.customers') }}</h3>
                <Link :href="links.customers" class="text-sm font-semibold text-primary-700 underline print:hidden">{{ t('reports.overview.go') }}</Link>
            </div>
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    :label="t('reports.metric.new')"
                    :help="t('reports.help.new')"
                    :metric="m(overview.customers.metrics, 'new')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.active')"
                    :help="t('reports.help.active')"
                    :metric="m(overview.customers.metrics, 'active')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.returning_rate')"
                    :help="t('reports.help.returning_rate')"
                    :metric="m(overview.customers.metrics, 'returning_rate')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.with_upcoming')"
                    :help="t('reports.help.with_upcoming')"
                    :metric="m(overview.customers.metrics, 'with_upcoming')"
                    forward
                />
            </dl>
        </section>

        <section v-if="overview.financial" :aria-label="t('reports.overview.financial_lead')">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.category.financial') }}</h3>
                <Link :href="links.financial" class="text-sm font-semibold text-primary-700 underline print:hidden">{{ t('reports.overview.go') }}</Link>
            </div>
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    :label="t('reports.metric.booked_value')"
                    :help="t('reports.help.booked_value')"
                    :metric="m(overview.financial.metrics, 'booked_value')"
                    :currency="overview.financial.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.collected')"
                    :help="t('reports.help.collected')"
                    :metric="m(overview.financial.metrics, 'collected')"
                    :currency="overview.financial.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.outstanding')"
                    :help="t('reports.help.outstanding')"
                    :metric="m(overview.financial.metrics, 'outstanding')"
                    :currency="overview.financial.currency"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.avg_value')"
                    :help="t('reports.help.avg_value')"
                    :metric="m(overview.financial.metrics, 'avg_value')"
                    :currency="overview.financial.currency"
                    :previous="period.previous"
                />
            </dl>
        </section>

        <section v-if="overview.practitioners" :aria-label="t('reports.overview.practitioners_lead')">
            <div class="mb-2 flex items-baseline justify-between gap-3">
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.category.practitioners') }}</h3>
                <Link :href="links.practitioners" class="text-sm font-semibold text-primary-700 underline print:hidden">{{ t('reports.overview.go') }}</Link>
            </div>
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    :label="t('reports.metric.scheduled')"
                    :help="t('reports.help.scheduled')"
                    :metric="m(overview.practitioners.metrics, 'scheduled')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.booked')"
                    :help="t('reports.help.booked')"
                    :metric="m(overview.practitioners.metrics, 'booked')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.utilization')"
                    :help="t('reports.help.utilization')"
                    :metric="m(overview.practitioners.metrics, 'utilization')"
                    :previous="period.previous"
                />
                <KpiCard
                    :label="t('reports.metric.no_show_rate')"
                    :help="t('reports.help.no_show_rate')"
                    :metric="m(overview.practitioners.metrics, 'no_show_rate')"
                    :previous="period.previous"
                />
            </dl>
        </section>

        <InsightList v-if="appointments" :insights="appointments.insights" />

        <ChartCard v-if="appointments" :title="t('reports.section.over_time')" :empty="empty">
            <template #chart><BarChart :items="items" :series="series" :format="(value) => formatCount(value, locale)" /></template>
            <template #table><DataTable :caption="t('reports.section.over_time')" :columns="table.columns" :rows="table.rows" /></template>
        </ChartCard>
    </div>
</template>
