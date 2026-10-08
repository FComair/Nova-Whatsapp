<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{ sectors: { value: string; label: string }[]; plan: string }>();
const { t } = useT();

const form = useForm({
    email: '',
    password: '',
    phone: '',
    sector: '',
    plan: props.plan,
    accept_terms: false,
    accept_privacy: false,
    accept_dpa: false,
});

const sectorOptions = computed(() => props.sectors);

function submit(): void {
    form.post(routes.register, { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthLayout :title="t('register.title')" :subtitle="t('register.subtitle')">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <p class="rounded-control bg-primary-50 px-3 py-2 text-sm text-nova-700">
                {{ t('register.plan') }} : <strong>{{ t(`register.plans.${form.plan}`) }}</strong>
            </p>

            <TextField
                v-model="form.email"
                type="email"
                :label="t('register.email')"
                autocomplete="email"
                inputmode="email"
                required
                :error="form.errors.email"
            />
            <TextField
                v-model="form.password"
                type="password"
                :label="t('register.password')"
                :hint="t('register.password_hint')"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />
            <TextField
                v-model="form.phone"
                type="tel"
                :label="t('register.phone')"
                :hint="t('register.phone_hint')"
                autocomplete="tel"
                inputmode="tel"
                required
                :error="form.errors.phone"
            />
            <SelectField
                v-model="form.sector"
                :label="t('register.sector')"
                :placeholder="t('register.sector_placeholder')"
                :options="sectorOptions"
                required
                :error="form.errors.sector"
            />

            <div class="space-y-3">
                <CheckboxField v-model="form.accept_terms" :label="t('register.accept_terms')" :error="form.errors.accept_terms" />
                <CheckboxField v-model="form.accept_privacy" :label="t('register.accept_privacy')" :error="form.errors.accept_privacy" />
                <CheckboxField v-model="form.accept_dpa" :label="t('register.accept_dpa')" :error="form.errors.accept_dpa" />
            </div>

            <BaseButton type="submit" :loading="form.processing" class="w-full">{{ t('register.submit') }}</BaseButton>
        </form>

        <template #footer>
            {{ t('register.have_account') }}
            <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('login.submit') }}</Link>
        </template>
    </AuthLayout>
</template>
