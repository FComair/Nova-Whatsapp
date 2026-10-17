<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CalendarForm from '../../Components/CalendarForm.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { useT } from '../../composables/useT';
import { formatDateTime } from '../../composables/dates';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface CalendarItem {
    id: string;
    name: string;
    kind: 'ics' | 'caldav';
    status: 'active' | 'error' | 'paused';
    last_synced_at: string | null;
    last_error: string | null;
    unsupported_rules: number;
}

interface PractitionerCalendars {
    id: string;
    name: string;
    photo_version: number | null;
    feed_url: string;
    calendars: CalendarItem[];
}

defineProps<{ practitioners: PractitionerCalendars[]; limit: number }>();

const { t } = useT();
const page = usePage<SharedProps>();

const removing = ref<CalendarItem | null>(null);
const rotating = ref<PractitionerCalendars | null>(null);
const copied = ref<string | null>(null);
const adding = ref<string | null>(null);

const errors = (): string | undefined => (page.props.errors as Record<string, string | undefined>).calendar;

function lastSync(calendar: CalendarItem): string {
    return calendar.last_synced_at === null
        ? t('calendars.never_synced')
        : t('calendars.last_sync', {
              date: formatDateTime(calendar.last_synced_at),
          });
}

async function copy(practitioner: PractitionerCalendars): Promise<void> {
    try {
        await navigator.clipboard.writeText(practitioner.feed_url);
        copied.value = practitioner.id;
    } catch {
        copied.value = null;
    }
}

function sync(calendar: CalendarItem): void {
    router.post(`${routes.calendars}/${calendar.id}/synchroniser`, {}, { preserveScroll: true });
}

function toggle(calendar: CalendarItem): void {
    router.put(`${routes.calendars}/${calendar.id}/pause`, {}, { preserveScroll: true });
}

function remove(): void {
    if (removing.value) {
        router.delete(`${routes.calendars}/${removing.value.id}`, { preserveScroll: true, onFinish: () => (removing.value = null) });
    }
}

function rotate(): void {
    if (rotating.value) {
        router.post(`${routes.calendars}/flux/${rotating.value.id}/renouveler`, {}, { preserveScroll: true, onFinish: () => (rotating.value = null) });
    }
}
</script>

<template>
    <SettingsLayout :title="t('calendars.title')">
        <h1 class="text-2xl font-semibold text-rendivoo-900">{{ t('calendars.title') }}</h1>
        <p class="mt-2 max-w-3xl text-sm text-neutral-700">{{ t('calendars.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-3xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors()" variant="error" class="mt-4 max-w-3xl">{{ errors() }}</AlertBox>
        <AlertBox v-if="!practitioners.length" variant="info" class="mt-6 max-w-3xl">{{ t('calendars.no_practitioners') }}</AlertBox>

        <details class="mt-4 max-w-3xl rounded-card border border-line bg-surface shadow-xs p-4 text-sm text-neutral-700">
            <summary class="cursor-pointer font-medium text-neutral-900">{{ t('calendars.providers_title') }}</summary>
            <p class="mt-2">{{ t('calendars.providers') }}</p>
        </details>

        <section v-for="practitioner in practitioners" :key="practitioner.id" class="mt-8 max-w-3xl" :aria-label="practitioner.name">
            <h2 class="flex items-center gap-2.5 text-lg font-semibold text-rendivoo-900">
                <PractitionerAvatar :id="practitioner.id" :name="practitioner.name" :version="practitioner.photo_version" size="md" />
                {{ practitioner.name }}
            </h2>

            <p v-if="!practitioner.calendars.length" class="mt-3 text-sm text-neutral-600">{{ t('calendars.empty') }}</p>
            <ul v-else class="mt-3 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                <li v-for="calendar in practitioner.calendars" :key="calendar.id" class="space-y-2 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium text-neutral-900"
                            >{{ calendar.name }} <span class="text-xs font-normal text-neutral-500">· {{ t(`calendars.kinds.${calendar.kind}`) }}</span></span
                        >
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="
                                calendar.status === 'error'
                                    ? 'bg-danger-50 text-danger-700'
                                    : calendar.status === 'paused'
                                      ? 'bg-neutral-100 text-neutral-700'
                                      : 'bg-success-50 text-success-600'
                            "
                        >
                            {{ t(`calendars.status.${calendar.status}`) }}
                        </span>
                    </div>
                    <p class="text-sm text-neutral-600">{{ lastSync(calendar) }}</p>
                    <p v-if="calendar.last_error" class="text-sm text-danger-700">
                        {{ t('calendars.last_error', { error: t(`calendars.errors.${calendar.last_error}`) }) }}
                    </p>
                    <p v-if="calendar.unsupported_rules > 0" class="text-sm text-warning-600">{{ t('calendars.unsupported') }}</p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <BaseButton variant="secondary" @click="sync(calendar)">
                            {{ t('calendars.sync_now') }}
                        </BaseButton>
                        <BaseButton variant="secondary" @click="toggle(calendar)">
                            {{ calendar.status === 'paused' ? t('calendars.resume') : t('calendars.pause') }}
                        </BaseButton>
                        <BaseButton variant="danger" @click="removing = calendar">{{ t('calendars.remove') }}</BaseButton>
                    </div>
                </li>
            </ul>

            <div class="mt-4">
                <BaseButton v-if="adding !== practitioner.id && practitioner.calendars.length < limit" variant="secondary" @click="adding = practitioner.id">
                    {{ t('calendars.add') }}
                </BaseButton>
                <div v-if="adding === practitioner.id" class="rounded-card border border-line bg-surface shadow-xs p-4">
                    <h3 class="mb-3 font-semibold text-neutral-900">{{ t('calendars.add') }}</h3>
                    <CalendarForm :practitioner-id="practitioner.id" />
                </div>
            </div>

            <div class="mt-6 rounded-card border border-line bg-neutral-50 p-4">
                <h3 class="font-semibold text-neutral-900">{{ t('calendars.feed_title') }}</h3>
                <p class="mt-1 text-sm text-neutral-600">{{ t('calendars.feed_help') }}</p>
                <input
                    :value="practitioner.feed_url"
                    readonly
                    class="mt-3 block w-full rounded-control border border-line-strong bg-surface px-3 py-2 text-sm"
                    :aria-label="t('calendars.feed_title')"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                    <BaseButton variant="secondary" @click="copy(practitioner)">
                        {{ copied === practitioner.id ? t('calendars.feed_copied') : t('calendars.feed_copy') }}
                    </BaseButton>
                    <BaseButton variant="secondary" @click="rotating = practitioner">{{ t('calendars.feed_rotate') }}</BaseButton>
                </div>
            </div>
        </section>

        <ConfirmDialog
            :open="removing !== null"
            :title="t('calendars.remove_title')"
            :body="t('calendars.remove_body')"
            :confirm-label="t('calendars.remove')"
            @confirm="remove"
            @cancel="removing = null"
        />
        <ConfirmDialog
            :open="rotating !== null"
            :title="t('calendars.feed_rotate_title')"
            :body="t('calendars.feed_rotate_body')"
            :confirm-label="t('calendars.feed_rotate')"
            @confirm="rotate"
            @cancel="rotating = null"
        />
    </SettingsLayout>
</template>
