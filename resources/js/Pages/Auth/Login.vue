<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const { t } = useT();
const form = useForm({ email: '', password: '' });

function submit(): void {
    form.post(routes.login, { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthLayout :title="t('login.title')" :subtitle="t('login.subtitle')">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.email"
                type="email"
                :label="t('login.email')"
                autocomplete="username"
                inputmode="email"
                required
                :error="form.errors.email"
            />
            <TextField
                v-model="form.password"
                type="password"
                :label="t('login.password')"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />

            <div class="flex items-center justify-between gap-3">
                <Link :href="routes.forgotPassword" class="text-sm font-medium text-primary-600 hover:text-primary-700">{{ t('login.forgot') }}</Link>
            </div>

            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('login.submit') }}</BaseButton>
        </form>

        <template #footer>
            {{ t('login.no_account') }}
            <Link :href="routes.register" class="font-medium text-primary-600 hover:text-primary-700">{{ t('login.create_account') }}</Link>
        </template>
    </AuthLayout>
</template>
