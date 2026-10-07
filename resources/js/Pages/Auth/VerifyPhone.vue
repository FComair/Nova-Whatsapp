<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{ phoneMasked: string; codeLength: number }>();
const { t } = useT();

const form = useForm({ code: '' });
const resend = useForm({});

function submit(): void {
    form.post(routes.phoneVerify, { onFinish: () => form.reset('code') });
}
</script>

<template>
    <AuthLayout :title="t('verify.phone_title')" :subtitle="t('verify.phone_body', { length: props.codeLength, phone: phoneMasked })">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <TextField
                v-model="form.code"
                :label="t('verify.code_label')"
                inputmode="numeric"
                autocomplete="one-time-code"
                :maxlength="props.codeLength"
                required
                :error="form.errors.code"
            />
            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('verify.code_submit') }}</BaseButton>
        </form>

        <form class="mt-4" @submit.prevent="resend.post(routes.phoneSend)">
            <BaseButton type="submit" variant="secondary" :loading="resend.processing" class="w-full">{{ t('verify.code_resend') }}</BaseButton>
        </form>
    </AuthLayout>
</template>
