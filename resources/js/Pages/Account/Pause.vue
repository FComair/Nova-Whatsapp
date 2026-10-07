<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import TextareaField from '../../Components/TextareaField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

const props = defineProps<{
    status: string;
    canPause: boolean;
    maxDays: number;
    defaultDays: number;
    pauseUntil: string | null;
    message: string | null;
    upcoming: number;
    expired: boolean;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const until = computed(() => (props.pauseUntil ? formatDate(props.pauseUntil) : ''));

const form = useForm({ days: String(props.defaultDays), message: '', password: '', confirm: false, cancel_bookings: false });

function resume(): void {
    router.delete(routes.accountPause);
}
</script>

<template>
    <SettingsLayout :title="t('pause.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('pause.title') }}</h1>

        <AlertBox v-if="errors.pause" variant="error" class="mt-4 max-w-2xl">{{ errors.pause }}</AlertBox>

        <section v-if="status === 'paused'" class="mt-6 max-w-2xl rounded-card border border-neutral-200 bg-white p-5">
            <p class="text-sm text-neutral-800">{{ t('pause.current', { date: until }) }}</p>
            <p v-if="message" class="mt-2 text-sm text-neutral-700">{{ message }}</p>
            <div class="mt-4">
                <BaseButton @click="resume">{{ t('pause.resume') }}</BaseButton>
            </div>
        </section>

        <template v-else-if="canPause">
            <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('pause.intro') }}</p>

            <section class="mt-6 max-w-2xl rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="effects">
                <h2 id="effects" class="text-lg font-semibold text-nova-900">{{ t('pause.effects_title') }}</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-neutral-700">
                    <li>{{ t('pause.effect_dashboard') }}</li>
                    <li>{{ t('pause.effect_bot') }}</li>
                    <li>{{ t('pause.effect_reminders') }}</li>
                    <li>{{ t('pause.effect_billing') }}</li>
                </ul>
            </section>

            <AlertBox v-if="upcoming > 0" variant="warning" class="mt-4 max-w-2xl">
                {{ t('pause.upcoming', { count: upcoming, max: maxDays }) }}
                <Link :href="routes.planning" class="font-semibold underline">{{ t('planning.nav') }}</Link>
            </AlertBox>

            <form
                class="mt-6 max-w-2xl space-y-4 rounded-card border border-neutral-200 bg-white p-5"
                novalidate
                @submit.prevent="form.post(routes.accountPause)"
            >
                <TextField v-model="form.days" inputmode="numeric" :label="t('pause.days', { max: maxDays })" required :error="form.errors.days" />
                <TextareaField v-model="form.message" :label="t('pause.message')" :hint="t('pause.message_hint')" :error="form.errors.message" />
                <TextField
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    :label="t('pause.password')"
                    required
                    :error="form.errors.password"
                />
                <CheckboxField v-if="upcoming > 0" v-model="form.cancel_bookings" :label="t('pause.cancel_bookings')" />
                <CheckboxField v-model="form.confirm" :label="t('pause.confirm')" :error="form.errors.confirm" />
                <BaseButton type="submit" :loading="form.processing">{{ t('pause.submit') }}</BaseButton>
            </form>
        </template>
    </SettingsLayout>
</template>
