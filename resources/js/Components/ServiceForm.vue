<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { useT } from '../composables/useT';
import { parseVat } from '../composables/vat';
import type { ServiceFormData } from '../types';
import BaseButton from './BaseButton.vue';
import CategoryCombobox from './CategoryCombobox.vue';
import CheckboxField from './CheckboxField.vue';
import SelectField from './SelectField.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';

const props = defineProps<{
    errors: Partial<Record<keyof ServiceFormData, string>>;
    processing: boolean;
    categories: string[];
    vatRates: number[];
}>();
const emit = defineEmits<{ submit: []; cancel: [] }>();

// Les données du formulaire appartiennent au parent (formulaire Inertia) ; ce composant ne fait que les éditer.
const data = defineModel<ServiceFormData>({ required: true });

const { t } = useT();

const CUSTOM = 'custom';
const presets = computed(() => props.vatRates.map(String));

// Taux de la liste, ou « Autre taux… » : dans ce cas le client saisit lui-même son taux (0 à 100 %, deux décimales au plus).
const customMode = ref(!presets.value.includes(String(data.value.vat_rate)));
const attempted = ref(false);
const customField = ref<HTMLElement | null>(null);

const vatChoice = computed({
    get: () => (customMode.value ? CUSTOM : data.value.vat_rate),
    set: (value: string) => {
        customMode.value = value === CUSTOM;

        if (value === CUSTOM) {
            void nextTick(() => customField.value?.querySelector('input')?.focus());
        } else {
            data.value.vat_rate = value;
            attempted.value = false;
        }
    },
});
const vatOptions = computed(() => [
    ...props.vatRates.map((rate) => ({ value: String(rate), label: rate === 0 ? t('services.fields.vat_zero') : `${rate} %` })),
    { value: CUSTOM, label: t('services.fields.vat_custom_option') },
]);
const customInvalid = computed(() => customMode.value && parseVat(String(data.value.vat_rate)) === null);
const vatCustomError = computed(
    () =>
        props.errors.vat_rate ??
        (customInvalid.value && (attempted.value || String(data.value.vat_rate).trim() !== '') ? t('services.fields.vat_invalid') : undefined),
);

function submit(): void {
    attempted.value = true;

    if (!customInvalid.value) {
        emit('submit');
    }
}
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="submit">
        <TextField v-model="data.name" :label="t('services.fields.name')" required :error="errors.name" />
        <CategoryCombobox
            v-model="data.category"
            :label="t('services.fields.category')"
            :hint="t('services.fields.category_hint')"
            :options="categories"
            :error="errors.category"
        />
        <TextareaField v-model="data.description" :label="t('services.fields.description')" :maxlength="1000" :error="errors.description" />

        <div class="grid gap-4 sm:grid-cols-3">
            <TextField v-model="data.duration_minutes" inputmode="numeric" :label="t('services.fields.duration')" required :error="errors.duration_minutes" />
            <TextField
                v-model="data.buffer_before_minutes"
                inputmode="numeric"
                :label="t('services.fields.buffer_before')"
                :error="errors.buffer_before_minutes"
            />
            <TextField
                v-model="data.buffer_after_minutes"
                inputmode="numeric"
                :label="t('services.fields.buffer_after')"
                :error="errors.buffer_after_minutes"
            />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <TextField v-model="data.price" inputmode="decimal" :label="t('services.fields.price')" required :error="errors.price" />
            <SelectField
                v-model="vatChoice"
                :label="t('services.fields.vat_rate')"
                :options="vatOptions"
                required
                :error="customMode ? undefined : errors.vat_rate"
            />
            <TextField v-model="data.capacity" inputmode="numeric" :label="t('services.fields.capacity')" required :error="errors.capacity" />
        </div>

        <div v-if="customMode" ref="customField" class="rounded-card bg-primary-50 p-4">
            <p class="mb-2 text-sm font-semibold text-rendivoo-900">{{ t('services.fields.vat_custom_title') }}</p>
            <TextField
                v-model="data.vat_rate"
                inputmode="decimal"
                :label="t('services.fields.vat_custom_label')"
                :hint="t('services.fields.vat_custom_hint')"
                :maxlength="6"
                required
                :error="vatCustomError"
            />
        </div>

        <div class="space-y-3">
            <CheckboxField v-model="data.bookable_online" :label="t('services.fields.bookable_online')" />
            <CheckboxField v-model="data.notes_required" :label="t('services.fields.notes_required')" />
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
            <BaseButton type="submit" :loading="processing">{{ t('services.save') }}</BaseButton>
            <BaseButton variant="secondary" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
        </div>
    </form>
</template>
