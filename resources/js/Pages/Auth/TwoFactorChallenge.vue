<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const { t } = useT();
const form = useForm({ code: '' });

function submit(): void {
    form.post(routes.twoFactorChallenge, { onFinish: () => form.reset('code') });
}
</script>

<template>
    <AuthLayout :title="t('security.challenge_title')" :subtitle="t('security.challenge_body')">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.code"
                :label="t('security.challenge_code')"
                autocomplete="one-time-code"
                inputmode="numeric"
                required
                :error="form.errors.code"
            />
            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('security.challenge_submit') }}</BaseButton>
        </form>

        <template #footer>
            <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('login.title') }}</Link>
        </template>
    </AuthLayout>
</template>
