<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ActivityList from '../../Components/ActivityList.vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import LoyaltyTrend from '../../Components/LoyaltyTrend.vue';
import RuleSummary from '../../Components/RuleSummary.vue';
import { programVariant } from '../../composables/loyalty';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';
import type { ActivityEntry, LoyaltyAbilities, LoyaltyReportData, ProgramSummary } from '../../types/loyalty';

/** Tableau de bord de la fidélité : résumé de la règle, chiffres du moment, clients proches de leur récompense, activité récente, évolution. */
const props = defineProps<{
    can: LoyaltyAbilities;
    program: ProgramSummary | null;
    overview: {
        enrolled: number;
        opted_out: number;
        in_progress: number;
        rewards_earned: number;
        rewards_redeemed: number;
        rewards_outstanding: number;
        rewards_expired: number;
        stamps_in_circulation: number;
        close: { customer_id: string; name: string | null; phone: string; stamps: number; remaining: number }[];
    } | null;
    recent: ActivityEntry[];
    report: LoyaltyReportData | null;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const kpis = ['enrolled', 'in_progress', 'rewards_earned', 'rewards_redeemed', 'rewards_outstanding', 'rewards_expired'] as const;
const hasAny = computed(() => (props.overview?.enrolled ?? 0) > 0 || (props.overview?.rewards_earned ?? 0) > 0);
</script>

<template>
    <AppLayout :title="t('loyalty.title')">
        <LoyaltyTabs current="overview" :can="can" />

        <AlertBox v-if="page.props.flash.status" variant="success" class="mb-4">{{ page.props.flash.status }}</AlertBox>

        <section v-if="program === null" class="rounded-card border border-line bg-surface p-8 text-center shadow-xs" data-testid="loyalty-empty">
            <h1 class="text-xl font-semibold text-nova-900">{{ t('loyalty.empty.not_set_up') }}</h1>
            <p class="mx-auto mt-2 max-w-xl text-neutral-700">{{ can.configure ? t('loyalty.empty.not_set_up_body') : t('loyalty.empty.not_set_up_staff') }}</p>
            <Link
                v-if="can.configure"
                :href="`${routes.loyalty}/programme`"
                class="mt-5 inline-flex min-h-11 items-center justify-center rounded-full bg-primary-600 px-6 text-sm font-semibold text-white hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            >
                {{ t('loyalty.empty.set_up') }}
            </Link>
        </section>

        <div v-else class="space-y-6">
            <section class="rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="program-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 id="program-title" class="text-xl font-semibold text-nova-900">{{ program.name || t('loyalty.title') }}</h1>
                        <p class="mt-1 text-sm text-neutral-700">{{ t(`loyalty.status_help.${program.status}`) }}</p>
                    </div>
                    <Badge :variant="programVariant(program.status)">{{ t(`loyalty.status.${program.status}`) }}</Badge>
                </div>
                <div class="mt-4">
                    <RuleSummary :rule="{ ...program, reward_description: program.reward_description }" />
                </div>
                <div class="mt-4 flex flex-wrap gap-2 text-sm font-semibold">
                    <Link
                        v-if="can.configure"
                        :href="`${routes.loyalty}/programme`"
                        class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 text-primary-700 hover:border-primary-600"
                    >
                        {{ t('loyalty.index.configure') }}
                    </Link>
                    <Link
                        v-if="can.configure"
                        :href="`${routes.loyalty}/messages`"
                        class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 text-primary-700 hover:border-primary-600"
                    >
                        {{ t('loyalty.index.messages') }}
                    </Link>
                    <Link
                        v-if="can.redeem"
                        :href="`${routes.loyalty}/utiliser`"
                        class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 text-primary-700 hover:border-primary-600"
                    >
                        {{ t('loyalty.index.redeem') }}
                    </Link>
                    <Link
                        :href="`${routes.loyalty}/rapport`"
                        class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 text-primary-700 hover:border-primary-600"
                    >
                        {{ t('loyalty.index.report') }}
                    </Link>
                </div>
            </section>

            <section v-if="overview" :aria-label="t('loyalty.index.summary')">
                <dl class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <div v-for="key in kpis" :key="key" class="rounded-card border border-line bg-surface p-4 shadow-xs">
                        <dt class="text-xs font-semibold text-neutral-700" :title="t(`loyalty.index.help.${key}`)">{{ t(`loyalty.index.kpi.${key}`) }}</dt>
                        <dd class="mt-1 text-2xl font-bold tabular-nums text-nova-900" :data-testid="`kpi-${key}`">{{ overview[key] }}</dd>
                        <p class="mt-1 text-xs text-neutral-600">{{ t(`loyalty.index.help.${key}`) }}</p>
                    </div>
                </dl>
            </section>

            <p v-if="overview && !hasAny" class="rounded-card border border-dashed border-line-strong p-4 text-sm text-neutral-800" data-testid="no-progress">
                {{ t('loyalty.empty.no_progress') }}
            </p>

            <div v-if="overview" class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="close-title">
                    <h2 id="close-title" class="font-semibold text-nova-900">{{ t('loyalty.index.close.title') }}</h2>
                    <p v-if="overview.close.length === 0" class="mt-3 text-sm text-neutral-700">{{ t('loyalty.index.close.empty') }}</p>
                    <ul v-else class="mt-3 divide-y divide-line">
                        <li v-for="row in overview.close" :key="row.customer_id" class="flex items-center justify-between gap-3 py-2 text-sm">
                            <Link
                                :href="`${routes.customers}/${row.customer_id}`"
                                class="min-w-0 truncate font-medium text-primary-700 underline underline-offset-2"
                            >
                                {{ row.name ?? row.phone }}
                            </Link>
                            <span class="shrink-0 text-neutral-800"
                                >{{ row.stamps }}/{{ program.required_visits }} · {{ t('loyalty.index.close.remaining', { count: row.remaining }) }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section class="rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="trend-title">
                    <h2 id="trend-title" class="font-semibold text-nova-900">{{ t('loyalty.index.performance.title') }}</h2>
                    <p class="text-xs text-neutral-700">{{ t('loyalty.index.performance.period') }}</p>
                    <div class="mt-3">
                        <LoyaltyTrend
                            v-if="report"
                            :series="report.series"
                            :granularity="report.period.granularity"
                            :table-label="t('loyalty.report.series_table')"
                        />
                    </div>
                    <Link :href="`${routes.loyalty}/rapport`" class="mt-3 inline-block text-sm font-semibold text-primary-700 underline underline-offset-2">
                        {{ t('loyalty.index.performance.see_report') }}
                    </Link>
                </section>
            </div>

            <section class="rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="activity-title">
                <h2 id="activity-title" class="font-semibold text-nova-900">{{ t('loyalty.index.activity.title') }}</h2>
                <p v-if="recent.length === 0" class="mt-3 text-sm text-neutral-700">{{ t('loyalty.empty.no_activity') }}</p>
                <ActivityList v-else :entries="recent" show-customer class="mt-2" />
            </section>
        </div>
    </AppLayout>
</template>
