<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ModalDialog from '../../Components/ModalDialog.vue';
import { centsToInput, formatPrice } from '../../composables/money';
import { needsCheck, parsePriceInput, summarize, type ReviewRow } from '../../composables/priceImport';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface Line {
    key: string;
    name: string;
    price_cents: number | null;
    category: string | null;
    duration_minutes: number;
    duration_guessed: boolean;
    vat_rate: number | null;
    unit: string | null;
    confidence: number;
    warnings: string[];
    ignored_reason: string | null;
    match: { service_id: string; name: string; score: number } | null;
    suggestion: { service_id: string; name: string; score: number } | null;
    action: 'new' | 'update' | 'ignore';
}

interface ExistingService {
    id: string;
    name: string;
    price_cents: number;
    has_category: boolean;
}

const props = defineProps<{
    importRecord: { id: string; file_names: string[] };
    lines: Line[];
    services: ExistingService[];
    categories: string[];
    vatRates: number[];
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const base = `${routes.servicesImport}/${props.importRecord.id}`;

const rows = reactive<ReviewRow[]>(
    props.lines.map((line) => ({
        key: line.key,
        name: line.name,
        price: line.price_cents === null ? '' : centsToInput(line.price_cents, locale.value),
        category: line.category ?? '',
        duration: String(line.duration_minutes),
        durationGuessed: line.duration_guessed,
        vat: line.vat_rate,
        action: line.action,
        serviceId: line.match?.service_id ?? null,
        warnings: line.warnings,
        ignoredReason: line.ignored_reason,
        suggestion: line.suggestion,
        selected: false,
    })),
);

const serviceById = computed(() => new Map(props.services.map((service) => [service.id, service])));
const summary = computed(() => summarize(rows));
const allSelected = computed(() => rows.length > 0 && rows.every((row) => row.selected));
const selectedCount = computed(() => rows.filter((row) => row.selected).length);
const bulkCategory = ref('');
const bulkDuration = ref('');
const confirming = ref(false);
const accepted = ref(false);
const cancelling = ref(false);
type LinePayload = {
    key: string;
    action: string;
    name?: string;
    price_cents?: number | null;
    category?: string | null;
    duration_minutes?: number;
    vat_rate?: number | null;
    service_id?: string | null;
};
const form = useForm<{ confirm: boolean; lines: LinePayload[] }>({ confirm: false, lines: [] });

function rowTarget(row: ReviewRow): string {
    return row.action === 'update' && row.serviceId !== null ? `update:${row.serviceId}` : row.action;
}

function setTarget(row: ReviewRow, value: string): void {
    if (value.startsWith('update:')) {
        row.action = 'update';
        row.serviceId = value.slice(7);
    } else {
        row.action = value === 'ignore' ? 'ignore' : 'new';
        row.serviceId = null;
    }
}

function useSuggestion(row: ReviewRow): void {
    if (row.suggestion) {
        row.action = 'update';
        row.serviceId = row.suggestion.service_id;
    }
}

function toggleAll(): void {
    const next = !allSelected.value;
    rows.forEach((row) => (row.selected = next));
}

function applyCategory(): void {
    rows.filter((row) => row.selected).forEach((row) => (row.category = bulkCategory.value.trim()));
}

function applyDuration(): void {
    if (Number(bulkDuration.value) >= 5 && Number(bulkDuration.value) <= 480) {
        rows.filter((row) => row.selected).forEach((row) => {
            row.duration = String(Math.round(Number(bulkDuration.value)));
            row.durationGuessed = false;
        });
    }
}

function ignoreSelected(): void {
    rows.filter((row) => row.selected).forEach((row) => {
        row.action = 'ignore';
        row.serviceId = null;
    });
}

function deleteSelected(): void {
    for (let index = rows.length - 1; index >= 0; index--) {
        if (rows[index]!.selected) {
            rows.splice(index, 1);
        }
    }
}

function oldPrice(row: ReviewRow): string | null {
    const service = row.serviceId === null ? undefined : serviceById.value.get(row.serviceId);

    return service === undefined ? null : formatPrice(service.price_cents, locale.value);
}

function newPrice(row: ReviewRow): string | null {
    const cents = parsePriceInput(row.price);

    return cents === null ? null : formatPrice(cents, locale.value);
}

function fieldInvalid(row: ReviewRow, field: 'name' | 'price' | 'duration'): boolean {
    if (row.action === 'ignore') {
        return false;
    }

    if (field === 'name') {
        return row.action === 'new' && row.name.trim() === '';
    }

    if (field === 'price') {
        return parsePriceInput(row.price) === null;
    }

    return row.action === 'new' && !(Number(row.duration) >= 5 && Number(row.duration) <= 480);
}

const invalid = computed(() => rows.filter((row) => fieldInvalid(row, 'name') || fieldInvalid(row, 'price') || fieldInvalid(row, 'duration')).length);
const importable = computed(() => summary.value.added + summary.value.updated);

function review(): void {
    accepted.value = false;
    confirming.value = true;
}

function submit(): void {
    form.confirm = accepted.value;
    form.lines = rows.map((row) =>
        row.action === 'ignore'
            ? { key: row.key, action: 'ignore' }
            : {
                  key: row.key,
                  action: row.action,
                  name: row.name.trim(),
                  price_cents: parsePriceInput(row.price),
                  category: row.category.trim() === '' ? null : row.category.trim(),
                  duration_minutes: Number(row.duration),
                  vat_rate: row.vat,
                  service_id: row.action === 'update' ? row.serviceId : null,
              },
    );
    form.post(`${base}/apply`, { onFinish: () => (confirming.value = false) });
}

function cancel(): void {
    cancelling.value = true;
    router.delete(base, { onFinish: () => (cancelling.value = false) });
}
</script>

<template>
    <AppLayout :title="t('price_import.review_title')">
        <nav class="mb-3 text-sm text-neutral-600" :aria-label="t('setup.breadcrumb')">
            <Link :href="routes.servicesImport" class="hover:text-primary-700">{{ t('price_import.title') }}</Link>
            <span aria-hidden="true"> › </span>
            <span class="font-semibold text-neutral-900" aria-current="page">{{ t('price_import.review_title') }}</span>
        </nav>

        <h1 class="text-2xl font-semibold text-nova-900">{{ t('price_import.review_title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm text-neutral-700">
            {{ t('price_import.review_intro', { lines: lines.length, pages: importRecord.file_names.length }) }}
        </p>

        <AlertBox v-if="summary.toCheck > 0" variant="warning" class="mt-4 max-w-5xl">{{ t('price_import.to_check', { count: summary.toCheck }) }}</AlertBox>
        <AlertBox v-if="errors.lines || errors.confirm" variant="error" class="mt-4 max-w-5xl">{{ errors.lines ?? errors.confirm }}</AlertBox>

        <div
            class="mt-4 flex max-w-6xl flex-wrap items-center justify-between gap-3 rounded-card border border-neutral-200 bg-white px-4 py-3"
            aria-live="polite"
        >
            <p class="flex flex-wrap gap-2 text-sm font-semibold">
                <span class="rounded-full bg-success-50 px-3 py-1 text-success-600">{{ t('price_import.sum_new', { count: summary.added }) }}</span>
                <span class="rounded-full bg-primary-50 px-3 py-1 text-primary-700">{{ t('price_import.sum_update', { count: summary.updated }) }}</span>
                <span class="rounded-full border border-neutral-200 bg-neutral-50 px-3 py-1 text-neutral-600">{{
                    t('price_import.sum_ignored', { count: summary.ignored })
                }}</span>
                <span v-if="summary.toCheck > 0" class="rounded-full bg-warning-50 px-3 py-1 text-warning-600">{{
                    t('price_import.sum_check', { count: summary.toCheck })
                }}</span>
            </p>
            <div v-if="selectedCount > 0" class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-neutral-600">{{ t('price_import.selected', { count: selectedCount }) }}</span>
                <input
                    v-model="bulkCategory"
                    type="text"
                    :placeholder="t('price_import.category')"
                    maxlength="60"
                    :aria-label="t('price_import.category')"
                    class="min-h-9 w-32 rounded-lg border border-neutral-300 px-2"
                    list="price-import-categories"
                />
                <button
                    type="button"
                    class="min-h-9 rounded-lg border border-neutral-300 bg-white px-3 font-semibold hover:bg-neutral-100"
                    @click="applyCategory"
                >
                    {{ t('price_import.set_category') }}
                </button>
                <input
                    v-model="bulkDuration"
                    type="number"
                    min="5"
                    max="480"
                    :placeholder="t('price_import.minutes')"
                    :aria-label="t('price_import.duration')"
                    class="min-h-9 w-20 rounded-lg border border-neutral-300 px-2"
                />
                <button
                    type="button"
                    class="min-h-9 rounded-lg border border-neutral-300 bg-white px-3 font-semibold hover:bg-neutral-100"
                    @click="applyDuration"
                >
                    {{ t('price_import.set_duration') }}
                </button>
                <button
                    type="button"
                    class="min-h-9 rounded-lg border border-neutral-300 bg-white px-3 font-semibold hover:bg-neutral-100"
                    @click="ignoreSelected"
                >
                    {{ t('price_import.ignore') }}
                </button>
                <button
                    type="button"
                    class="min-h-9 rounded-lg border border-neutral-300 bg-white px-3 font-semibold hover:bg-neutral-100"
                    @click="deleteSelected"
                >
                    {{ t('price_import.delete') }}
                </button>
            </div>
        </div>
        <datalist id="price-import-categories"><option v-for="name in categories" :key="name" :value="name" /></datalist>

        <div class="mt-4 max-w-6xl overflow-x-auto rounded-card border border-neutral-200 bg-white">
            <table class="w-full min-w-[60rem] text-left text-sm">
                <caption class="sr-only">
                    {{
                        t('price_import.table')
                    }}
                </caption>
                <thead class="bg-neutral-50 text-xs uppercase tracking-wider text-neutral-600">
                    <tr>
                        <th scope="col" class="w-10 px-3 py-2">
                            <input type="checkbox" :checked="allSelected" :aria-label="t('price_import.select_all')" @change="toggleAll" />
                        </th>
                        <th scope="col" class="px-2 py-2">{{ t('price_import.service') }}</th>
                        <th scope="col" class="px-2 py-2">{{ t('price_import.category') }}</th>
                        <th scope="col" class="w-28 px-2 py-2">{{ t('price_import.duration') }}</th>
                        <th scope="col" class="w-28 px-2 py-2">{{ t('price_import.price') }}</th>
                        <th scope="col" class="w-72 px-2 py-2">{{ t('price_import.what_happens') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.key"
                        class="border-t border-neutral-200 align-top"
                        :class="[row.action === 'ignore' ? 'opacity-60' : '', needsCheck(row) ? 'bg-warning-50' : '']"
                    >
                        <td class="px-3 py-2">
                            <input v-model="row.selected" type="checkbox" :aria-label="t('price_import.select_line', { name: row.name })" />
                        </td>
                        <td class="px-2 py-2">
                            <input
                                v-model="row.name"
                                type="text"
                                maxlength="120"
                                :aria-label="t('price_import.service')"
                                :aria-invalid="fieldInvalid(row, 'name') || undefined"
                                class="min-h-10 w-full rounded-lg border bg-white px-2"
                                :class="[fieldInvalid(row, 'name') ? 'border-danger-600' : 'border-neutral-300', row.action === 'ignore' ? 'line-through' : '']"
                                :disabled="row.action === 'update'"
                            />
                        </td>
                        <td class="px-2 py-2">
                            <input
                                v-model="row.category"
                                type="text"
                                maxlength="60"
                                :aria-label="t('price_import.category')"
                                class="min-h-10 w-full rounded-lg border border-neutral-300 bg-white px-2"
                                list="price-import-categories"
                                :disabled="row.action === 'ignore'"
                            />
                        </td>
                        <td class="px-2 py-2">
                            <div class="flex items-center gap-1">
                                <input
                                    v-model="row.duration"
                                    type="number"
                                    min="5"
                                    max="480"
                                    :aria-label="t('price_import.duration')"
                                    :aria-invalid="fieldInvalid(row, 'duration') || undefined"
                                    class="min-h-10 w-16 rounded-lg border bg-white px-2"
                                    :class="
                                        fieldInvalid(row, 'duration')
                                            ? 'border-danger-600'
                                            : row.durationGuessed && row.action === 'new'
                                              ? 'border-warning-600'
                                              : 'border-neutral-300'
                                    "
                                    :disabled="row.action !== 'new'"
                                />
                                <span class="text-xs text-neutral-600">{{ t('price_import.minutes') }}</span>
                            </div>
                        </td>
                        <td class="px-2 py-2">
                            <input
                                v-model="row.price"
                                type="text"
                                inputmode="decimal"
                                :aria-label="t('price_import.price')"
                                :aria-invalid="fieldInvalid(row, 'price') || undefined"
                                class="min-h-10 w-24 rounded-lg border bg-white px-2"
                                :class="
                                    fieldInvalid(row, 'price')
                                        ? 'border-danger-600'
                                        : row.warnings.some((w) => w.startsWith('price'))
                                          ? 'border-warning-600'
                                          : 'border-neutral-300'
                                "
                                :disabled="row.action === 'ignore'"
                            />
                        </td>
                        <td class="px-2 py-2">
                            <select
                                :value="rowTarget(row)"
                                :aria-label="t('price_import.what_happens')"
                                class="min-h-10 w-full rounded-lg border border-neutral-300 bg-white px-2"
                                @change="setTarget(row, ($event.target as HTMLSelectElement).value)"
                            >
                                <option value="new">{{ t('price_import.actions.new') }}</option>
                                <optgroup :label="t('price_import.update_existing')">
                                    <option v-for="service in services" :key="service.id" :value="`update:${service.id}`">
                                        {{ t('price_import.actions.update_named', { name: service.name }) }}
                                    </option>
                                </optgroup>
                                <option value="ignore">{{ t('price_import.actions.ignore') }}</option>
                            </select>
                            <p v-if="row.action === 'update' && oldPrice(row)" class="mt-1 text-xs text-neutral-600">
                                {{ t('price_import.price_change', { old: oldPrice(row) ?? '', new: newPrice(row) ?? '–' }) }}
                            </p>
                            <p v-if="row.action === 'new' && row.suggestion" class="mt-1 text-xs text-neutral-700">
                                {{ t('price_import.looks_like', { name: row.suggestion.name, score: Math.round(row.suggestion.score * 100) }) }}
                                <button type="button" class="ml-1 font-semibold text-primary-700 underline" @click="useSuggestion(row)">
                                    {{ t('price_import.use') }}
                                </button>
                            </p>
                            <p v-if="row.action === 'ignore' && row.ignoredReason" class="mt-1 text-xs text-neutral-600">
                                {{ t(`price_import.ignored_reason.${row.ignoredReason}`) }}
                            </p>
                            <ul v-if="row.action !== 'ignore' && row.warnings.length > 0" class="mt-1 space-y-0.5 text-xs font-semibold text-warning-600">
                                <li v-for="warning in row.warnings" :key="warning">⚠ {{ t(`price_import.warn.${warning}`) }}</li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-6 flex max-w-6xl flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-neutral-600">{{ t('price_import.nothing_yet', { count: importable }) }}</p>
            <div class="flex flex-wrap gap-3">
                <BaseButton variant="secondary" :loading="cancelling" @click="cancel">{{ t('price_import.cancel_import') }}</BaseButton>
                <BaseButton :disabled="importable === 0 || invalid > 0" @click="review">{{ t('price_import.review_import') }}</BaseButton>
            </div>
        </div>
        <p v-if="invalid > 0" class="mt-2 text-sm font-medium text-danger-700" role="alert">{{ t('price_import.fix_lines', { count: invalid }) }}</p>

        <!-- Confirmation : ce qui va se passer, puis une case à cocher. -->
        <ModalDialog :open="confirming" :title="t('price_import.confirm_title')" @close="confirming = false">
            <p class="text-sm text-neutral-700">{{ t('price_import.confirm_about') }}</p>
            <ul class="mt-2 space-y-1 text-sm text-neutral-900">
                <li>
                    <strong>{{ summary.added }}</strong> {{ t('price_import.confirm_new') }}
                </li>
                <li>
                    <strong>{{ summary.updated }}</strong> {{ t('price_import.confirm_update') }}
                </li>
                <li>
                    <strong>{{ summary.ignored }}</strong> {{ t('price_import.confirm_ignored') }}
                </li>
            </ul>
            <p class="mt-3 text-sm text-neutral-600">{{ t('price_import.confirm_prices_only') }}</p>
            <label class="mt-4 flex items-start gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
                <input v-model="accepted" type="checkbox" class="mt-0.5 size-4" />
                <span>{{ t('price_import.confirm_check') }}</span>
            </label>
            <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <BaseButton variant="secondary" @click="confirming = false">{{ t('price_import.back_to_list') }}</BaseButton>
                <BaseButton :disabled="!accepted" :loading="form.processing" @click="submit">
                    {{ t('price_import.import_n', { count: importable }) }}
                </BaseButton>
            </div>
        </ModalDialog>
    </AppLayout>
</template>
