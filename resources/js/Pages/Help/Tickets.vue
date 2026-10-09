<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import HelpTabs from '../../Components/HelpTabs.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import TicketStatusBadge from '../../Components/TicketStatusBadge.vue';
import { formatDate, formatDateTime } from '../../composables/dates';
import { STATUSES, urgencyVariant } from '../../composables/supportTickets';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

interface Row {
    reference: string;
    subject: string;
    status: string;
    category: string;
    urgency: string;
    created_at: string;
    last_message_at: string;
    unread: boolean;
    mine: boolean;
}

type Filters = Partial<{ status: string; category: string; urgency: string; attention: string; scope: string; q: string }>;

const props = defineProps<{
    tickets: Row[];
    pagination: { page: number; last_page: number; total: number };
    filters: Filters;
    sort: 'created' | 'updated';
    dir: 'asc' | 'desc';
    options: { categories: string[]; urgencies: string[] };
    canSeeAll: boolean;
    hasAny: boolean;
}>();

const { t } = useT();
const search = ref(props.filters.q ?? '');
const filterCount = computed(() => Object.keys(props.filters).length);
const base = routes.help;

function visit(next: Filters & { sort?: string; dir?: string; page?: number }): void {
    const query: Record<string, string | number> = {};
    const merged = { ...props.filters, q: search.value.trim() || undefined, ...next };

    for (const [key, value] of Object.entries(merged)) {
        if (value !== undefined && value !== null && value !== '') {
            query[key] = value as string | number;
        }
    }

    router.get(`${base}/tickets`, query, { preserveState: true, preserveScroll: true, replace: true });
}

let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);

    if (value.trim() === (props.filters.q ?? '')) {
        return;
    }

    timer = setTimeout(() => visit({ q: value.trim() || undefined, page: 1 }), 350);
});
onBeforeUnmount(() => clearTimeout(timer));

function sortBy(column: 'created' | 'updated'): void {
    visit({ sort: column, dir: props.sort === column && props.dir === 'desc' ? 'asc' : 'desc', page: 1 });
}

const ariaSort = (column: 'created' | 'updated'): 'ascending' | 'descending' | 'none' =>
    props.sort === column ? (props.dir === 'asc' ? 'ascending' : 'descending') : 'none';

function clear(): void {
    search.value = '';
    router.get(`${base}/tickets`, {}, { preserveState: true, replace: true });
}

const statusOptions = computed(() => [
    { value: '', label: t('support.tickets.status_all') },
    ...STATUSES.filter((status) => status !== 'resolved' && status !== 'closed').map((status) => ({ value: status, label: t(`support.statuses.${status}`) })),
    { value: 'done', label: t('support.tickets.status_done') },
]);
const categoryOptions = computed(() => [
    { value: '', label: t('support.tickets.category_all') },
    ...props.options.categories.map((key) => ({ value: key, label: t(`support.categories.${key}`) })),
]);
const urgencyOptions = computed(() => [
    { value: '', label: t('support.tickets.urgency_all') },
    ...props.options.urgencies.map((key) => ({ value: key, label: t(`support.urgencies.${key}.label`) })),
]);
const scopeOptions = computed(() => [
    { value: '', label: t('support.tickets.scope_mine') },
    { value: 'all', label: t('support.tickets.scope_all') },
]);
</script>

<template>
    <AppLayout :title="t('support.tickets.title')">
        <HelpTabs />

        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl font-bold text-nova-900">{{ t('support.tickets.title') }}</h1>
                <p class="mt-1 text-neutral-700">{{ t('support.tickets.subtitle') }}</p>
                <p class="mt-1 text-sm text-neutral-600">{{ t('support.response_time') }}</p>
            </div>
            <Link :href="`${base}/contact`">
                <BaseButton>{{ t('support.tickets.new') }}</BaseButton>
            </Link>
        </header>

        <div v-if="!hasAny" class="mt-8 rounded-card border border-line bg-surface px-6 py-12 text-center shadow-xs">
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('support.tickets.empty_title') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-neutral-700">{{ t('support.tickets.empty_body') }}</p>
            <Link :href="`${base}/contact`" class="mt-5 inline-block">
                <BaseButton>{{ t('support.tickets.empty_cta') }}</BaseButton>
            </Link>
        </div>

        <template v-else>
            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="sm:col-span-2 lg:col-span-4">
                    <TextField
                        v-model="search"
                        type="text"
                        autocomplete="off"
                        :label="t('support.tickets.search')"
                        :hint="t('support.tickets.search_placeholder')"
                    />
                </div>
                <SelectField
                    :model-value="filters.status ?? ''"
                    :label="t('support.tickets.status')"
                    :options="statusOptions"
                    @update:model-value="(value) => visit({ status: value || undefined, page: 1 })"
                />
                <SelectField
                    :model-value="filters.category ?? ''"
                    :label="t('support.tickets.category')"
                    :options="categoryOptions"
                    @update:model-value="(value) => visit({ category: value || undefined, page: 1 })"
                />
                <SelectField
                    :model-value="filters.urgency ?? ''"
                    :label="t('support.tickets.urgency')"
                    :options="urgencyOptions"
                    @update:model-value="(value) => visit({ urgency: value || undefined, page: 1 })"
                />
                <SelectField
                    v-if="canSeeAll"
                    :model-value="filters.scope ?? ''"
                    :label="t('support.tickets.scope_mine')"
                    :options="scopeOptions"
                    @update:model-value="(value) => visit({ scope: value || undefined, page: 1 })"
                />
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="min-h-9 rounded-full border-[1.5px] px-3.5 text-sm font-semibold transition"
                    :class="
                        filters.attention
                            ? 'border-primary-600 bg-primary-50 text-primary-700'
                            : 'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600'
                    "
                    :aria-pressed="!!filters.attention"
                    @click="visit({ attention: filters.attention ? undefined : '1', page: 1 })"
                >
                    {{ t('support.tickets.attention') }}
                </button>
                <BaseButton v-if="filterCount" variant="ghost" size="sm" @click="clear">{{ t('support.tickets.clear') }}</BaseButton>
                <p class="ml-auto text-sm font-semibold text-neutral-700" aria-live="polite">{{ t('support.tickets.count', { count: pagination.total }) }}</p>
            </div>

            <div v-if="!tickets.length" class="mt-6 rounded-card border border-line bg-surface px-6 py-10 text-center shadow-xs">
                <h2 class="text-lg font-semibold text-neutral-900">{{ t('support.tickets.no_result_title') }}</h2>
                <p class="mt-2 text-neutral-700">{{ t('support.tickets.no_result_body') }}</p>
                <BaseButton variant="secondary" class="mt-4" @click="clear">{{ t('support.tickets.clear') }}</BaseButton>
            </div>

            <template v-else>
                <!-- Tableau sur écran large, cartes sur téléphone. -->
                <div class="mt-4 hidden overflow-x-auto rounded-card border border-line bg-surface shadow-xs md:block">
                    <table class="w-full min-w-[48rem] border-collapse text-left text-sm">
                        <thead>
                            <tr class="border-b border-line bg-surface-2 text-xs uppercase tracking-wide text-neutral-700">
                                <th scope="col" class="px-4 py-3">{{ t('support.tickets.reference') }}</th>
                                <th scope="col" class="px-3 py-3">{{ t('support.tickets.subject') }}</th>
                                <th scope="col" class="px-3 py-3">{{ t('support.tickets.status') }}</th>
                                <th scope="col" class="px-3 py-3">{{ t('support.tickets.category') }}</th>
                                <th scope="col" class="px-3 py-3">{{ t('support.tickets.urgency') }}</th>
                                <th scope="col" class="px-3 py-3" :aria-sort="ariaSort('created')">
                                    <button
                                        type="button"
                                        class="font-semibold uppercase"
                                        :aria-label="t('support.tickets.sort_by', { column: t('support.tickets.created') })"
                                        @click="sortBy('created')"
                                    >
                                        {{ t('support.tickets.created')
                                        }}<span aria-hidden="true">{{ sort === 'created' ? (dir === 'asc' ? ' ↑' : ' ↓') : '' }}</span>
                                    </button>
                                </th>
                                <th scope="col" class="px-3 py-3" :aria-sort="ariaSort('updated')">
                                    <button
                                        type="button"
                                        class="font-semibold uppercase"
                                        :aria-label="t('support.tickets.sort_by', { column: t('support.tickets.updated') })"
                                        @click="sortBy('updated')"
                                    >
                                        {{ t('support.tickets.updated')
                                        }}<span aria-hidden="true">{{ sort === 'updated' ? (dir === 'asc' ? ' ↑' : ' ↓') : '' }}</span>
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="row in tickets" :key="row.reference" class="align-middle hover:bg-surface-2">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <Link :href="`${base}/tickets/${row.reference}`" class="font-semibold text-primary-700 underline underline-offset-4">
                                        #{{ row.reference }}
                                    </Link>
                                </td>
                                <td class="max-w-xs px-3 py-3">
                                    <span class="flex items-center gap-2">
                                        <span v-if="row.unread" class="size-2 shrink-0 rounded-full bg-primary-600" aria-hidden="true" />
                                        <span class="truncate" :class="row.unread ? 'font-bold text-neutral-900' : 'text-neutral-900'">{{ row.subject }}</span>
                                        <span v-if="row.unread" class="sr-only">{{ t('support.tickets.new_reply') }}</span>
                                    </span>
                                    <span v-if="!row.mine" class="block text-xs text-neutral-600">{{ t('support.tickets.opened_by_colleague') }}</span>
                                </td>
                                <td class="px-3 py-3"><TicketStatusBadge :status="row.status" /></td>
                                <td class="px-3 py-3">{{ t(`support.categories.${row.category}`) }}</td>
                                <td class="px-3 py-3">
                                    <Badge :variant="urgencyVariant(row.urgency)">{{ t(`support.urgencies.${row.urgency}.label`) }}</Badge>
                                </td>
                                <td class="whitespace-nowrap px-3 py-3">{{ formatDate(row.created_at) }}</td>
                                <td class="whitespace-nowrap px-3 py-3">{{ formatDateTime(row.last_message_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <ul class="mt-4 space-y-3 md:hidden">
                    <li v-for="row in tickets" :key="row.reference">
                        <Link :href="`${base}/tickets/${row.reference}`" class="block rounded-card border border-line bg-surface p-4 shadow-xs">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-primary-700">#{{ row.reference }}</span>
                                <TicketStatusBadge :status="row.status" />
                            </span>
                            <span class="mt-1 block" :class="row.unread ? 'font-bold' : 'font-semibold'"
                                >{{ row.subject }}<span v-if="row.unread" class="sr-only"> — {{ t('support.tickets.new_reply') }}</span></span
                            >
                            <span class="mt-1 block text-sm text-neutral-700"
                                >{{ t(`support.categories.${row.category}`) }} · {{ formatDateTime(row.last_message_at) }}</span
                            >
                        </Link>
                    </li>
                </ul>

                <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center gap-3" :aria-label="t('support.tickets.title')">
                    <BaseButton variant="secondary" :disabled="pagination.page <= 1" @click="visit({ page: pagination.page - 1 })">
                        {{ t('customers.previous') }}
                    </BaseButton>
                    <span class="text-sm text-neutral-700">{{ t('customers.page', { page: pagination.page, total: pagination.last_page }) }}</span>
                    <BaseButton variant="secondary" :disabled="pagination.page >= pagination.last_page" @click="visit({ page: pagination.page + 1 })">
                        {{ t('customers.next') }}
                    </BaseButton>
                </nav>
            </template>

            <details class="mt-8 rounded-card border border-line bg-surface p-4">
                <summary class="cursor-pointer font-semibold text-neutral-900">{{ t('support.tickets.statuses_title') }}</summary>
                <dl class="mt-3 space-y-2 text-sm">
                    <div v-for="status in STATUSES" :key="status" class="flex flex-wrap items-start gap-x-3 gap-y-1">
                        <dt class="w-40 shrink-0"><TicketStatusBadge :status="status" /></dt>
                        <dd class="min-w-0 flex-1 text-neutral-700">{{ t(`support.status_help.${status}`) }}</dd>
                    </div>
                </dl>
            </details>
        </template>
    </AppLayout>
</template>
