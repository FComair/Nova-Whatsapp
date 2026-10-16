<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import BaseButton from '../../Components/BaseButton.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { Wizard } from '../../types';

const props = defineProps<{
    wizard: Wizard;
    company: { name: string; sector: string; country: string; timezone: string; locale: string };
    sectors: { value: string; label: string }[];
    countries: string[];
    /** Fuseaux IANA proposés pour chaque pays (le fuseau principal en premier). */
    timezones: Record<string, string[]>;
}>();

const { t } = useT();
const form = useForm({ ...props.company });

const sectorOptions = computed(() => props.sectors);
const countryOptions = computed(() => props.countries.map((value) => ({ value, label: t(`onboarding.company.countries.${value}`) })));
const timezoneOptions = computed(() => (props.timezones[form.country] ?? []).map((value) => ({ value, label: value.replaceAll('_', ' ') })));
// Changer de pays propose les fuseaux de ce pays ; l'ancien choix est remplacé s'il n'en fait pas partie.
watch(
    () => form.country,
    (country) => {
        const zones = props.timezones[country] ?? [];

        if (!zones.includes(form.timezone)) {
            form.timezone = zones[0] ?? '';
        }
    },
);
const localeOptions = computed(() => (['fr', 'nl', 'en'] as const).map((value) => ({ value, label: t(`common.languages.${value}`) })));
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('onboarding.company.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('onboarding.company.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('onboarding.company.body') }}</p>

        <form class="mt-6 space-y-5" novalidate @submit.prevent="form.post(`${routes.onboarding}/entreprise`)">
            <TextField v-model="form.name" :label="t('onboarding.company.name')" autocomplete="organization" required :error="form.errors.name" />
            <SelectField v-model="form.sector" :label="t('onboarding.company.sector')" :options="sectorOptions" required :error="form.errors.sector" />
            <SelectField v-model="form.country" :label="t('onboarding.company.country')" :options="countryOptions" required :error="form.errors.country" />
            <div>
                <SelectField
                    v-model="form.timezone"
                    :label="t('onboarding.company.timezone')"
                    :options="timezoneOptions"
                    required
                    :error="form.errors.timezone"
                />
                <p class="mt-1.5 text-sm text-neutral-600">{{ t('onboarding.company.timezone_hint') }}</p>
            </div>
            <SelectField v-model="form.locale" :label="t('onboarding.company.locale')" :options="localeOptions" required :error="form.errors.locale" />

            <BaseButton type="submit" :loading="form.processing">{{ t('onboarding.continue') }}</BaseButton>
        </form>
    </OnboardingLayout>
</template>
