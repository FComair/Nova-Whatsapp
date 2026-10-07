<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import TextField from '../../Components/TextField.vue';
import { centsToInput } from '../../composables/money';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { Wizard } from '../../types';

const props = defineProps<{
    wizard: Wizard;
    policy: {
        min_notice_hours: number;
        max_horizon_days: number;
        cancellation_window_hours: number;
        no_show_fee_cents: number;
        allow_overbooking: boolean;
        hold_minutes: number;
        reminder_hours_before: number;
    };
}>();

const { t, locale } = useT();

// Les nombres sont saisis sous forme de texte ; le serveur les valide et les convertit.
const form = useForm({
    min_notice_hours: String(props.policy.min_notice_hours),
    max_horizon_days: String(props.policy.max_horizon_days),
    cancellation_window_hours: String(props.policy.cancellation_window_hours),
    no_show_fee: centsToInput(props.policy.no_show_fee_cents, locale.value),
    allow_overbooking: props.policy.allow_overbooking,
    hold_minutes: String(props.policy.hold_minutes),
    reminder_hours_before: String(props.policy.reminder_hours_before),
});
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('policies.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('policies.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('policies.body') }}</p>

        <form class="mt-6 space-y-5" novalidate @submit.prevent="form.post(`${routes.onboarding}/politiques`)">
            <TextField
                v-model="form.min_notice_hours"
                inputmode="numeric"
                :label="t('policies.fields.min_notice')"
                :hint="t('policies.fields.min_notice_hint')"
                required
                :error="form.errors.min_notice_hours"
            />
            <TextField
                v-model="form.max_horizon_days"
                inputmode="numeric"
                :label="t('policies.fields.max_horizon')"
                :hint="t('policies.fields.max_horizon_hint')"
                required
                :error="form.errors.max_horizon_days"
            />
            <TextField
                v-model="form.cancellation_window_hours"
                inputmode="numeric"
                :label="t('policies.fields.cancellation_window')"
                :hint="t('policies.fields.cancellation_window_hint')"
                required
                :error="form.errors.cancellation_window_hours"
            />
            <TextField
                v-model="form.no_show_fee"
                inputmode="decimal"
                :label="t('policies.fields.no_show_fee')"
                :hint="t('policies.fields.no_show_fee_hint')"
                required
                :error="form.errors.no_show_fee"
            />
            <TextField
                v-model="form.reminder_hours_before"
                inputmode="numeric"
                :label="t('policies.fields.reminder')"
                :hint="t('policies.fields.reminder_hint')"
                required
                :error="form.errors.reminder_hours_before"
            />
            <TextField
                v-model="form.hold_minutes"
                inputmode="numeric"
                :label="t('policies.fields.hold')"
                :hint="t('policies.fields.hold_hint')"
                required
                :error="form.errors.hold_minutes"
            />
            <div>
                <CheckboxField v-model="form.allow_overbooking" :label="t('policies.fields.allow_overbooking')" :error="form.errors.allow_overbooking" />
                <p class="mt-1.5 pl-8 text-sm text-neutral-600">{{ t('policies.fields.allow_overbooking_hint') }}</p>
            </div>

            <BaseButton type="submit" :loading="form.processing">{{ t('onboarding.continue') }}</BaseButton>
        </form>
    </OnboardingLayout>
</template>
