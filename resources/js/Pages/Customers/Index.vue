<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import CustomerFilters from '../../Components/CustomerFilters.vue';
import CustomersTabs from '../../Components/CustomersTabs.vue';
import PractitionerAvatar from '../../Components/PractitionerAvatar.vue';
import TextField from '../../Components/TextField.vue';
import {
    ALL_COLUMNS,
    type ColumnKey,
    type Filters,
    SORTABLE,
    STARTER_VIEWS,
    type StarterView,
    activeFilterCount,
    buildQuery,
    initials,
    matchingView,
    normalizeColumns,
} from '../../composables/customerList';
import { formatDate } from '../../composables/dates';
import { formatPrice } from '../../composables/money';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface Row {
    id: string;
    name: string | null;
    reference: string;
    phone: string;
    email: string | null;
    status: 'active' | 'archived' | 'blocked';
    tags: string[];
    source: string | null;
    whatsapp: 'opted_in' | 'opted_out' | 'unknown';
    last: { at: string | null; service: string | null; status: string } | null;
    next: { at: string | null; service: string | null; practitioner: string | null; practitioner_id: string | null; practitioner_photo: number | null } | null;
    visits: number;
    no_shows: number;
    value_cents: number;
    created_at: string | null;
}

interface SavedView {
    id: string;
    name: string;
    filters: Filters;
    columns: string[] | null;
    sort: string | null;
    dir: 'asc' | 'desc' | null;
    shared: boolean;
    mine: boolean;
}

const props = defineProps<{
    q: string;
    filters: Filters;
    sort: string;
    dir: 'asc' | 'desc';
    customers: Row[];
    pagination: { page: number; last_page: number; total: number };
    views: SavedView[];
    practitioners: { value: string; label: string; photo_version: number | null }[];
    tags: string[];
    can: { tag: boolean; archive: boolean; share_views: boolean };
    scoped: boolean;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();

const STORAGE_KEY = 'nova.customers.columns';
const search = ref(props.q);
const showFilters = ref(false);
const loading = ref(false);
const columns = ref<ColumnKey[]>(readColumns());
const showColumns = ref(false);
const selected = ref<string[]>([]);
const tagInput = ref('');
const tagMode = ref<'tag' | 'untag' | null>(null);
const confirmArchive = ref<'archive' | 'unarchive' | null>(null);
const saving = ref(false);
const viewName = ref('');
const viewShared = ref(false);
const bulkBusy = ref(false);

function readColumns(): ColumnKey[] {
    try {
        return normalizeColumns(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null'));
    } catch {
        return normalizeColumns(null);
    }
}

function storeColumns(): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(columns.value));
    } catch {
        // Stockage indisponible (navigation privée) : le choix vaut pour cette page seulement.
    }
}

const filterCount = computed(() => activeFilterCount(props.filters));
const hasCriteria = computed(() => filterCount.value > 0 || props.q !== '');
const currentView = computed(() => matchingView({ q: props.q, filters: props.filters }, STARTER_VIEWS, props.views));
const allSelected = computed(() => props.customers.length > 0 && props.customers.every((row) => selected.value.includes(row.id)));
const selectedRows = computed(() => props.customers.filter((row) => selected.value.includes(row.id)));
const can = computed(() => props.can);

function visit(next: Partial<{ q: string; filters: Filters; sort: string; dir: string; page: number }> = {}): void {
    const query = buildQuery({
        q: next.q ?? search.value,
        filters: next.filters ?? props.filters,
        sort: next.sort ?? props.sort,
        dir: next.dir ?? props.dir,
        page: next.page,
    });

    router.get(routes.customers, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

// Recherche pendant la frappe : dès 2 caractères, ou quand le champ est vidé ; une courte pause évite une requête par lettre.
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);

    if (value.trim() === props.q.trim() || value.trim().length === 1) {
        return;
    }

    timer = setTimeout(() => visit({ q: value, page: 1 }), 300);
});
onBeforeUnmount(() => clearTimeout(timer));

watch(
    () => props.customers,
    () => (selected.value = []),
);

function sortBy(key: string): void {
    visit({ sort: key, dir: props.sort === key && props.dir === 'asc' ? 'desc' : 'asc', page: 1 });
}

const ariaSort = (key: string | undefined): 'ascending' | 'descending' | 'none' | undefined =>
    key === undefined ? undefined : props.sort === key ? (props.dir === 'asc' ? 'ascending' : 'descending') : 'none';

function applyStarter(view: StarterView): void {
    search.value = '';
    visit({ q: '', filters: view.filters, sort: view.sort ?? 'name', dir: view.dir ?? 'asc', page: 1 });
}

function applySaved(view: SavedView): void {
    if (view.columns) {
        columns.value = normalizeColumns(view.columns);
        storeColumns();
    }

    search.value = '';
    visit({ q: '', filters: view.filters, sort: view.sort ?? 'name', dir: view.dir ?? 'asc', page: 1 });
}

function clearAll(): void {
    search.value = '';
    visit({ q: '', filters: {}, page: 1 });
}

function toggleAll(): void {
    selected.value = allSelected.value ? [] : props.customers.map((row) => row.id);
}

function toggleColumn(key: ColumnKey): void {
    columns.value = columns.value.includes(key) ? columns.value.filter((c) => c !== key) : ALL_COLUMNS.filter((c) => c === key || columns.value.includes(c));
    storeColumns();
}

function bulk(action: 'tag' | 'untag' | 'archive' | 'unarchive'): void {
    bulkBusy.value = true;
    router.post(
        `${routes.customers}/actions`,
        { action, ids: selected.value, tag: tagInput.value.trim() || undefined },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = [];
                tagMode.value = null;
                tagInput.value = '';
                confirmArchive.value = null;
            },
            onFinish: () => (bulkBusy.value = false),
        },
    );
}

function saveView(): void {
    router.post(
        `${routes.customers}/vues`,
        { name: viewName.value, filters: props.filters, columns: columns.value, sort: props.sort, dir: props.dir, shared: viewShared.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                saving.value = false;
                viewName.value = '';
                viewShared.value = false;
            },
        },
    );
}

function deleteView(view: SavedView): void {
    router.delete(`${routes.customers}/vues/${view.id}`, { preserveScroll: true });
}

const money = (cents: number): string => formatPrice(cents, locale.value);
const day = (at: string | null): string => (at === null ? '' : formatDate(at.slice(0, 10)));
const visibleColumns = computed(() => ALL_COLUMNS.filter((key) => columns.value.includes(key)));
const statusVariant = (status: Row['status']): 'success' | 'danger' | 'neutral' =>
    status === 'active' ? 'success' : status === 'blocked' ? 'danger' : 'neutral';
const consentVariant = (state: Row['whatsapp']): 'success' | 'danger' | 'neutral' =>
    state === 'opted_in' ? 'success' : state === 'opted_out' ? 'danger' : 'neutral';
</script>

<template>
    <AppLayout :title="t('customers.title')">
        <CustomersTabs active="clients" />
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-nova-900">{{ t('customers.title') }}</h1>
                <p class="mt-1 text-sm text-neutral-700">{{ t('customers.subtitle') }}</p>
            </div>
            <p class="text-sm font-semibold text-neutral-700" aria-live="polite">{{ t('customers.count', { count: pagination.total }) }}</p>
        </header>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="scoped" variant="info" class="mt-4">{{ t('customers.scoped') }}</AlertBox>

        <div class="mt-5 flex flex-wrap items-end gap-3">
            <form class="min-w-64 flex-1 sm:max-w-md" role="search" @submit.prevent="visit({ q: search, page: 1 })">
                <TextField v-model="search" :label="t('customers.search_placeholder')" type="text" autocomplete="off" />
            </form>
            <BaseButton variant="secondary" :aria-expanded="showFilters" @click="showFilters = !showFilters">
                {{ t('customers.filters.button')
                }}<span v-if="filterCount" class="ml-1 rounded-full bg-primary-600 px-2 text-xs text-white">{{ filterCount }}</span>
            </BaseButton>
            <div class="relative">
                <BaseButton variant="secondary" :aria-expanded="showColumns" @click="showColumns = !showColumns">
                    {{ t('customers.columns.button') }}
                </BaseButton>
                <div
                    v-if="showColumns"
                    class="absolute right-0 z-20 mt-2 w-60 rounded-card border border-line bg-surface p-4 shadow-md"
                    role="group"
                    :aria-label="t('customers.columns.title')"
                >
                    <p class="mb-2 text-sm font-semibold text-neutral-900">{{ t('customers.columns.title') }}</p>
                    <div class="flex flex-col gap-2">
                        <CheckboxField
                            v-for="key in ALL_COLUMNS"
                            :key="key"
                            :model-value="columns.includes(key)"
                            :label="t(`customers.columns.${key}`)"
                            @update:model-value="toggleColumn(key)"
                        />
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-2 text-sm text-neutral-700">{{ t('customers.search_hint') }}</p>

        <div class="mt-4 flex flex-wrap items-center gap-2" role="group" :aria-label="t('customers.views.title')">
            <button
                v-for="view in STARTER_VIEWS"
                :key="view.key"
                type="button"
                class="min-h-9 rounded-full border-[1.5px] px-3.5 text-sm font-semibold transition"
                :class="
                    currentView === `starter:${view.key}`
                        ? 'border-primary-600 bg-primary-50 text-primary-700'
                        : 'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600'
                "
                :aria-pressed="currentView === `starter:${view.key}`"
                @click="applyStarter(view)"
            >
                {{ t(`customers.views.${view.key}`) }}
            </button>
            <span v-for="view in views" :key="view.id" class="inline-flex items-center">
                <button
                    type="button"
                    class="min-h-9 rounded-l-full border-[1.5px] py-0 pl-3.5 pr-3 text-sm font-semibold transition"
                    :class="[
                        view.mine ? 'rounded-r-none' : 'rounded-r-full',
                        currentView === `saved:${view.id}`
                            ? 'border-primary-600 bg-primary-50 text-primary-700'
                            : 'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600',
                    ]"
                    :aria-pressed="currentView === `saved:${view.id}`"
                    @click="applySaved(view)"
                >
                    {{ view.name }}<span v-if="view.shared" class="ml-1.5 text-xs font-normal text-neutral-700">· {{ t('customers.views.shared') }}</span>
                </button>
                <button
                    v-if="view.mine"
                    type="button"
                    class="min-h-9 rounded-r-full border-[1.5px] border-l-0 border-neutral-300 bg-surface px-2.5 text-neutral-700 hover:border-danger-fg hover:text-danger-fg"
                    :aria-label="t('customers.views.delete', { name: view.name })"
                    @click="deleteView(view)"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </span>
            <BaseButton v-if="hasCriteria && !saving" variant="ghost" size="sm" @click="saving = true">{{ t('customers.views.save') }}</BaseButton>
        </div>

        <form v-if="saving" class="mt-3 flex max-w-xl flex-wrap items-end gap-3 rounded-card border border-line bg-surface p-4" @submit.prevent="saveView">
            <div class="min-w-48 flex-1"><TextField v-model="viewName" :label="t('customers.views.name')" :maxlength="60" required /></div>
            <CheckboxField v-if="can.share_views" v-model="viewShared" :label="t('customers.views.share')" />
            <BaseButton type="submit" :disabled="viewName.trim() === ''">{{ t('customers.views.confirm') }}</BaseButton>
        </form>

        <div v-if="showFilters" class="mt-4">
            <CustomerFilters
                :filters="filters"
                :practitioners="practitioners"
                :tags="tags"
                @apply="(f) => visit({ filters: f, page: 1 })"
                @close="showFilters = false"
            />
        </div>

        <div
            v-if="selected.length"
            class="sticky top-2 z-10 mt-4 flex flex-wrap items-center gap-3 rounded-card border border-primary-600 bg-primary-50 px-4 py-3"
            role="region"
            :aria-label="t('customers.bulk.selected', { count: selected.length })"
        >
            <span class="font-semibold text-primary-700">{{ t('customers.bulk.selected', { count: selected.length }) }}</span>
            <template v-if="can.tag">
                <BaseButton variant="secondary" size="sm" @click="tagMode = 'tag'">{{ t('customers.bulk.add_tag') }}</BaseButton>
                <BaseButton variant="secondary" size="sm" @click="tagMode = 'untag'">{{ t('customers.bulk.remove_tag') }}</BaseButton>
            </template>
            <template v-if="can.archive">
                <BaseButton v-if="selectedRows.some((r) => r.status === 'active')" variant="secondary" size="sm" @click="confirmArchive = 'archive'">
                    {{ t('customers.bulk.archive') }}
                </BaseButton>
                <BaseButton v-if="selectedRows.some((r) => r.status === 'archived')" variant="secondary" size="sm" @click="confirmArchive = 'unarchive'">
                    {{ t('customers.bulk.unarchive') }}
                </BaseButton>
            </template>
            <BaseButton variant="ghost" size="sm" @click="selected = []">{{ t('customers.bulk.clear') }}</BaseButton>

            <form v-if="tagMode" class="flex w-full flex-wrap items-end gap-3" @submit.prevent="bulk(tagMode)">
                <div class="min-w-48"><TextField v-model="tagInput" :label="t('customers.bulk.tag_label')" :maxlength="30" :suggestions="tags" required /></div>
                <BaseButton type="submit" size="sm" :loading="bulkBusy" :disabled="tagInput.trim() === ''">{{ t('customers.bulk.tag_apply') }}</BaseButton>
            </form>
        </div>

        <div v-if="!customers.length" class="mt-6 rounded-card border border-line bg-surface px-6 py-12 text-center shadow-xs">
            <template v-if="hasCriteria">
                <h2 class="text-lg font-semibold text-neutral-900">{{ t('customers.no_result_title') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-neutral-700">{{ t('customers.no_result_body') }}</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <BaseButton v-if="q" variant="secondary" @click="search = ''">{{ t('customers.clear_search') }}</BaseButton>
                    <BaseButton v-if="filterCount" variant="ghost" @click="clearAll">{{ t('customers.clear_filters') }}</BaseButton>
                </div>
            </template>
            <template v-else>
                <h2 class="text-lg font-semibold text-neutral-900">{{ t('customers.empty_title') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-neutral-700">{{ t('customers.empty_body') }}</p>
                <div class="mt-5">
                    <Link :href="routes.whatsappQr" class="font-semibold text-primary-700 underline">{{ t('customers.empty_qr') }}</Link>
                </div>
            </template>
        </div>

        <div v-else class="mt-5 overflow-x-auto rounded-card border border-line bg-surface shadow-xs" :aria-busy="loading" :class="{ 'opacity-60': loading }">
            <p v-if="loading" class="sr-only" role="status">{{ t('customers.loading') }}</p>
            <table class="w-full min-w-[56rem] border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b border-line bg-surface-2 text-xs uppercase tracking-wide text-neutral-700">
                        <th scope="col" class="w-10 px-4 py-3">
                            <input type="checkbox" class="nova-check" :checked="allSelected" :aria-label="t('customers.select_all')" @change="toggleAll" />
                        </th>
                        <th scope="col" class="px-3 py-3" :aria-sort="ariaSort('name')">
                            <button
                                type="button"
                                class="font-semibold uppercase"
                                :aria-label="t('customers.sort_by', { column: t('customers.columns.customer') })"
                                @click="sortBy('name')"
                            >
                                {{ t('customers.columns.customer') }}<span aria-hidden="true">{{ sort === 'name' ? (dir === 'asc' ? ' ↑' : ' ↓') : '' }}</span>
                            </button>
                        </th>
                        <th v-for="key in visibleColumns" :key="key" scope="col" class="px-3 py-3" :aria-sort="ariaSort(SORTABLE[key])">
                            <button
                                v-if="SORTABLE[key]"
                                type="button"
                                class="font-semibold uppercase"
                                :aria-label="t('customers.sort_by', { column: t(`customers.columns.${key}`) })"
                                @click="sortBy(SORTABLE[key] as string)"
                            >
                                {{ t(`customers.columns.${key}`)
                                }}<span aria-hidden="true">{{ sort === SORTABLE[key] ? (dir === 'asc' ? ' ↑' : ' ↓') : '' }}</span>
                            </button>
                            <span v-else class="font-semibold">{{ t(`customers.columns.${key}`) }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr
                        v-for="row in customers"
                        :key="row.id"
                        class="align-middle hover:bg-surface-2"
                        :class="{ 'bg-primary-50/50': selected.includes(row.id) }"
                    >
                        <td class="px-4 py-3">
                            <input
                                v-model="selected"
                                type="checkbox"
                                class="nova-check"
                                :value="row.id"
                                :aria-label="t('customers.select_row', { name: row.name ?? row.phone })"
                            />
                        </td>
                        <td class="px-3 py-3">
                            <Link :href="`${routes.customers}/${row.id}`" class="flex items-center gap-3">
                                <span
                                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-mint text-xs font-bold text-primary-700"
                                    aria-hidden="true"
                                    >{{ initials(row.name, row.phone) }}</span
                                >
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold text-neutral-900">{{ row.name ?? t('customers.unnamed') }}</span>
                                    <span class="block text-xs text-neutral-700">{{ row.reference }}</span>
                                </span>
                            </Link>
                        </td>
                        <td v-for="key in visibleColumns" :key="key" class="px-3 py-3 text-neutral-900">
                            <template v-if="key === 'contact'">
                                <span class="block">{{ row.phone }}</span>
                                <span v-if="row.email" class="block max-w-52 truncate text-xs text-neutral-700">{{ row.email }}</span>
                            </template>
                            <template v-else-if="key === 'last'">
                                <template v-if="row.last">
                                    <span class="block">{{ day(row.last.at) }}</span>
                                    <span class="block max-w-44 truncate text-xs text-neutral-700"
                                        >{{ row.last.service
                                        }}<template v-if="row.last.status === 'no_show'"> · {{ t('customers.status.no_show') }}</template></span
                                    >
                                </template>
                                <span v-else class="text-neutral-700">{{ t('customers.never') }}</span>
                            </template>
                            <template v-else-if="key === 'next'">
                                <template v-if="row.next">
                                    <span class="block">{{ day(row.next.at) }}</span>
                                    <span class="block max-w-44 truncate text-xs text-neutral-700"
                                        >{{ row.next.service
                                        }}<template v-if="row.next.practitioner">
                                            ·
                                            <PractitionerAvatar
                                                v-if="row.next.practitioner_id"
                                                :id="row.next.practitioner_id"
                                                :name="row.next.practitioner"
                                                :version="row.next.practitioner_photo"
                                                size="xs"
                                                class="align-middle"
                                            />
                                            {{ row.next.practitioner }}
                                        </template></span
                                    >
                                </template>
                                <span v-else class="text-neutral-700">—</span>
                            </template>
                            <template v-else-if="key === 'visits'">
                                <span class="font-semibold">{{ row.visits }}</span>
                            </template>
                            <template v-else-if="key === 'value'">
                                <span class="font-semibold">{{ money(row.value_cents) }}</span>
                            </template>
                            <template v-else-if="key === 'whatsapp'">
                                <Badge :variant="consentVariant(row.whatsapp)">{{ t(`customers.whatsapp_state.${row.whatsapp}`) }}</Badge>
                            </template>
                            <template v-else-if="key === 'tags'">
                                <span class="flex flex-wrap gap-1">
                                    <span
                                        v-for="tag in row.tags.slice(0, 2)"
                                        :key="tag"
                                        class="rounded-full bg-neutral-bg px-2.5 py-0.5 text-xs font-semibold text-neutral-fg"
                                        >{{ tag }}</span
                                    >
                                    <span v-if="row.tags.length > 2" class="text-xs text-neutral-700">+{{ row.tags.length - 2 }}</span>
                                </span>
                            </template>
                            <template v-else-if="key === 'status'">
                                <Badge :variant="statusVariant(row.status)">{{ t(`customers.status_label.${row.status}`) }}</Badge>
                            </template>
                            <template v-else-if="key === 'source'">{{ row.source ? t(`customers.source_label.${row.source}`) : '—' }}</template>
                            <template v-else-if="key === 'created'">{{ row.created_at ? formatDate(row.created_at) : '—' }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="customers.length && columns.includes('value')" class="mt-2 text-xs text-neutral-700">{{ t('customers.value_hint') }}</p>

        <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center gap-3" :aria-label="t('customers.title')">
            <BaseButton variant="secondary" :disabled="pagination.page <= 1" @click="visit({ page: pagination.page - 1 })">
                {{ t('customers.previous') }}
            </BaseButton>
            <span class="text-sm text-neutral-700">{{ t('customers.page', { page: pagination.page, total: pagination.last_page }) }}</span>
            <BaseButton variant="secondary" :disabled="pagination.page >= pagination.last_page" @click="visit({ page: pagination.page + 1 })">
                {{ t('customers.next') }}
            </BaseButton>
        </nav>

        <ConfirmDialog
            :open="confirmArchive === 'archive'"
            :title="t('customers.bulk.archive_title', { count: selected.length })"
            :body="t('customers.bulk.archive_body')"
            :confirm-label="t('customers.bulk.archive')"
            :loading="bulkBusy"
            @confirm="bulk('archive')"
            @cancel="confirmArchive = null"
        />
        <ConfirmDialog
            :open="confirmArchive === 'unarchive'"
            :title="t('customers.bulk.unarchive')"
            :body="t('customers.bulk.selected', { count: selected.length })"
            :confirm-label="t('customers.bulk.unarchive')"
            :loading="bulkBusy"
            @confirm="bulk('unarchive')"
            @cancel="confirmArchive = null"
        />
    </AppLayout>
</template>
