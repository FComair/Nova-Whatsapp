<script setup lang="ts">
import { computed, useId } from 'vue';

const props = withDefaults(defineProps<{ label: string; error?: string; hint?: string; maxlength?: number; rows?: number }>(), {
    error: undefined,
    hint: undefined,
    maxlength: undefined,
    rows: 3,
});

const model = defineModel<string>({ required: true });

const id = useId();
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const describedBy = computed(() => [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div>
        <label :for="id" class="mb-1.5 block text-sm font-medium text-neutral-800">{{ label }}</label>
        <textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-lg border bg-white px-3 py-2.5 text-base text-neutral-900 focus:border-primary-600 focus:outline-none"
            :class="error ? 'border-danger-600' : 'border-neutral-300'"
        />
        <p v-if="hint" :id="hintId" class="mt-1.5 text-sm text-neutral-600">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ error }}</p>
    </div>
</template>
