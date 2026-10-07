<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{ label: string; error?: string }>();
const model = defineModel<boolean>({ required: true });

const id = useId();
const errorId = `${id}-error`;
const describedBy = computed(() => (props.error ? errorId : undefined));
</script>

<template>
    <div>
        <div class="flex items-start gap-3">
            <input
                :id="id"
                v-model="model"
                type="checkbox"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="mt-0.5 size-5 shrink-0 rounded border-neutral-300 text-primary-600"
            />
            <label :for="id" class="text-sm text-neutral-800">{{ label }}</label>
        </div>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-700" role="alert">{{ error }}</p>
    </div>
</template>
