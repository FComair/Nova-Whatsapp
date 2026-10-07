<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

defineProps<{
    passwordChangedAt: string | null;
    passwordOld: boolean;
    twoFactor: {
        enabled: boolean;
        required: boolean;
        setup: { secret: string; qr: string } | null;
        remainingCodes: number;
    };
    recoveryCodes: string[] | null;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const password = useForm({ current_password: '', password: '', password_confirmation: '' });
const sessions = useForm({ current_password: '' });
const confirmTwoFactor = useForm({ code: '' });
const disableTwoFactor = useForm({ current_password: '', code: '' });
const regenerate = useForm({ current_password: '' });
const begin = useForm({});

function changePassword(): void {
    password.put(routes.security.password, { preserveScroll: true, onSuccess: () => password.reset() });
}

function closeSessions(): void {
    sessions.post(routes.security.sessions, { preserveScroll: true, onSuccess: () => sessions.reset() });
}

function startTwoFactor(): void {
    begin.post(routes.security.twoFactor, { preserveScroll: true });
}

function enableTwoFactor(): void {
    confirmTwoFactor.post(routes.security.twoFactorConfirm, { preserveScroll: true, onSuccess: () => confirmTwoFactor.reset() });
}

function turnOffTwoFactor(): void {
    disableTwoFactor.delete(routes.security.twoFactor, { preserveScroll: true, onSuccess: () => disableTwoFactor.reset() });
}

function newCodes(): void {
    regenerate.post(routes.security.twoFactorCodes, { preserveScroll: true, onSuccess: () => regenerate.reset() });
}
</script>

<template>
    <SettingsLayout :title="t('security.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('security.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('security.intro') }}</p>

        <AlertBox v-if="page.props.flash.warning" variant="warning" class="mt-4 max-w-2xl">{{ page.props.flash.warning }}</AlertBox>
        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-2xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.two_factor" variant="error" class="mt-4 max-w-2xl">{{ errors.two_factor }}</AlertBox>

        <!-- Double authentification -->
        <section class="mt-8 max-w-2xl rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="two-factor">
            <h2 id="two-factor" class="text-lg font-semibold text-nova-900">
                {{ t('security.two_factor_section') }}
                <span class="ml-2 text-sm font-medium" :class="twoFactor.enabled ? 'text-success-600' : 'text-neutral-600'">
                    {{ twoFactor.enabled ? t('security.two_factor_on') : t('security.two_factor_off') }}
                </span>
            </h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('security.two_factor_body') }}</p>
            <p v-if="twoFactor.required" class="mt-1 text-sm font-medium text-neutral-800">{{ t('security.two_factor_mandatory') }}</p>

            <!-- Codes de secours : affichés une seule fois, juste après l'activation ou le renouvellement. -->
            <div v-if="recoveryCodes" class="mt-4 rounded-lg border border-neutral-300 bg-neutral-50 p-4">
                <h3 class="font-semibold text-nova-900">{{ t('security.recovery_title') }}</h3>
                <p class="mt-1 text-sm text-neutral-700">{{ t('security.recovery_body') }}</p>
                <ul class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm" data-testid="recovery-codes">
                    <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                </ul>
            </div>

            <!-- Pas encore activée -->
            <template v-if="!twoFactor.enabled">
                <div v-if="!twoFactor.setup" class="mt-4">
                    <BaseButton :loading="begin.processing" @click="startTwoFactor">{{ t('security.two_factor_enable') }}</BaseButton>
                </div>
                <form v-else class="mt-4 space-y-4" novalidate @submit.prevent="enableTwoFactor">
                    <p class="text-sm text-neutral-700">{{ t('security.two_factor_scan') }}</p>
                    <img :src="twoFactor.setup.qr" alt="" class="h-44 w-44 rounded border border-neutral-200" />
                    <p class="break-all font-mono text-sm text-neutral-800">{{ t('security.two_factor_secret', { secret: twoFactor.setup.secret }) }}</p>
                    <TextField
                        v-model="confirmTwoFactor.code"
                        :label="t('security.two_factor_code')"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        :maxlength="6"
                        required
                        :error="confirmTwoFactor.errors.code"
                    />
                    <BaseButton type="submit" :loading="confirmTwoFactor.processing">{{ t('security.two_factor_confirm') }}</BaseButton>
                </form>
            </template>

            <!-- Activée -->
            <template v-else>
                <p class="mt-3 text-sm text-neutral-700">{{ t('security.recovery_remaining', { count: twoFactor.remainingCodes }) }}</p>
                <form class="mt-3 space-y-3" novalidate @submit.prevent="newCodes">
                    <TextField
                        v-model="regenerate.current_password"
                        type="password"
                        :label="t('security.current_password')"
                        autocomplete="current-password"
                        required
                        :error="regenerate.errors.current_password"
                    />
                    <BaseButton type="submit" variant="secondary" :loading="regenerate.processing">{{ t('security.recovery_regenerate') }}</BaseButton>
                </form>

                <form v-if="!twoFactor.required" class="mt-6 space-y-3 border-t border-neutral-200 pt-4" novalidate @submit.prevent="turnOffTwoFactor">
                    <TextField
                        v-model="disableTwoFactor.current_password"
                        type="password"
                        :label="t('security.current_password')"
                        autocomplete="current-password"
                        required
                        :error="disableTwoFactor.errors.current_password"
                    />
                    <TextField
                        v-model="disableTwoFactor.code"
                        :label="t('security.two_factor_disable_code')"
                        autocomplete="one-time-code"
                        required
                        :error="disableTwoFactor.errors.code"
                    />
                    <BaseButton type="submit" variant="danger" :loading="disableTwoFactor.processing">{{ t('security.two_factor_disable') }}</BaseButton>
                </form>
            </template>
        </section>

        <!-- Mot de passe -->
        <section class="mt-6 max-w-2xl rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="password">
            <h2 id="password" class="text-lg font-semibold text-nova-900">{{ t('security.password_section') }}</h2>
            <AlertBox v-if="passwordOld" variant="warning" class="mt-3">{{ t('security.password_old') }}</AlertBox>
            <form class="mt-3 space-y-4" novalidate @submit.prevent="changePassword">
                <TextField
                    v-model="password.current_password"
                    type="password"
                    :label="t('security.current_password')"
                    autocomplete="current-password"
                    required
                    :error="password.errors.current_password"
                />
                <TextField
                    v-model="password.password"
                    type="password"
                    :label="t('security.new_password')"
                    autocomplete="new-password"
                    required
                    :hint="t('security.password_hint')"
                    :error="password.errors.password"
                />
                <TextField
                    v-model="password.password_confirmation"
                    type="password"
                    :label="t('security.confirm_password')"
                    autocomplete="new-password"
                    required
                />
                <BaseButton type="submit" :loading="password.processing">{{ t('security.password_save') }}</BaseButton>
            </form>
        </section>

        <!-- Sessions -->
        <section class="mt-6 max-w-2xl rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="sessions">
            <h2 id="sessions" class="text-lg font-semibold text-nova-900">{{ t('security.sessions_section') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('security.sessions_body') }}</p>
            <form class="mt-3 space-y-3" novalidate @submit.prevent="closeSessions">
                <TextField
                    v-model="sessions.current_password"
                    type="password"
                    :label="t('security.current_password')"
                    autocomplete="current-password"
                    required
                    :error="sessions.errors.current_password"
                />
                <BaseButton type="submit" variant="secondary" :loading="sessions.processing">{{ t('security.sessions_close') }}</BaseButton>
            </form>
        </section>
    </SettingsLayout>
</template>
