<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';

const props = defineProps<{ token: string; organization: string; name: string; email: string }>();
const { t } = useT();

const form = useForm({ password: '', password_confirmation: '', accept_terms: false, accept_privacy: false });

function submit(): void {
    form.post(`/invitation/${props.token}`, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout :title="t('invitation.title', { organization })" :subtitle="t('invitation.body', { name })">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <!-- L'adresse est celle de l'invitation : elle n'est pas modifiable. -->
            <div>
                <p class="mb-1.5 text-sm font-medium text-neutral-800">{{ t('invitation.email') }}</p>
                <p class="rounded-lg bg-neutral-100 px-3 py-2.5 text-base text-neutral-800">{{ email }}</p>
            </div>

            <TextField
                v-model="form.password"
                type="password"
                :label="t('invitation.password')"
                :hint="t('register.password_hint')"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />
            <TextField v-model="form.password_confirmation" type="password" :label="t('invitation.password_confirm')" autocomplete="new-password" required />

            <div class="space-y-3">
                <CheckboxField v-model="form.accept_terms" :label="t('register.accept_terms')" :error="form.errors.accept_terms" />
                <CheckboxField v-model="form.accept_privacy" :label="t('register.accept_privacy')" :error="form.errors.accept_privacy" />
            </div>

            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('invitation.submit') }}</BaseButton>
        </form>
    </AuthLayout>
</template>
