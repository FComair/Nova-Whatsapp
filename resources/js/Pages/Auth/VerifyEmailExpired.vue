<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const { t } = useT();
const form = useForm({ email: '' });
</script>

<template>
    <AuthLayout :title="t('verify.expired_title')" :subtitle="t('verify.expired_body')">
        <form class="space-y-5" novalidate @submit.prevent="form.post(routes.verificationSend)">
            <TextField v-model="form.email" type="email" :label="t('login.email')" autocomplete="email" inputmode="email" required :error="form.errors.email" />
            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('verify.email_resend') }}</BaseButton>
        </form>

        <template #footer>
            <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('forgot.back_to_login') }}</Link>
        </template>
    </AuthLayout>
</template>
