<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

const props = defineProps<{
    organizationName: string;
    status: string;
    canRequestDeletion: boolean;
    deletionScheduledFor: string | null;
    canCancelDeletion: boolean;
    deletionStarted: boolean;
    deletionDelayDays: number;
    csrf: string;
}>();

const { t } = useT();
const page = usePage<SharedProps>();

const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const scheduled = computed(() => (props.deletionScheduledFor ? formatDate(props.deletionScheduledFor) : ''));

const deletion = useForm({ password: '', confirm_word: '', confirm: false });

function cancel(): void {
    router.delete(routes.accountDeletion, { preserveScroll: true });
}
</script>

<template>
    <SettingsLayout :title="t('account_data.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('account_data.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('account_data.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-2xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.deletion" variant="error" class="mt-4 max-w-2xl">{{ errors.deletion }}</AlertBox>

        <section class="mt-8 max-w-2xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="export">
            <h2 id="export" class="text-lg font-semibold text-nova-900">{{ t('account_data.export_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('account_data.export_body') }}</p>
            <!-- Formulaire natif : la réponse est un fichier à télécharger, pas une page Inertia. -->
            <form method="post" :action="routes.accountExport" class="mt-4 space-y-3">
                <input type="hidden" name="_token" :value="csrf" />
                <label class="block text-sm font-medium text-neutral-800" for="export-password">{{ t('account_data.password') }}</label>
                <input
                    id="export-password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="block w-full rounded-control border px-3 py-2.5 text-base"
                    :class="errors.password ? 'border-danger-600' : 'border-line-strong'"
                />
                <p v-if="errors.password" class="text-sm font-medium text-danger-700" role="alert">{{ errors.password }}</p>
                <BaseButton type="submit">{{ t('account_data.export_submit') }}</BaseButton>
            </form>
        </section>

        <section
            v-if="status === 'pending_deletion'"
            class="mt-6 max-w-2xl rounded-card border border-danger-600/30 bg-danger-50 p-5"
            aria-labelledby="pending"
        >
            <h2 id="pending" class="text-lg font-semibold text-danger-700">{{ t('account_data.pending_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-800">
                {{ deletionStarted ? t('account_data.started_body') : t('account_data.pending_body', { date: scheduled }) }}
            </p>
            <div v-if="canCancelDeletion" class="mt-4">
                <BaseButton @click="cancel">{{ t('account_data.cancel_submit') }}</BaseButton>
            </div>
        </section>

        <section v-else-if="canRequestDeletion" class="mt-6 max-w-2xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="delete">
            <h2 id="delete" class="text-lg font-semibold text-nova-900">{{ t('account_data.delete_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('account_data.delete_body', { days: deletionDelayDays }) }}</p>
            <p v-if="status !== 'paused'" class="mt-2 text-sm text-neutral-700">
                {{ t('account_data.pause_hint') }}
                <Link :href="routes.accountPause" class="font-semibold text-primary-700 underline">{{ t('account_data.pause_link') }}</Link>
            </p>
            <form class="mt-4 space-y-4" novalidate @submit.prevent="deletion.post(routes.accountDeletion, { preserveScroll: true })">
                <TextField
                    v-model="deletion.confirm_word"
                    :label="t('account_data.delete_confirm_word', { word: t('account_data.confirm_word') })"
                    :error="deletion.errors.confirm_word"
                />
                <TextField
                    v-model="deletion.password"
                    type="password"
                    autocomplete="current-password"
                    :label="t('account_data.password')"
                    :error="deletion.errors.password"
                />
                <CheckboxField v-model="deletion.confirm" :label="t('account_data.delete_confirm')" :error="deletion.errors.confirm" />
                <BaseButton type="submit" variant="danger" :loading="deletion.processing">{{ t('account_data.delete_submit') }}</BaseButton>
            </form>
        </section>
    </SettingsLayout>
</template>
