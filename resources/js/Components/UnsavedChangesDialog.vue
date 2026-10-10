<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';
import { answerUnsaved, unsavedPrompt } from '../composables/unsaved';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';

/** Confirmation « modifications non enregistrées » : Rester et enregistrer, Quitter sans enregistrer, Annuler (Échap = Annuler). */
const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();

watch(
    () => unsavedPrompt.open,
    async (open) => {
        await nextTick();

        if (open && !dialog.value?.open) {
            dialog.value?.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
);
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        data-testid="unsaved-dialog"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-modal border border-line bg-surface p-6 shadow-xl backdrop:bg-nova-900/55"
        @cancel.prevent="answerUnsaved('cancel')"
    >
        <h2 :id="titleId" class="text-lg font-semibold text-nova-900">{{ t('unsaved.message') }}</h2>

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-end">
            <BaseButton variant="primary" @click="answerUnsaved('save')">{{ t('unsaved.save') }}</BaseButton>
            <BaseButton variant="danger" @click="answerUnsaved('leave')">{{ t('unsaved.leave') }}</BaseButton>
            <BaseButton variant="secondary" @click="answerUnsaved('cancel')">{{ t('common.cancel') }}</BaseButton>
        </div>
    </dialog>
</template>
