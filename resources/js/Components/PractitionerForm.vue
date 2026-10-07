<script setup lang="ts">
import { useId } from 'vue';
import { useT } from '../composables/useT';
import type { PractitionerFormData } from '../types';
import BaseButton from './BaseButton.vue';
import CheckboxField from './CheckboxField.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';

defineProps<{
    errors: Record<string, string>;
    processing: boolean;
    services: { id: string; name: string }[];
    colors: string[];
    /** Propose « C'est moi » (création uniquement, si le propriétaire n'est pas déjà praticien). */
    canBeMe: boolean;
}>();
const emit = defineEmits<{ submit: []; cancel: [] }>();

// Données possédées par le parent (formulaire Inertia) ; ce composant ne fait que les éditer.
const data = defineModel<PractitionerFormData>({ required: true });

const { t } = useT();
const id = useId();
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="emit('submit')">
        <CheckboxField v-if="canBeMe" v-model="data.is_me" :label="t('practitioners.fields.is_me')" :error="errors.is_me" />

        <TextField v-model="data.name" :label="t('practitioners.fields.name')" required :error="errors.name" />

        <fieldset>
            <legend class="mb-1.5 text-sm font-medium text-neutral-800">{{ t('practitioners.fields.color') }}</legend>
            <div class="flex flex-wrap gap-2">
                <label v-for="color in colors" :key="color" class="cursor-pointer">
                    <input v-model="data.color" type="radio" :name="`${id}-color`" :value="color" class="peer sr-only" :aria-label="color" />
                    <span
                        class="block size-9 rounded-full border-2 border-white shadow ring-2 ring-transparent peer-checked:ring-nova-900 peer-focus-visible:outline peer-focus-visible:outline-3 peer-focus-visible:outline-primary-600"
                        :style="{ backgroundColor: color }"
                    />
                </label>
            </div>
            <p v-if="errors.color" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ errors.color }}</p>
        </fieldset>

        <TextareaField v-model="data.bio" :label="t('practitioners.fields.bio')" :maxlength="1000" :error="errors.bio" />

        <fieldset>
            <legend class="text-sm font-medium text-neutral-800">{{ t('practitioners.fields.services') }}</legend>
            <p class="mb-2 text-sm text-neutral-600">{{ t('practitioners.fields.services_hint') }}</p>
            <p v-if="services.length === 0" class="text-sm text-warning-600">{{ t('practitioners.fields.no_services') }}</p>
            <ul class="space-y-2">
                <li v-for="service in services" :key="service.id" class="flex items-center gap-3">
                    <input
                        :id="`${id}-${service.id}`"
                        v-model="data.service_ids"
                        type="checkbox"
                        :value="service.id"
                        class="size-5 rounded border-neutral-300 text-primary-600"
                    />
                    <label :for="`${id}-${service.id}`" class="text-sm text-neutral-800">{{ service.name }}</label>
                </li>
            </ul>
            <p v-if="errors.service_ids" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ errors.service_ids }}</p>
        </fieldset>

        <div class="flex flex-col gap-3 sm:flex-row">
            <BaseButton type="submit" :loading="processing">{{ t('practitioners.save') }}</BaseButton>
            <BaseButton variant="secondary" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
        </div>
    </form>
</template>
