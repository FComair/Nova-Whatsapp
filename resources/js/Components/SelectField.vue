<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{
    label: string;
    options: { value: string; label: string }[];
    placeholder?: string;
    error?: string;
    required?: boolean;
}>();

const model = defineModel<string>({ required: true });

const id = useId();
const errorId = `${id}-error`;
const describedBy = computed(() => (props.error ? errorId : undefined));
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-neutral-800">
            {{ label }}<span v-if="required" class="text-danger-600" aria-hidden="true"> *</span>
        </label>

        <select
            :id="id"
            v-model="model"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-neutral-900 focus:border-primary-600 focus:outline-none"
            :class="error ? 'border-danger-600' : 'border-neutral-300'"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ error }}</p>
    </div>
</template>
