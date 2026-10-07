<script setup lang="ts">
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

defineProps<{ email: string; verified: boolean }>();
const { t } = useT();
const form = useForm({});
const errors = computed(() => usePage().props.errors as Record<string, string>);
</script>

<template>
    <AuthLayout :title="t('verify.email_title')">
        <p class="text-neutral-700">{{ t('verify.email_body', { email }) }}</p>

        <AlertBox v-if="errors.email" variant="error" class="mt-4">{{ errors.email }}</AlertBox>

        <form class="mt-6" @submit.prevent="form.post(routes.verificationSend)">
            <BaseButton type="submit" variant="secondary" :loading="form.processing">{{ t('verify.email_resend') }}</BaseButton>
        </form>

        <template #footer>
            <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('forgot.back_to_login') }}</Link>
        </template>
    </AuthLayout>
</template>
