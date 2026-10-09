<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BaseButton from '../../Components/BaseButton.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import LoyaltyTrend from '../../Components/LoyaltyTrend.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { formatDate } from '../../composables/dates';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { LoyaltyAbilities, LoyaltyReportData, ProgramSummary, ReturningGroup } from '../../types/loyalty';

/**
 * Rapport de fidélité : tampons, récompenses, taux d'utilisation, inscrits et retour des clients, filtrés par dates, service, site et programme.
 * Les chiffres décrivent ce qui s'est passé ; l'écran ne prétend jamais que le programme a causé une hausse de visites ou de chiffre d'affaires.
 */
const props = defineProps<{
    can: LoyaltyAbilities;
    program: ProgramSummary | null;
    report: LoyaltyReportData;
    services: { id: string; name: string }[];
    locations: { id: string; name: string }[];
}>();

const { t, locale } = useT();
const from = ref(props.report.period.from);
const to = ref(props.report.period.to);
const service = ref(props.report.filters.service ?? '');
const location = ref(props.report.filters.location ?? '');
const programId = ref(props.report.filters.program ?? '');
const metrics = [
    'enrolled',
    'stamps_earned',
    'stamps_reversed',
    'stamps_net',
    'rewards_earned',
    'rewards_redeemed',
    'rewards_outstanding',
    'redemption_rate',
] as const;

function apply(): void {
    router.get(
        `${routes.loyalty}/rapport`,
        { from: from.value, to: to.value, service: service.value || undefined, location: location.value || undefined, program: programId.value || undefined },
        { preserveScroll: true },
    );
}

function value(key: (typeof metrics)[number]): string {
    const metric = props.report.metrics[key];

    if (key === 'redemption_rate') {
        return metric === null ? '—' : new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 0 }).format(Number(metric));
    }

    return new Intl.NumberFormat(locale.value).format(Number(metric));
}

const line = (group: ReturningGroup): string =>
    group.customers === 0 ? t('loyalty.report.returning_none') : t('loyalty.report.returning_line', { repeat: group.repeat, customers: group.customers });
const percent = (rate: number | null): string =>
    rate === null ? '' : new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 0 }).format(rate);
</script>

<template>
    <AppLayout :title="t('loyalty.report.title')">
        <LoyaltyTabs current="report" :can="can" />

        <h1 class="text-xl font-semibold text-nova-900">{{ t('loyalty.report.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-700">{{ t('loyalty.report.intro') }} {{ formatDate(report.period.from) }} – {{ formatDate(report.period.to) }}.</p>

        <form
            class="mt-4 grid gap-3 rounded-card border border-line bg-surface p-4 shadow-xs sm:grid-cols-2 lg:grid-cols-6"
            :aria-label="t('loyalty.report.filters')"
            @submit.prevent="apply"
        >
            <TextField v-model="from" type="date" :label="t('loyalty.report.from')" />
            <TextField v-model="to" type="date" :label="t('loyalty.report.to')" />
            <SelectField
                v-model="service"
                :label="t('loyalty.report.service')"
                :options="[{ value: '', label: t('loyalty.report.all_services') }, ...services.map((row) => ({ value: row.id, label: row.name }))]"
            />
            <SelectField
                v-model="location"
                :label="t('loyalty.report.location')"
                :options="[{ value: '', label: t('loyalty.report.all_locations') }, ...locations.map((row) => ({ value: row.id, label: row.name }))]"
            />
            <SelectField
                v-model="programId"
                :label="t('loyalty.report.program')"
                :options="[
                    { value: '', label: t('loyalty.report.all_programs') },
                    ...report.programs.map((row) => ({
                        value: row.id,
                        label: `${row.name || t('loyalty.title')} (${formatDate(row.created_at)}, ${t(`loyalty.status.${row.status}`).toLowerCase()})`,
                    })),
                ]"
            />
            <div class="flex items-end">
                <BaseButton type="submit" class="w-full">{{ t('loyalty.report.apply') }}</BaseButton>
            </div>
        </form>

        <p v-if="report.scoped" class="mt-3 text-sm text-neutral-800" data-testid="scoped-note">{{ t('loyalty.report.scoped') }}</p>

        <dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
            <div v-for="key in metrics" :key="key" class="rounded-card border border-line bg-surface p-4 shadow-xs">
                <dt class="text-xs font-semibold text-neutral-700">{{ t(`loyalty.report.metric.${key}`) }}</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums text-nova-900" :data-testid="`metric-${key}`">{{ value(key) }}</dd>
                <p v-if="key === 'redemption_rate'" class="text-xs text-neutral-700">
                    {{
                        report.metrics.redemption_rate_base > 0
                            ? t('loyalty.report.rate_base', { count: report.metrics.redemption_rate_base })
                            : t('loyalty.report.no_rate')
                    }}
                </p>
                <p class="mt-1 text-xs text-neutral-600">{{ t(`loyalty.report.help.${key}`) }}</p>
            </div>
        </dl>

        <section class="mt-6 rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="series-title">
            <h2 id="series-title" class="font-semibold text-nova-900">{{ t('loyalty.report.series') }}</h2>
            <div class="mt-3">
                <LoyaltyTrend :series="report.series" :granularity="report.period.granularity" :table-label="t('loyalty.report.series_table')" />
            </div>
        </section>

        <section class="mt-6 rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="returning-title">
            <h2 id="returning-title" class="font-semibold text-nova-900">{{ t('loyalty.report.returning_title') }}</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-surface-2 p-3 text-sm">
                    <dt class="font-semibold">{{ t('loyalty.report.returning_enrolled') }}</dt>
                    <dd data-testid="returning-enrolled">
                        {{ line(report.returning.enrolled) }}
                        <span v-if="report.returning.enrolled.rate !== null" class="font-semibold">({{ percent(report.returning.enrolled.rate) }})</span>
                    </dd>
                </div>
                <div class="rounded-xl bg-surface-2 p-3 text-sm">
                    <dt class="font-semibold">{{ t('loyalty.report.returning_others') }}</dt>
                    <dd data-testid="returning-others">
                        {{ line(report.returning.others) }}
                        <span v-if="report.returning.others.rate !== null" class="font-semibold">({{ percent(report.returning.others.rate) }})</span>
                    </dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-neutral-800" data-testid="causal-note">{{ t('loyalty.report.returning_note') }}</p>
        </section>
    </AppLayout>
</template>
