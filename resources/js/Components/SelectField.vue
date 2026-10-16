<script setup lang="ts">
import { computed, useId } from 'vue';

const props = defineProps<{
    label: string;
    options: { value: string; label: string }[];
    placeholder?: string;
    /** Texte de l'unique option (désactivée) quand la liste est vide : la liste ne s'ouvre jamais « à blanc ». */
    emptyLabel?: string;
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
        <label :for="id" class="mb-1.5 block text-sm font-semibold text-neutral-900">
            {{ label }}<span v-if="required" class="text-danger-600" aria-hidden="true"> *</span>
        </label>

        <select
            :id="id"
            v-model="model"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="block w-full rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-2"
            :class="
                error
                    ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                    : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
            "
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-if="options.length === 0 && emptyLabel" value="" disabled>{{ emptyLabel }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
