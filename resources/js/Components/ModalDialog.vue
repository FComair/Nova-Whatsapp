<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';
import { useT } from '../composables/useT';

const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();

// <dialog> natif : focus piégé, fermeture par Échap, arrière-plan inerte (accessibilité).
watch(
    () => props.open,
    async (open) => {
        await nextTick();

        if (open && !dialog.value?.open) {
            dialog.value?.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
    { immediate: true },
);
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-card border border-neutral-200 bg-white p-6 shadow-xl backdrop:bg-nova-900/50"
        @cancel.prevent="emit('close')"
    >
        <div class="flex items-start justify-between gap-4">
            <h2 :id="titleId" class="text-lg font-semibold text-nova-900">{{ title }}</h2>
            <button type="button" class="-mr-2 -mt-1 rounded-lg px-2 py-1 text-sm font-medium text-neutral-600 hover:bg-neutral-100" @click="emit('close')">
                {{ t('planning.actions.close') }}
            </button>
        </div>
        <div class="mt-4"><slot /></div>
    </dialog>
</template>
