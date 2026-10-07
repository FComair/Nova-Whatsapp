<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlertBox from '../../Components/AlertBox.vue';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { Wizard } from '../../types';

defineProps<{ wizard: Wizard }>();
const { t } = useT();
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('onboarding.pending.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t(`onboarding.steps.${wizard.step}`) }}</h1>

        <AlertBox variant="info" class="mt-4">
            <strong class="block">{{ t('onboarding.pending.title') }}</strong>
            {{ t('onboarding.pending.body') }}
        </AlertBox>

        <Link :href="routes.dashboard" class="mt-6 inline-block font-medium text-primary-700 hover:underline">{{ t('onboarding.pending.back') }}</Link>
    </OnboardingLayout>
</template>
