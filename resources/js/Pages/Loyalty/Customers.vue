<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Badge from '../../Components/Badge.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { formatDate } from '../../composables/dates';
import { memberVariant } from '../../composables/loyalty';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { LoyaltyAbilities, MemberStatus, ProgramSummary } from '../../types/loyalty';

interface Row {
    customer_id: string;
    name: string | null;
    phone: string;
    stamps: number | null;
    required: number;
    rewards_available: number;
    last_visit_at: string | null;
    status: MemberStatus;
}

/** Clients du programme : progression, récompenses disponibles, dernière visite, statut ; recherche et filtre par statut. */
const props = defineProps<{
    can: LoyaltyAbilities;
    program: ProgramSummary | null;
    customers: Row[];
    pagination: { page: number; last: number; total: number };
    filters: { status: string; q: string };
    statuses: MemberStatus[];
}>();

const { t } = useT();
const search = ref(props.filters.q);
const status = ref(props.filters.status);
let timer: ReturnType<typeof setTimeout> | undefined;

function visit(page = 1): void {
    router.get(
        `${routes.loyalty}/clients`,
        { q: search.value || undefined, status: status.value || undefined, page: page > 1 ? page : undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => visit(), 300);
});
watch(status, () => visit());
</script>

<template>
    <AppLayout :title="t('loyalty.customers.title')">
        <LoyaltyTabs current="customers" :can="can" />

        <h1 class="text-xl font-semibold text-rendivoo-900">{{ t('loyalty.customers.title') }}</h1>

        <div class="mt-4 grid gap-3 sm:grid-cols-[2fr_1fr]">
            <TextField v-model="search" :label="t('loyalty.customers.search')" :hint="t('loyalty.customers.search_hint')" inputmode="text" />
            <SelectField
                v-model="status"
                :label="t('loyalty.customers.status')"
                :options="[
                    { value: '', label: t('loyalty.customers.all_statuses') },
                    ...statuses.map((value) => ({ value, label: t(`loyalty.member_status.${value}`) })),
                ]"
            />
        </div>

        <p class="mt-3 text-sm text-neutral-700" aria-live="polite">{{ t('loyalty.customers.count', { count: pagination.total }) }}</p>

        <div v-if="program === null" class="mt-4 rounded-card border border-dashed border-line-strong p-6 text-sm text-neutral-800">
            {{ t('loyalty.empty.not_set_up') }}
        </div>
        <div
            v-else-if="customers.length === 0"
            class="mt-4 rounded-card border border-dashed border-line-strong p-6 text-sm text-neutral-800"
            data-testid="no-customers"
        >
            {{ t('loyalty.empty.no_customers') }}
        </div>

        <div v-else class="mt-3 overflow-x-auto rounded-card border border-line bg-surface shadow-xs">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-2 text-xs text-neutral-800">
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ t('loyalty.customers.col.customer') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ t('loyalty.customers.col.progress') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right font-semibold">{{ t('loyalty.customers.col.rewards') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ t('loyalty.customers.col.last_visit') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">{{ t('loyalty.customers.col.status') }}</th>
                        <th scope="col" class="px-4 py-2.5 font-semibold">
                            <span class="sr-only">{{ t('loyalty.customers.col.actions') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in customers" :key="row.customer_id" class="border-b border-line/60 last:border-0">
                        <th scope="row" class="px-4 py-2.5 font-medium">
                            <Link :href="`${routes.customers}/${row.customer_id}`" class="text-primary-700 underline underline-offset-2">
                                {{ row.name ?? t('loyalty.customers.unnamed') }}
                            </Link>
                            <span class="block text-xs font-normal text-neutral-700">{{ row.phone }}</span>
                        </th>
                        <td class="px-4 py-2.5 tabular-nums">
                            {{
                                row.stamps === null
                                    ? t('loyalty.customers.not_enrolled_progress')
                                    : t('loyalty.progress.of', { stamps: row.stamps, required: row.required })
                            }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ row.rewards_available }}</td>
                        <td class="px-4 py-2.5">{{ row.last_visit_at ? formatDate(row.last_visit_at) : t('loyalty.customers.never') }}</td>
                        <td class="px-4 py-2.5">
                            <Badge :variant="memberVariant(row.status)">{{ t(`loyalty.member_status.${row.status}`) }}</Badge>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <Link
                                v-if="can.redeem && row.rewards_available > 0"
                                :href="`${routes.loyalty}/utiliser?customer=${row.customer_id}`"
                                class="font-semibold text-primary-700 underline underline-offset-2"
                            >
                                {{ t('loyalty.customers.redeem') }}
                            </Link>
                            <Link v-else :href="`${routes.customers}/${row.customer_id}`" class="text-neutral-800 underline underline-offset-2">
                                {{ t('loyalty.customers.open') }}
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination.last > 1"
            class="mt-4 flex items-center justify-between gap-3 text-sm"
            :aria-label="t('loyalty.customers.page', { page: pagination.page, last: pagination.last })"
        >
            <button
                type="button"
                class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 font-semibold disabled:opacity-50"
                :disabled="pagination.page <= 1"
                @click="visit(pagination.page - 1)"
            >
                {{ t('loyalty.customers.previous') }}
            </button>
            <span class="text-neutral-700">{{ t('loyalty.customers.page', { page: pagination.page, last: pagination.last }) }}</span>
            <button
                type="button"
                class="rounded-full border-[1.5px] border-line-strong px-4 py-1.5 font-semibold disabled:opacity-50"
                :disabled="pagination.page >= pagination.last"
                @click="visit(pagination.page + 1)"
            >
                {{ t('loyalty.customers.next') }}
            </button>
        </nav>
    </AppLayout>
</template>
