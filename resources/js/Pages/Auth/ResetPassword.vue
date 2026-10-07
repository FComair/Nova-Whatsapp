<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{ token: string; email: string }>();
const { t } = useT();

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function submit(): void {
    form.post(routes.resetPassword, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout :title="t('reset.title')">
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
                :label="t('reset.password')"
                :hint="t('register.password_hint')"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />
            <TextField v-model="form.password_confirmation" type="password" :label="t('reset.confirm')" autocomplete="new-password" required />
            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('reset.submit') }}</BaseButton>
        </form>
    </AuthLayout>
</template>
