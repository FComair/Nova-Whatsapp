<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{ setupRef: string }>();

const { t } = useT();

function choose(card: 'ok' | 'declined'): void {
    router.post(`${routes.billing}/simulation`, { ref: props.setupRef, card });
}
</script>

<template>
    <AppLayout :title="t('billing.simulation_title')">
        <h1 class="text-2xl font-semibold text-rendivoo-900">{{ t('billing.simulation_title') }}</h1>
        <AlertBox variant="warning" class="mt-4 max-w-xl">{{ t('billing.simulation_body') }}</AlertBox>
        <div class="mt-6 flex max-w-xl flex-col gap-3 sm:flex-row">
            <BaseButton @click="choose('ok')">{{ t('billing.simulation_ok') }}</BaseButton>
            <BaseButton variant="secondary" @click="choose('declined')">{{ t('billing.simulation_declined') }}</BaseButton>
        </div>
    </AppLayout>
</template>
