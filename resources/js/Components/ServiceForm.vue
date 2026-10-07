<script setup lang="ts">
import { computed } from 'vue';
import { useT } from '../composables/useT';
import type { ServiceFormData } from '../types';
import BaseButton from './BaseButton.vue';
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

const vatOptions = computed(() => props.vatRates.map((rate) => ({ value: String(rate), label: rate === 0 ? t('services.fields.vat_zero') : `${rate} %` })));
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="emit('submit')">
        <TextField v-model="data.name" :label="t('services.fields.name')" required :error="errors.name" />
        <TextField
            v-model="data.category"
            :label="t('services.fields.category')"
            :hint="t('services.fields.category_hint')"
            :suggestions="categories"
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
            <SelectField v-model="data.vat_rate" :label="t('services.fields.vat_rate')" :options="vatOptions" required :error="errors.vat_rate" />
            <TextField v-model="data.capacity" inputmode="numeric" :label="t('services.fields.capacity')" required :error="errors.capacity" />
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
