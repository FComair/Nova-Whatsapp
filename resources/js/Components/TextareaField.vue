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
        <label :for="id" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ label }}</label>
        <textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-2"
            :class="
                error
                    ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                    : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
            "
        />
        <p v-if="hint" :id="hintId" class="mt-1.5 text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
