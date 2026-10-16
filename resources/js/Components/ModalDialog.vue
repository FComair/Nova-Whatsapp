<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';
import { guardedLeave } from '../composables/unsaved';
import { useT } from '../composables/useT';

const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();

/** Fermer (Retour, Échap) : si la fenêtre contient des modifications non enregistrées, on demande d'abord confirmation. */
function close(): void {
    void guardedLeave(() => emit('close'), dialog.value);
}

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
        class="m-auto max-h-[90vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-modal border border-line bg-surface p-6 shadow-xl backdrop:bg-deep/55"
        @cancel.prevent="close"
    >
        <button
            type="button"
            data-testid="modal-back"
            class="-ml-2 -mt-2 inline-flex min-h-11 items-center gap-1.5 rounded-control px-2 text-sm font-semibold text-primary-700 transition-colors duration-150 hover:bg-primary-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:bg-primary-100"
            @click="close"
        >
            <svg
                class="size-4 shrink-0"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M19 12H5M11 18l-6-6 6-6" />
            </svg>
            {{ t('common.back') }}
        </button>
        <h2 :id="titleId" class="mt-1 text-lg font-semibold text-nova-900">{{ title }}</h2>
        <div class="mt-4"><slot /></div>
    </dialog>
</template>
