<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '../../composables/money';
import { formatCount, formatMinutes, formatRate, heatmapTable, SERIES_STYLE } from '../../composables/reports';
import { formatDate } from '../../composables/dates';
import { useT } from '../../composables/useT';
import type { PractitionersReportData, ReportPeriod } from '../../types/reports';
import AlertBox from '../AlertBox.vue';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import DataTable from './DataTable.vue';
import type { SeriesDef } from './chartTypes';
import HBarList from './HBarList.vue';
import Heatmap from './Heatmap.vue';
import InsightList from './InsightList.vue';
import KpiCard from './KpiCard.vue';

/**
 * Rapport « Praticiens » : charge de travail et occupation. Trois notions distinctes, jamais confondues : temps de travail PRÉVU
 * (planning), temps RÉSERVÉ (rendez-vous) et heures réellement travaillées (non enregistrées, donc absentes).
 */
const props = defineProps<{ report: PractitionersReportData; period: ReportPeriod; currency: string; showValues: boolean; showCompare: boolean }>();

const { t, locale } = useT();
const m = (key: string) => props.report.metrics[key] ?? null;
const hours = (minutes: number): string => formatMinutes(minutes, locale.value);

const columns = computed(() => [
    { key: 'name', label: t('reports.col.practitioner'), sortable: true },
    { key: 'assigned', label: t('reports.col.assigned'), align: 'right' as const, sortable: true },
    { key: 'completed', label: t('reports.status.completed'), align: 'right' as const, sortable: true },
    { key: 'cancelled', label: t('reports.status.cancelled'), align: 'right' as const, sortable: true },
    { key: 'no_show', label: t('reports.status.no_show'), align: 'right' as const, sortable: true },
    { key: 'scheduled', label: t('reports.col.scheduled'), align: 'right' as const, sortable: true },
    { key: 'booked', label: t('reports.col.booked'), align: 'right' as const, sortable: true },
    { key: 'available', label: t('reports.col.available'), align: 'right' as const, sortable: true },
    { key: 'utilization', label: t('reports.col.utilization'), align: 'right' as const, sortable: true },
    { key: 'days_off', label: t('reports.col.days_off'), align: 'right' as const, sortable: true },
    ...(props.showValues
        ? [
              { key: 'value', label: `${t('reports.metric.booked_value')} (${props.currency})`, align: 'right' as const, sortable: true },
              { key: 'collected_money', label: `${t('reports.metric.collected')} (${props.currency})`, align: 'right' as const, sortable: true },
          ]
        : []),
]);
const rows = computed(() =>
    props.report.rows.map((row) => ({
        name: row.name,
        assigned: row.assigned,
        completed: row.completed,
        cancelled: row.cancelled,
        no_show: row.no_show,
        scheduled: { text: hours(row.scheduled_minutes), sort: row.scheduled_minutes },
        booked: { text: hours(row.booked_minutes), sort: row.booked_minutes },
        available: { text: hours(row.available_minutes), sort: row.available_minutes },
        utilization: { text: formatRate(row.utilization, locale.value), sort: row.utilization ?? -1 },
        days_off: row.days_off,
        value: { text: formatPrice(row.value_cents ?? 0, locale.value, props.currency), sort: row.value_cents ?? 0 },
        collected_money: { text: formatPrice(row.collected_cents ?? 0, locale.value, props.currency), sort: row.collected_cents ?? 0 },
    })),
);
const over = computed(() => props.report.rows.some((row) => row.utilization !== null && row.utilization > 1));

const hourSeries = computed<SeriesDef[]>(() => [
    { key: 'booked', label: t('reports.metric.booked'), ...SERIES_STYLE.booked },
    { key: 'available', label: t('reports.metric.available'), ...SERIES_STYLE.available },
]);
const hourItems = computed(() =>
    props.report.rows.map((row) => ({
        key: row.id,
        label: row.name.length > 10 ? `${row.name.slice(0, 9)}…` : row.name,
        title: row.name,
        values: [row.booked_minutes, row.available_minutes],
    })),
);
const completedItems = computed(() =>
    props.report.rows.map((row) => ({
        key: row.id,
        label: row.name,
        value: row.completed,
        text: formatCount(row.completed, locale.value),
        sub: `${t('reports.metric.assigned')} : ${formatCount(row.assigned, locale.value)}`,
    })),
);
const mixRows = computed(() => props.report.rows.filter((row) => row.services.length > 0));
const heat = computed(() => heatmapTable(props.report.heatmap, { day: t('reports.col.weekday'), weekday: (day) => t(`reports.weekday.${day}`) }));
const offTable = computed(() => ({
    columns: [
        { key: 'name', label: t('reports.col.practitioner') },
        { key: 'from', label: t('reports.col.from') },
        { key: 'to', label: t('reports.col.to') },
        { key: 'days', label: t('reports.col.days'), align: 'right' as const },
    ],
    rows: props.report.time_off.map((off) => ({ name: off.practitioner, from: formatDate(off.from), to: formatDate(off.to), days: off.days })),
}));
</script>

<template>
    <div class="space-y-6" data-testid="practitioners-report">
        <AlertBox variant="info">
            <p>{{ t('reports.note.worked_hours') }}</p>
            <p class="mt-1">{{ t('reports.note.utilization') }} {{ t('reports.note.days_off') }}</p>
        </AlertBox>

        <section :aria-label="t('reports.section.highlights')">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                <KpiCard :label="t('reports.metric.assigned')" :help="t('reports.help.assigned')" :metric="m('assigned')" :previous="period.previous" />
                <KpiCard :label="t('reports.status.completed')" :help="t('reports.help.completed')" :metric="m('completed')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.scheduled')" :help="t('reports.help.scheduled')" :metric="m('scheduled')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.booked')" :help="t('reports.help.booked')" :metric="m('booked')" :previous="period.previous" />
                <KpiCard :label="t('reports.metric.available')" :help="t('reports.help.available')" :metric="m('available')" :previous="period.previous" />
                <KpiCard
                    :label="t('reports.metric.utilization')"
                    :help="t('reports.help.utilization')"
                    :metric="m('utilization')"
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
            </dl>
        </section>

        <InsightList :insights="report.insights" />

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                v-if="showCompare"
                class="min-w-0 rounded-card border border-line bg-surface p-4 shadow-xs sm:p-5 lg:col-span-2 print:break-inside-avoid print:shadow-none"
                :aria-label="t('reports.section.comparison')"
            >
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.section.comparison') }}</h3>
                <p v-if="over" class="mt-1 text-xs text-neutral-700">{{ t('reports.practitioner.utilization_cap') }}</p>
                <div class="mt-3">
                    <p v-if="report.rows.length === 0" class="rounded-control bg-neutral-50 px-4 py-6 text-center text-sm text-neutral-700">
                        {{ t('reports.empty.section') }}
                    </p>
                    <DataTable v-else :caption="t('reports.section.comparison')" :columns="columns" :rows="rows" />
                </div>
            </section>

            <ChartCard v-if="showCompare" :title="t('reports.section.hours')" :empty="report.rows.length === 0" :note="t('reports.note.utilization')">
                <template #chart><BarChart :items="hourItems" :series="hourSeries" :format="(value) => hours(value)" /></template>
                <template #table>
                    <DataTable
                        :caption="t('reports.section.hours')"
                        :columns="[
                            { key: 'name', label: t('reports.col.practitioner') },
                            { key: 'scheduled', label: t('reports.col.scheduled'), align: 'right' },
                            { key: 'booked', label: t('reports.col.booked'), align: 'right' },
                            { key: 'available', label: t('reports.col.available'), align: 'right' },
                        ]"
                        :rows="rows"
                    />
                </template>
            </ChartCard>

            <ChartCard v-if="showCompare" :title="t('reports.section.completed_by')" :empty="report.rows.length === 0">
                <template #chart><HBarList :items="completedItems" /></template>
                <template #table>
                    <DataTable
                        :caption="t('reports.section.completed_by')"
                        :columns="[
                            { key: 'name', label: t('reports.col.practitioner') },
                            { key: 'assigned', label: t('reports.col.assigned'), align: 'right' },
                            { key: 'completed', label: t('reports.status.completed'), align: 'right' },
                        ]"
                        :rows="rows"
                    />
                </template>
            </ChartCard>

            <ChartCard v-if="showCompare" :title="t('reports.section.service_mix')" :empty="mixRows.length === 0" hide-toggle>
                <template #chart>
                    <ul class="space-y-3 text-sm">
                        <li v-for="row in mixRows" :key="row.id" class="print:break-inside-avoid">
                            <p class="font-medium text-neutral-900">{{ row.name }}</p>
                            <p class="text-neutral-800">{{ row.services.map((service) => `${service.name} (${service.count})`).join(' · ') }}</p>
                        </li>
                    </ul>
                </template>
                <template #table><span /></template>
            </ChartCard>

            <ChartCard :title="t('reports.section.workload')" :empty="report.heatmap.total === 0" class="lg:col-span-2">
                <template #chart><Heatmap :data="report.heatmap" /></template>
                <template #table><DataTable :caption="t('reports.section.workload')" :columns="heat.columns" :rows="heat.rows" /></template>
            </ChartCard>

            <section
                v-if="showCompare"
                class="min-w-0 rounded-card border border-line bg-surface p-4 shadow-xs sm:p-5 lg:col-span-2 print:break-inside-avoid print:shadow-none"
                :aria-label="t('reports.section.time_off')"
            >
                <h3 class="text-base font-semibold text-nova-900">{{ t('reports.section.time_off') }}</h3>
                <p v-if="report.time_off.length === 0" class="mt-3 text-sm text-neutral-700">{{ t('reports.practitioner.time_off_none') }}</p>
                <div v-else class="mt-3"><DataTable :caption="t('reports.section.time_off')" :columns="offTable.columns" :rows="offTable.rows" /></div>
            </section>
        </div>
    </div>
</template>
