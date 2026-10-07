<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import BookingDetails from '../../Components/BookingDetails.vue';
import BookingForm from '../../Components/BookingForm.vue';
import ModalDialog from '../../Components/ModalDialog.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { blockStyle, hourMarks, minutesToTime, PIXELS_PER_MINUTE, shiftDate } from '../../composables/planning';
import { useT } from '../../composables/useT';
import { formatWeekdayDate } from '../../composables/dates';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { PlanningBooking, PlanningPractitioner, PlanningService, SharedProps } from '../../types';

const props = defineProps<{
    locations: { id: string; name: string }[];
    location: { id: string; name: string; timezone: string } | null;
    date?: string;
    today?: string;
    range?: { from: number; to: number };
    practitioners?: PlanningPractitioner[];
    bookings?: PlanningBooking[];
    services?: PlanningService[];
    canCreate?: boolean;
    seesNotes?: boolean;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();

const creating = ref(false);
const selectedId = ref<string | null>(null);
const selected = computed(() => props.bookings?.find((booking) => booking.id === selectedId.value) ?? null);

const range = computed(() => props.range ?? { from: 540, to: 1080 });
const gridHeight = computed(() => `${(range.value.to - range.value.from) * PIXELS_PER_MINUTE}px`);
const heading = computed(() => (props.date === undefined ? '' : formatWeekdayDate(props.date, locale.value)));

function visit(query: Record<string, string>): void {
    router.get(routes.planning, { location: props.location?.id, date: props.date, ...query }, { preserveScroll: true, preserveState: false });
}

function bookingsOf(practitionerId: string): PlanningBooking[] {
    return (props.bookings ?? []).filter((booking) => booking.practitioner_id === practitionerId);
}

function describe(booking: PlanningBooking): string {
    return `${booking.starts} – ${booking.ends}, ${booking.customer.name ?? booking.customer.phone ?? ''}, ${booking.service ?? ''}, ${t(`planning.status.${booking.status}`)}`;
}
</script>

<template>
    <AppLayout :title="t('planning.title')">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-2xl font-semibold text-nova-900">{{ t('planning.title') }}</h1>
            <BaseButton v-if="location && canCreate" @click="creating = true">{{ t('planning.new') }}</BaseButton>
        </div>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="page.props.flash.warning" variant="warning" class="mt-4">{{ page.props.flash.warning }}</AlertBox>
        <AlertBox v-if="!location" variant="info" class="mt-4">{{ t('planning.no_sites') }}</AlertBox>

        <template v-else>
            <div class="mt-5 flex flex-wrap items-end gap-3">
                <SelectField
                    v-if="locations.length > 1"
                    :model-value="location.id"
                    :label="t('planning.location')"
                    :options="locations.map((item) => ({ value: item.id, label: item.name }))"
                    @update:model-value="(id) => visit({ location: id })"
                />
                <div class="flex items-center gap-2">
                    <BaseButton variant="secondary" :aria-label="t('planning.previous_day')" @click="visit({ date: shiftDate(date!, -1) })">‹</BaseButton>
                    <TextField :model-value="date!" type="date" :label="t('planning.date')" @update:model-value="(value) => value && visit({ date: value })" />
                    <BaseButton variant="secondary" :aria-label="t('planning.next_day')" @click="visit({ date: shiftDate(date!, 1) })">›</BaseButton>
                </div>
                <BaseButton v-if="date !== today" variant="secondary" @click="visit({ date: today! })">{{ t('planning.today') }}</BaseButton>
            </div>

            <h2 class="mt-5 text-lg font-semibold capitalize text-nova-900">{{ heading }}</h2>

            <AlertBox v-if="!practitioners?.length" variant="info" class="mt-4">{{ t('planning.no_practitioners') }}</AlertBox>

            <div v-else class="mt-3 overflow-x-auto rounded-card border border-neutral-200 bg-white">
                <div class="flex min-w-max">
                    <div class="w-14 shrink-0 border-r border-neutral-200">
                        <div class="h-10 border-b border-neutral-200" />
                        <div class="relative" :style="{ height: gridHeight }">
                            <span
                                v-for="mark in hourMarks(range)"
                                :key="mark"
                                class="absolute right-2 -translate-y-1/2 text-xs text-neutral-500"
                                :style="{ top: `${(mark - range.from) * PIXELS_PER_MINUTE}px` }"
                            >
                                {{ minutesToTime(mark) }}
                            </span>
                        </div>
                    </div>

                    <section
                        v-for="practitioner in practitioners"
                        :key="practitioner.id"
                        class="w-48 shrink-0 border-r border-neutral-200 last:border-r-0 sm:w-56"
                        :aria-label="practitioner.name"
                    >
                        <h3 class="flex h-10 items-center gap-2 border-b border-neutral-200 px-3 text-sm font-semibold text-neutral-900">
                            <span class="size-3 shrink-0 rounded-full" :style="{ backgroundColor: practitioner.color }" aria-hidden="true" />
                            <span class="truncate">{{ practitioner.name }}</span>
                        </h3>
                        <div class="relative bg-neutral-100" :style="{ height: gridHeight }">
                            <div
                                v-for="(slot, index) in practitioner.working"
                                :key="index"
                                class="absolute inset-x-0 bg-white"
                                :style="blockStyle(range, slot.start, slot.end)"
                                :title="t('planning.working_hours')"
                            />
                            <span
                                v-for="mark in hourMarks(range)"
                                :key="mark"
                                class="pointer-events-none absolute inset-x-0 border-t border-neutral-200"
                                :style="{ top: `${(mark - range.from) * PIXELS_PER_MINUTE}px` }"
                            />
                            <button
                                v-for="booking in bookingsOf(practitioner.id)"
                                :key="booking.id"
                                type="button"
                                class="absolute inset-x-1 overflow-hidden rounded-md border-l-4 px-2 py-0.5 text-left text-xs shadow-sm hover:brightness-95 focus-visible:outline focus-visible:outline-2"
                                :class="
                                    booking.status === 'pending'
                                        ? 'border-dashed bg-neutral-50'
                                        : booking.status === 'confirmed'
                                          ? 'bg-primary-50'
                                          : 'bg-neutral-200 text-neutral-600'
                                "
                                :style="{ ...blockStyle(range, booking.start_minute, booking.end_minute), borderColor: practitioner.color }"
                                :aria-label="describe(booking)"
                                @click="selectedId = booking.id"
                            >
                                <span class="block truncate font-semibold">{{ booking.starts }} {{ booking.customer.name ?? booking.customer.phone }}</span>
                                <span class="block truncate">{{ booking.service }}</span>
                            </button>
                        </div>
                    </section>
                </div>
            </div>

            <p v-if="practitioners?.length && !bookings?.length" class="mt-3 text-sm text-neutral-600">{{ t('planning.empty_day') }}</p>
        </template>

        <ModalDialog v-if="location" :open="creating" :title="t('planning.form.title')" @close="creating = false">
            <BookingForm
                :location-id="location.id"
                :date="date!"
                :practitioners="practitioners ?? []"
                :services="services ?? []"
                :sees-notes="seesNotes ?? false"
                @done="creating = false"
            />
        </ModalDialog>

        <ModalDialog :open="selected !== null" :title="t('planning.actions.details')" @close="selectedId = null">
            <BookingDetails
                v-if="selected"
                :key="selected.id"
                :booking="selected"
                :date="date!"
                :practitioners="practitioners ?? []"
                @done="selectedId = null"
            />
        </ModalDialog>
    </AppLayout>
</template>
