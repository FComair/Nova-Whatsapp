<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { type Filters, cleanFilters } from '../composables/customerList';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';
import SelectField from './SelectField.vue';
import TextField from './TextField.vue';

const props = defineProps<{
    filters: Filters;
    practitioners: { value: string; label: string }[];
    tags: string[];
}>();
const emit = defineEmits<{ apply: [filters: Filters]; close: [] }>();

const { t } = useT();

// Saisie locale en texte : rien n'est envoyé au serveur avant « Appliquer ».
const draft = reactive({ status: '', appt: '', consent: '', practitioner: '', tag: '', source: '', days: '', created_days: '', visits_min: '' });

watch(
    () => props.filters,
    (filters) => {
        draft.status = filters.status ?? '';
        draft.appt = filters.appt ?? '';
        draft.consent = filters.consent ?? '';
        draft.practitioner = filters.practitioner ?? '';
        draft.tag = filters.tag ?? '';
        draft.source = filters.source ?? '';
        draft.days = filters.days === undefined ? '' : String(filters.days);
        draft.created_days = filters.created_days === undefined ? '' : String(filters.created_days);
        draft.visits_min = filters.visits_min === undefined ? '' : String(filters.visits_min);
    },
    { immediate: true, deep: true },
);

const any = computed(() => ({ value: '', label: t('customers.filters.any') }));
const options = (keys: string[], prefix: string) => [any.value, ...keys.map((key) => ({ value: key, label: t(`${prefix}.${key}`) }))];

const statusOptions = computed(() => [
    { value: '', label: t('customers.filters.status_default') },
    { value: 'active', label: t('customers.status_label.active') },
    { value: 'blocked', label: t('customers.status_label.blocked') },
    { value: 'archived', label: t('customers.status_label.archived') },
    { value: 'all', label: t('customers.filters.status_all') },
]);
const apptOptions = computed(() => options(['upcoming', 'none', 'inactive', 'no_show'], 'customers.appt_filter'));
const consentOptions = computed(() => options(['opted_in', 'opted_out', 'unknown'], 'customers.consent_state'));
const sourceOptions = computed(() =>
    options(['whatsapp_qr', 'whatsapp', 'website', 'walk_in', 'referral', 'social', 'manual', 'other'], 'customers.source_label'),
);
const practitionerOptions = computed(() => [any.value, ...props.practitioners]);
const tagOptions = computed(() => [any.value, ...props.tags.map((tag) => ({ value: tag, label: tag }))]);

const number = (value: string): number | undefined =>
    value.trim() !== '' && Number.isFinite(Number(value)) ? Math.max(1, Math.round(Number(value))) : undefined;

function apply(): void {
    emit(
        'apply',
        cleanFilters({
            status: draft.status,
            appt: draft.appt,
            consent: draft.consent,
            practitioner: draft.practitioner,
            tag: draft.tag,
            source: draft.source,
            days: number(draft.days),
            created_days: number(draft.created_days),
            visits_min: number(draft.visits_min),
        }),
    );
}

function reset(): void {
    emit('apply', {});
}
</script>

<template>
    <section class="rounded-card border border-line bg-surface p-5 shadow-xs" :aria-label="t('customers.filters.title')">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('customers.filters.title') }}</h2>
            <BaseButton variant="ghost" size="sm" @click="emit('close')">{{ t('customers.filters.close') }}</BaseButton>
        </div>

        <form class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="apply">
            <SelectField v-model="draft.status" :label="t('customers.filters.status')" :options="statusOptions" />
            <SelectField v-model="draft.appt" :label="t('customers.filters.appt')" :options="apptOptions" />
            <TextField v-if="draft.appt === 'inactive'" v-model="draft.days" :label="t('customers.filters.days')" inputmode="numeric" />
            <SelectField v-model="draft.consent" :label="t('customers.filters.consent')" :options="consentOptions" />
            <SelectField v-if="practitioners.length" v-model="draft.practitioner" :label="t('customers.filters.practitioner')" :options="practitionerOptions" />
            <SelectField v-if="tags.length" v-model="draft.tag" :label="t('customers.filters.tag')" :options="tagOptions" />
            <SelectField v-model="draft.source" :label="t('customers.filters.source')" :options="sourceOptions" />
            <TextField v-model="draft.created_days" :label="t('customers.filters.created_days')" inputmode="numeric" />
            <TextField v-model="draft.visits_min" :label="t('customers.filters.visits_min')" inputmode="numeric" />

            <div class="flex flex-wrap gap-3 sm:col-span-2 lg:col-span-3">
                <BaseButton type="submit">{{ t('customers.filters.apply') }}</BaseButton>
                <BaseButton variant="secondary" @click="reset">{{ t('customers.filters.reset') }}</BaseButton>
            </div>
        </form>
    </section>
</template>
