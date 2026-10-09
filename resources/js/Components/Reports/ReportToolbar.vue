<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { formatDate } from '../../composables/dates';
import { activeFilterCount, filterQuery } from '../../composables/reports';
import { useT } from '../../composables/useT';
import { routes } from '../../routes';
import type { ReportAccessInfo, ReportFiltersValue, ReportOptions, ReportPeriod, ReportView } from '../../types/reports';
import BaseButton from '../BaseButton.vue';
import CheckboxField from '../CheckboxField.vue';
import SelectField from '../SelectField.vue';
import TextField from '../TextField.vue';

/**
 * Période, filtres communs, résumé de ce qui est affiché, et actions Exporter / Imprimer. Changer de période ou de filtre recharge
 * le rapport en gardant la catégorie ; changer de catégorie (onglets) garde période et filtres.
 */
const props = defineProps<{
    category: ReportView;
    filters: ReportFiltersValue;
    period: ReportPeriod;
    options: ReportOptions;
    access: ReportAccessInfo;
    /** Le filtre de statut s'applique-t-il à cette catégorie ? */
    statusApplies: boolean;
}>();
const emit = defineEmits<{ export: []; print: [] }>();

const { t } = useT();
const showFilters = ref(activeFilterCount(props.filters) > 0);
const custom = reactive({ from: props.filters.from, to: props.filters.to });
const customOpen = ref(props.filters.preset === 'custom');

watch(
    () => props.filters,
    (filters) => {
        custom.from = filters.from;
        custom.to = filters.to;
        customOpen.value = filters.preset === 'custom';
    },
);

function go(next: Partial<ReportFiltersValue>): void {
    const filters = { ...props.filters, ...next };

    router.get(routes.reports, filterQuery(filters, { category: props.category === 'overview' ? undefined : props.category }), {
        preserveScroll: true,
        preserveState: true,
    });
}

function choose(preset: string): void {
    if (preset === 'custom') {
        customOpen.value = true;

        return;
    }

    customOpen.value = false;
    go({ preset });
}

const customInvalid = computed(() => custom.from === '' || custom.to === '' || custom.from > custom.to);
const applyCustom = (): void => go({ preset: 'custom', from: custom.from, to: custom.to });

const locationOptions = computed(() => [
    { value: '', label: t('reports.filter.all_locations') },
    ...props.options.locations.map((row) => ({ value: row.id, label: row.name })),
]);
const practitionerOptions = computed(() => [
    { value: '', label: t('reports.filter.all_practitioners') },
    ...props.options.practitioners.map((row) => ({ value: row.id, label: row.name })),
]);
const serviceOptions = computed(() => [
    { value: '', label: t('reports.filter.all_services') },
    ...props.options.services.map((row) => ({ value: row.id, label: row.name })),
]);
const sourceOptions = computed(() => [
    { value: '', label: t('reports.filter.all_sources') },
    ...props.options.sources.map((key) => ({ value: key, label: t(`reports.source.${key}`) })),
]);

const count = computed(() => activeFilterCount(props.filters));
const summary = computed(
    () =>
        `${t('reports.period.range', { from: formatDate(props.period.from), to: formatDate(props.period.to) })} (${t('reports.period.days', { count: props.period.days, days: props.period.days })})`,
);
const compared = computed(() => t('reports.period.compared', { from: formatDate(props.period.previous.from), to: formatDate(props.period.previous.to) }));
const activeText = computed(() => {
    const parts: string[] = [];
    const name = (list: { id: string; name: string }[], id: string | null) => list.find((row) => row.id === id)?.name;

    if (props.filters.location) parts.push(`${t('reports.filter.location')} : ${name(props.options.locations, props.filters.location) ?? ''}`);
    if (props.filters.practitioner) parts.push(`${t('reports.filter.practitioner')} : ${name(props.options.practitioners, props.filters.practitioner) ?? ''}`);
    if (props.filters.service) parts.push(`${t('reports.filter.service')} : ${name(props.options.services, props.filters.service) ?? ''}`);
    if (props.filters.status.length > 0)
        parts.push(`${t('reports.filter.status')} : ${props.filters.status.map((status) => t(`reports.status.${status}`)).join(', ')}`);
    if (props.filters.source) parts.push(`${t('reports.filter.source')} : ${t(`reports.source.${props.filters.source}`)}`);

    return parts.join(' · ');
});

function toggleStatus(status: string, on: boolean): void {
    const next = on ? [...props.filters.status, status] : props.filters.status.filter((item) => item !== status);
    go({ status: next });
}
</script>

<template>
    <div class="space-y-4" data-testid="report-toolbar">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div
                class="inline-flex max-w-full flex-wrap gap-1 rounded-[1.4rem] border border-line bg-surface p-1"
                role="group"
                :aria-label="t('reports.period.label')"
            >
                <button
                    v-for="preset in options.presets"
                    :key="preset"
                    type="button"
                    class="rounded-full px-3.5 py-1.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-600/20"
                    :class="
                        filters.preset === preset || (preset === 'custom' && customOpen) ? 'bg-primary-600 text-white' : 'text-neutral-800 hover:bg-primary-50'
                    "
                    :aria-pressed="filters.preset === preset || (preset === 'custom' && customOpen)"
                    :data-testid="`preset-${preset}`"
                    @click="choose(preset)"
                >
                    {{ t(`reports.period.preset.${preset}`) }}
                </button>
            </div>
            <div class="flex flex-wrap gap-2 print:hidden">
                <BaseButton v-if="access.export" variant="secondary" size="sm" data-testid="export-open" @click="emit('export')">
                    {{ t('reports.export.action') }}
                </BaseButton>
                <BaseButton variant="secondary" size="sm" data-testid="print-open" @click="emit('print')">{{ t('reports.print.action') }}</BaseButton>
            </div>
        </div>

        <form v-if="customOpen" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" data-testid="custom-range" @submit.prevent="applyCustom">
            <TextField v-model="custom.from" type="date" :label="t('reports.period.from')" required />
            <TextField v-model="custom.to" type="date" :label="t('reports.period.to')" required />
            <BaseButton type="submit" :disabled="customInvalid">{{ t('reports.period.apply') }}</BaseButton>
        </form>

        <div>
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-full border border-line-strong bg-surface px-3.5 py-1.5 text-sm font-semibold text-neutral-900 hover:border-primary-600 md:hidden"
                :aria-expanded="showFilters"
                @click="showFilters = !showFilters"
            >
                {{ t('reports.filter.title') }}<span v-if="count > 0" class="rounded-full bg-primary-600 px-1.5 text-xs text-white">{{ count }}</span>
            </button>

            <div :class="showFilters ? 'mt-3 block md:mt-0' : 'hidden md:block'" role="group" :aria-label="t('reports.filter.title')">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <SelectField
                        v-if="options.locations.length > 1"
                        :model-value="filters.location ?? ''"
                        :label="t('reports.filter.location')"
                        :options="locationOptions"
                        @update:model-value="go({ location: $event || null })"
                    />
                    <SelectField
                        v-if="!access.own_only"
                        :model-value="filters.practitioner ?? ''"
                        :label="t('reports.filter.practitioner')"
                        :options="practitionerOptions"
                        @update:model-value="go({ practitioner: $event || null })"
                    />
                    <SelectField
                        :model-value="filters.service ?? ''"
                        :label="t('reports.filter.service')"
                        :options="serviceOptions"
                        @update:model-value="go({ service: $event || null })"
                    />
                    <SelectField
                        :model-value="filters.source ?? ''"
                        :label="t('reports.filter.source')"
                        :options="sourceOptions"
                        @update:model-value="go({ source: $event || null })"
                    />
                </div>
                <fieldset v-if="statusApplies" class="mt-3">
                    <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('reports.filter.status') }}</legend>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <CheckboxField
                            v-for="status in options.statuses"
                            :key="status"
                            :model-value="filters.status.includes(status)"
                            :label="t(`reports.status.${status}`)"
                            @update:model-value="toggleStatus(status, $event)"
                        />
                    </div>
                </fieldset>
                <p v-else-if="category === 'customers'" class="mt-3 text-xs text-neutral-700">{{ t('reports.filter.not_applied') }}</p>
                <button
                    v-if="count > 0"
                    type="button"
                    class="mt-3 text-sm font-semibold text-primary-700 underline"
                    @click="go({ location: null, practitioner: null, service: null, source: null, status: [] })"
                >
                    {{ t('reports.filter.reset') }}
                </button>
            </div>
        </div>

        <div class="rounded-control bg-surface-2 px-4 py-3 text-sm text-neutral-900" data-testid="report-summary" aria-live="polite">
            <p>
                <strong>{{ summary }}</strong> · {{ t('reports.period.timezone', { timezone: period.timezone }) }}
            </p>
            <p class="text-neutral-800">{{ compared }}</p>
            <p v-if="period.in_progress" class="text-neutral-800">{{ t('reports.period.in_progress') }}</p>
            <p class="text-neutral-800">{{ activeText ? t('reports.filter.active', { filters: activeText }) : t('reports.filter.none') }}</p>
            <p v-if="access.own_only" class="text-neutral-800">{{ t('reports.filter.own_only') }}</p>
            <p v-if="category !== 'customers' && filters.status.length > 0" class="text-neutral-800">{{ t('reports.note.status_filter') }}</p>
        </div>
    </div>
</template>
