<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { startMetaSignup } from '../composables/metaSignup';
import type { MetaSignupConfig } from '../composables/metaSignup';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';
import AlertBox from './AlertBox.vue';
import Badge from './Badge.vue';
import BaseButton from './BaseButton.vue';
import TextField from './TextField.vue';

export interface WhatsappTemplateRow {
    key: 'reminder' | 'confirmation' | 'rescheduled' | 'cancellation';
    name: string;
    language: string;
    status: 'missing' | 'pending' | 'approved' | 'rejected' | 'paused';
    reason: string | null;
}

/** Couleur de la pastille selon l'état d'approbation du modèle chez Meta. */
function templateVariant(status: WhatsappTemplateRow['status']): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
    return { approved: 'success', pending: 'info', rejected: 'danger', paused: 'warning', missing: 'neutral' }[status] as
        'success' | 'warning' | 'danger' | 'info' | 'neutral';
}

const props = withDefaults(
    defineProps<{
        account: { phone_number_id: string; waba_id: string | null; display_phone: string | null } | null;
        templates: WhatsappTemplateRow[];
        allApproved: boolean;
        embedded?: MetaSignupConfig | null;
        section?: 'all' | 'connection' | 'templates';
    }>(),
    { section: 'all', embedded: null },
);

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const connect = useForm({ phone_number_id: '', waba_id: '', access_token: '', password: '' });
const disconnect = useForm({ password: '' });

const messages = computed(() => [...new Set(props.templates.map((row) => row.key))]);
const rowsFor = (key: string): WhatsappTemplateRow[] => props.templates.filter((row) => row.key === key);

function submitConnection(): void {
    connect.post(routes.whatsapp, { preserveScroll: true, onSuccess: () => connect.reset() });
}

function submitDisconnect(): void {
    disconnect.delete(routes.whatsapp, { preserveScroll: true, onSuccess: () => disconnect.reset() });
}

const post = (url: string): void => router.post(url, {}, { preserveScroll: true });

// Inscription intégrée de Meta : le module de Meta n'est chargé qu'au clic (c'est alors seulement que Meta reçoit l'adresse IP).
const signingUp = ref(false);
const signupError = ref<string | null>(null);

async function signUpWithMeta(): Promise<void> {
    if (props.embedded === null) {
        return;
    }

    signingUp.value = true;
    signupError.value = null;

    try {
        const result = await startMetaSignup(props.embedded);
        router.post(routes.whatsappEmbedded, { ...result }, { preserveScroll: true });
    } catch (error) {
        const reason = error instanceof Error ? error.message : 'error';
        signupError.value = reason === 'cancel' ? t('whatsapp.embedded_cancelled') : t('whatsapp.embedded_failed');
    } finally {
        signingUp.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <AlertBox v-if="errors.whatsapp" variant="error">{{ errors.whatsapp }}</AlertBox>

        <section v-if="section !== 'templates'" class="rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="connection">
            <h2 id="connection" class="text-lg font-semibold text-nova-900">{{ t('whatsapp.connection_title') }}</h2>

            <p v-if="account" class="mt-2 text-sm font-medium text-success-600" data-testid="connected">
                {{ t('whatsapp.connected_as', { phone: account.display_phone ?? account.phone_number_id }) }}
            </p>
            <p v-else class="mt-2 text-sm text-neutral-700">{{ t('whatsapp.not_connected') }}</p>

            <div v-if="embedded" class="mt-4 rounded-control border border-line bg-neutral-50 p-4" data-testid="embedded">
                <h3 class="font-semibold text-nova-900">{{ t('whatsapp.embedded_title') }}</h3>
                <p class="mt-1 text-sm text-neutral-700">{{ t('whatsapp.embedded_notice') }}</p>
                <AlertBox v-if="signupError" variant="warning" class="mt-3">{{ signupError }}</AlertBox>
                <BaseButton class="mt-3" :loading="signingUp" @click="signUpWithMeta">{{ t('whatsapp.embedded_button') }}</BaseButton>
            </div>

            <h3 v-if="embedded" class="mt-6 font-semibold text-nova-900">{{ t('whatsapp.manual_title') }}</h3>
            <p class="mt-1 text-sm text-neutral-600">{{ t('whatsapp.help') }}</p>

            <form class="mt-4 space-y-4" novalidate @submit.prevent="submitConnection">
                <TextField
                    v-model="connect.phone_number_id"
                    inputmode="numeric"
                    :label="t('whatsapp.phone_number_id')"
                    required
                    :error="connect.errors.phone_number_id"
                />
                <TextField v-model="connect.waba_id" inputmode="numeric" :label="t('whatsapp.waba_id')" required :error="connect.errors.waba_id" />
                <TextField
                    v-model="connect.access_token"
                    type="password"
                    autocomplete="off"
                    :label="t('whatsapp.access_token')"
                    required
                    :error="connect.errors.access_token"
                />
                <TextField
                    v-model="connect.password"
                    type="password"
                    autocomplete="current-password"
                    :label="t('whatsapp.password')"
                    required
                    :error="connect.errors.password"
                />
                <BaseButton type="submit" :loading="connect.processing">{{ account ? t('whatsapp.reconnect') : t('whatsapp.connect') }}</BaseButton>
            </form>

            <form v-if="account" class="mt-6 space-y-3 border-t border-line pt-4" novalidate @submit.prevent="submitDisconnect">
                <TextField
                    v-model="disconnect.password"
                    type="password"
                    autocomplete="current-password"
                    :label="t('whatsapp.password')"
                    required
                    :error="disconnect.errors.password"
                />
                <BaseButton type="submit" variant="danger" :loading="disconnect.processing">{{ t('whatsapp.disconnect') }}</BaseButton>
            </form>
        </section>

        <section v-if="section !== 'connection'" class="rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="templates">
            <h2 id="templates" class="text-lg font-semibold text-nova-900">{{ t('whatsapp.templates_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('whatsapp.templates_intro') }}</p>

            <p v-if="!account" class="mt-3 text-sm text-neutral-600">{{ t('whatsapp.templates_need_connection') }}</p>

            <template v-else>
                <p v-if="allApproved" class="mt-3 text-sm font-medium text-success-600">{{ t('whatsapp.all_approved') }}</p>

                <table class="mt-4 w-full text-left text-sm" data-testid="templates">
                    <thead>
                        <tr class="border-b border-line bg-surface-2 text-xs uppercase tracking-wider text-muted">
                            <th scope="col" class="px-3 py-2.5 font-semibold">{{ t('whatsapp.templates_title') }}</th>
                            <th v-for="row in rowsFor(messages[0] ?? 'reminder')" :key="row.language" scope="col" class="px-3 py-2.5 font-semibold uppercase">
                                {{ row.language }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="key in messages" :key="key" class="border-b border-line transition-colors duration-100 hover:bg-surface-2">
                            <th scope="row" class="px-3 py-3 font-medium text-neutral-900">{{ t(`whatsapp.message.${key}`) }}</th>
                            <td v-for="row in rowsFor(key)" :key="row.language" class="px-3 py-3" :title="row.reason ?? undefined">
                                <Badge :variant="templateVariant(row.status)">{{ t(`whatsapp.status.${row.status}`) }}</Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                    <BaseButton @click="post(routes.whatsappTemplates)">{{ t('whatsapp.submit_templates') }}</BaseButton>
                    <BaseButton variant="secondary" @click="post(routes.whatsappTemplatesSync)">{{ t('whatsapp.sync_templates') }}</BaseButton>
                </div>
            </template>
        </section>
    </div>
</template>
