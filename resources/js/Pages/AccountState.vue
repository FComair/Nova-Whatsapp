<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../Components/AlertBox.vue';
import { useT } from '../composables/useT';
import { formatDate } from '../composables/dates';
import AppLayout from '../Layouts/AppLayout.vue';
import { routes } from '../routes';
import type { SharedProps } from '../types';

const props = defineProps<{
    status: string | null;
    reason: string | null;
    pauseUntil: string | null;
    deletionScheduledFor: string | null;
}>();

const { t } = useT();
const pauseEnded = computed(() => props.status === 'suspended' && props.reason === 'system.pause_ended');

function reactivate(): void {
    router.delete(routes.accountPause);
}
const isOwner = computed(() => usePage<SharedProps>().props.auth.organization?.role === 'owner');

const deadline = computed(() => {
    const iso = props.status === 'pending_deletion' ? props.deletionScheduledFor : props.pauseUntil;

    return iso ? formatDate(iso) : null;
});
</script>

<template>
    <AppLayout :title="t(`account.status.${status}`)">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t(`account.status.${status}`) }}</h1>

        <AlertBox :variant="status === 'pending_deletion' ? 'error' : 'warning'" class="mt-5 max-w-2xl">
            {{ t(`account.body.${status}`) }}
            <span v-if="deadline" class="mt-1 block font-semibold">{{ deadline }}</span>
            <span v-if="reason && status === 'suspended'" class="mt-1 block">{{
                reason.startsWith('system.') ? t(`account.reasons.${reason.slice(7)}`) : reason
            }}</span>
        </AlertBox>

        <Link
            v-if="isOwner && reason?.startsWith('system.') && !pauseEnded"
            :href="routes.billing"
            class="mt-4 mr-4 inline-block text-sm font-semibold text-primary-700 underline"
        >
            {{ t('billing.title') }}
        </Link>
        <button v-if="isOwner && pauseEnded" type="button" class="mt-4 mr-4 inline-block text-sm font-semibold text-primary-700 underline" @click="reactivate">
            {{ t('pause.resume') }}
        </button>
        <Link v-if="isOwner && status === 'paused'" :href="routes.accountPause" class="mt-4 mr-4 inline-block text-sm font-semibold text-primary-700 underline">
            {{ t('pause.resume') }}
        </Link>
        <Link v-if="isOwner" :href="routes.accountData" class="mt-4 inline-block text-sm font-semibold text-primary-700 underline">
            {{ t('account_data.title') }}
        </Link>
    </AppLayout>
</template>
