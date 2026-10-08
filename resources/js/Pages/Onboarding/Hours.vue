<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import WeekEditor from '../../Components/WeekEditor.vue';
import { toDays, toPayload, type DayState, type TimeRange } from '../../composables/hours';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { onboardingStep, routes } from '../../routes';
import type { Wizard } from '../../types';

const props = defineProps<{
    wizard: Wizard;
    locations: { id: string; name: string; configured: boolean }[];
    selectedId: string;
    week: Record<string, TimeRange[]>;
}>();

const { t } = useT();
const days = ref<DayState[]>(toDays(props.week));
const form = useForm({ hours: toPayload(days.value) });

// Changement de site : on recharge les horaires du site sélectionné.
watch(
    () => [props.selectedId, props.week] as const,
    () => {
        days.value = toDays(props.week);
        form.clearErrors();
    },
);

// Erreurs de validation rangées par jour (clés « hours.0.ranges.1.end »).
const errorsByDay = computed(() =>
    Array.from({ length: 7 }, (_unused, index) =>
        Object.entries(form.errors)
            .filter(([key]) => key.startsWith(`hours.${index}.`))
            .map(([, message]) => message),
    ),
);

const globalError = computed(() => (form.errors as Record<string, string>).hours);

function save(): void {
    form.hours = toPayload(days.value);
    form.put(`${routes.onboarding}/horaires/${props.selectedId}`, { preserveScroll: true });
}
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('onboarding.hours.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('onboarding.hours.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('onboarding.hours.body') }}</p>

        <nav v-if="locations.length > 1" class="mt-5" :aria-label="t('onboarding.hours.site')">
            <ul class="flex flex-wrap gap-2">
                <li v-for="site in locations" :key="site.id">
                    <Link
                        :href="onboardingStep('horaires', { site: site.id })"
                        class="inline-flex items-center gap-2 rounded-control border px-3 py-1.5 text-sm font-medium"
                        :class="
                            site.id === selectedId ? 'border-primary-600 bg-primary-50 text-nova-900' : 'border-line-strong text-neutral-700 hover:bg-surface-2'
                        "
                        :aria-current="site.id === selectedId ? 'page' : undefined"
                    >
                        {{ site.name }}
                        <span class="text-xs" :class="site.configured ? 'text-success-600' : 'text-warning-600'">
                            {{ site.configured ? '✓ ' + t('onboarding.hours.configured') : '! ' + t('onboarding.hours.missing') }}
                        </span>
                    </Link>
                </li>
            </ul>
        </nav>

        <AlertBox v-if="globalError" variant="error" class="mt-4">{{ globalError }}</AlertBox>

        <form class="mt-6" novalidate @submit.prevent="save">
            <WeekEditor v-model="days" :errors-by-day="errorsByDay" />

            <div class="mt-6">
                <BaseButton type="submit" :loading="form.processing">{{ t('onboarding.continue') }}</BaseButton>
            </div>
        </form>
    </OnboardingLayout>
</template>
