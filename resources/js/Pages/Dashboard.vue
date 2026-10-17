<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlertBox from '../Components/AlertBox.vue';
import Badge from '../Components/Badge.vue';
import { useT } from '../composables/useT';
import { formatWeekdayDate } from '../composables/dates';
import PractitionerAvatar from '../Components/PractitionerAvatar.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { onboardingStep, routes } from '../routes';

const statusVariant = { pending: 'warning', confirmed: 'success', completed: 'neutral', no_show: 'danger' } as const;

interface Appointment {
    id: string;
    starts: string;
    ends: string;
    customer: string | null;
    service: string | null;
    practitioner: string | null;
    practitioner_id: string | null;
    practitioner_photo: number | null;
    site: string | null;
    status: 'pending' | 'confirmed' | 'completed' | 'no_show';
    is_next: boolean;
    is_past: boolean;
}

interface Summary {
    date: string;
    counts: { today: number; remaining: number; completed: number; cancelled: number; tomorrow: number; week: number; new_customers: number | null };
    next: Appointment | null;
    appointments: Appointment[];
    truncated: boolean;
    multi_site: boolean;
}

defineProps<{
    onboarding: { slug: string } | null;
    profile: { complete: boolean } | null;
    summary: Summary;
    firstSteps: { key: 'whatsapp' | 'two_factor' | 'first_booking' | 'calendar' | 'billing'; done: boolean }[] | null;
    trial: { days: number } | null;
}>();

const { t, locale } = useT();

const stepLinks = {
    whatsapp: routes.whatsapp,
    two_factor: routes.security.show,
    first_booking: routes.planning,
    calendar: routes.calendars,
    billing: routes.billing,
} as const;

function formatDate(iso: string): string {
    return formatWeekdayDate(iso, locale.value);
}
</script>

<template>
    <AppLayout :title="t('dashboard.title')">
        <AlertBox v-if="onboarding" variant="warning" class="mb-6 max-w-2xl">
            <strong class="block">{{ t('onboarding.resume_title') }}</strong>
            {{ t('onboarding.resume_body') }}
            <Link :href="onboardingStep(onboarding.slug)" class="mt-2 inline-block font-semibold underline">{{ t('onboarding.resume') }}</Link>
        </AlertBox>

        <AlertBox v-if="profile && !profile.complete" variant="warning" class="mb-6 max-w-2xl">
            <strong class="block">{{ t('dashboard.profile_title') }}</strong>
            {{ t('dashboard.profile_body') }}
            <Link href="/mon-profil" class="mt-2 inline-block font-semibold underline">{{ t('dashboard.profile_action') }}</Link>
        </AlertBox>

        <AlertBox v-if="trial" variant="info" class="mb-6 max-w-2xl">
            {{ t('dashboard.trial_days', { days: trial.days }) }}
            <Link :href="routes.billing" class="ml-1 font-semibold underline">{{ t('dashboard.trial_action') }}</Link>
        </AlertBox>

        <h1 class="text-2xl font-semibold text-rendivoo-900">{{ t('dashboard.today_title') }}</h1>
        <p class="mt-1 text-sm capitalize text-neutral-600">{{ formatDate(summary.date) }}</p>

        <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-testid="counts">
            <div class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_today') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.today }}</dd>
            </div>
            <div class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_remaining') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.remaining }}</dd>
            </div>
            <div class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_cancelled') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.cancelled }}</dd>
            </div>
            <div class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_tomorrow') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.tomorrow }}</dd>
            </div>
            <div class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_week') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.week }}</dd>
            </div>
            <div v-if="summary.counts.new_customers !== null" class="rounded-card border border-line bg-surface shadow-xs p-4">
                <dt class="text-xs font-medium text-neutral-600">{{ t('dashboard.count_new_customers') }}</dt>
                <dd class="mt-1 font-display text-3xl font-bold text-rendivoo-900">{{ summary.counts.new_customers }}</dd>
            </div>
        </dl>

        <section class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="next">
            <h2 id="next" class="text-lg font-semibold text-rendivoo-900">{{ t('dashboard.next') }}</h2>
            <p v-if="summary.next" class="mt-2 text-neutral-800" data-testid="next">
                <strong>{{ summary.next.starts }}</strong>
                {{ summary.next.customer }} · {{ summary.next.service }}
                <span v-if="summary.next.practitioner" class="inline-flex items-center gap-1.5 align-middle text-neutral-600">
                    <PractitionerAvatar
                        v-if="summary.next.practitioner_id"
                        :id="summary.next.practitioner_id"
                        :name="summary.next.practitioner"
                        :version="summary.next.practitioner_photo"
                        size="xs"
                    />
                    {{ t('dashboard.with', { name: summary.next.practitioner }) }}
                </span>
            </p>
            <p v-else class="mt-2 text-sm text-neutral-600">{{ t('dashboard.next_none') }}</p>
        </section>

        <section class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="agenda">
            <div class="flex items-center justify-between gap-3">
                <h2 id="agenda" class="text-lg font-semibold text-rendivoo-900">{{ t('dashboard.today_title') }}</h2>
                <Link :href="routes.planning" class="text-sm font-semibold text-primary-700 underline">{{ t('dashboard.see_planning') }}</Link>
            </div>

            <p v-if="summary.appointments.length === 0" class="mt-3 text-sm text-neutral-600">{{ t('dashboard.today_empty') }}</p>
            <ul v-else class="mt-3 divide-y divide-line" data-testid="appointments">
                <li
                    v-for="appointment in summary.appointments"
                    :key="appointment.id"
                    class="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-3"
                    :class="{ 'opacity-60': appointment.is_past }"
                >
                    <span class="w-24 shrink-0 font-semibold text-rendivoo-900">{{ appointment.starts }}–{{ appointment.ends }}</span>
                    <span class="min-w-0 flex-1 text-neutral-800">
                        {{ appointment.customer }} · {{ appointment.service }}
                        <span v-if="appointment.practitioner" class="inline-flex items-center gap-1.5 align-middle text-neutral-600">
                            <PractitionerAvatar
                                v-if="appointment.practitioner_id"
                                :id="appointment.practitioner_id"
                                :name="appointment.practitioner"
                                :version="appointment.practitioner_photo"
                                size="xs"
                            />
                            {{ t('dashboard.with', { name: appointment.practitioner }) }}
                        </span>
                        <span v-if="summary.multi_site && appointment.site" class="text-neutral-600"> · {{ appointment.site }}</span>
                    </span>
                    <Badge :variant="statusVariant[appointment.status]">{{ t(`dashboard.status.${appointment.status}`) }}</Badge>
                </li>
            </ul>
            <p v-if="summary.truncated" class="mt-3 text-sm text-neutral-600">{{ t('dashboard.truncated', { count: summary.appointments.length }) }}</p>
        </section>

        <section v-if="firstSteps" class="mt-6 rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="steps">
            <h2 id="steps" class="text-lg font-semibold text-rendivoo-900">{{ t('dashboard.first_steps_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('dashboard.first_steps_body') }}</p>
            <ul class="mt-3 space-y-2" data-testid="first-steps">
                <li v-for="step in firstSteps" :key="step.key" class="flex items-center gap-3 text-sm">
                    <span :class="step.done ? 'text-success-600' : 'text-neutral-500'" aria-hidden="true">{{ step.done ? '✓' : '○' }}</span>
                    <span v-if="step.done" class="text-neutral-600 line-through">{{ t(`dashboard.steps.${step.key}`) }}</span>
                    <Link v-else :href="stepLinks[step.key]" class="font-medium text-primary-700 underline">{{ t(`dashboard.steps.${step.key}`) }}</Link>
                    <span v-if="step.done" class="sr-only">{{ t('dashboard.step_done') }}</span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
