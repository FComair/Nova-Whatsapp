<script setup lang="ts">
import { nextTick, ref, useId, watch } from 'vue';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';

const props = defineProps<{
    open: boolean;
    title: string;
    body: string;
    confirmLabel: string;
    loading?: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();
const bodyId = useId();

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
        :aria-describedby="bodyId"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-card border border-neutral-200 bg-white p-6 shadow-xl backdrop:bg-nova-900/50"
        @cancel.prevent="emit('cancel')"
    >
        <h2 :id="titleId" class="text-lg font-semibold text-nova-900">{{ title }}</h2>
        <p :id="bodyId" class="mt-2 text-sm text-neutral-700">{{ body }}</p>

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <BaseButton variant="secondary" @click="emit('cancel')">{{ t('common.cancel') }}</BaseButton>
            <BaseButton variant="danger" :loading="loading" @click="emit('confirm')">{{ confirmLabel }}</BaseButton>
        </div>
    </dialog>
</template>
