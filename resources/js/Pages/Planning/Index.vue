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
import { formatDate, formatWeekdayDate } from '../../composables/dates';
import { blockStyle, hourMarks, isoWeekday, layoutLanes, minutesToTime, PIXELS_PER_MINUTE, shiftDate, visibleDays } from '../../composables/planning';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { PlanningBooking, PlanningPractitioner, PlanningService, PlanningWeek, SharedProps } from '../../types';

const props = defineProps<{
    locations: { id: string; name: string }[];
    location: { id: string; name: string; timezone: string } | null;
    date?: string;
    today?: string;
    range?: { from: number; to: number };
    week?: PlanningWeek;
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

/** Filtre de praticien (vide = tous) : purement visuel, la semaine complète reste chargée. */
const practitionerFilter = ref('');

const range = computed(() => props.range ?? { from: 540, to: 1080 });
const gridHeight = computed(() => `${(range.value.to - range.value.from) * PIXELS_PER_MINUTE}px`);
const weekLabel = computed(() => (props.week === undefined ? '' : `${formatDate(props.week.start)} – ${formatDate(props.week.end)}`));
const practitionerById = computed(() => new Map((props.practitioners ?? []).map((practitioner) => [practitioner.id, practitioner])));

const visibleBookings = computed(() =>
    (props.bookings ?? []).filter((booking) => practitionerFilter.value === '' || booking.practitioner_id === practitionerFilter.value),
);

/** Les rendez-vous de chaque jour de la semaine, avec leur colonne quand ils se chevauchent. */
/**
 * Seuls les jours d'ouverture du site où le praticien (ou l'un des praticiens) travaille d'après son profil sont affichés.
 * Les heures en blanc sont celles du praticien filtré, ou celles d'ouverture du site quand tous sont affichés.
 */
const days = computed(() => {
    const bookedDates = new Set(visibleBookings.value.map((booking) => booking.date));
    const chosen = practitionerById.value.get(practitionerFilter.value);

    return visibleDays(props.week?.days ?? [], props.practitioners ?? [], bookedDates, practitionerFilter.value).map((day) => {
        const bookings = visibleBookings.value.filter((booking) => booking.date === day.date);
        const working = chosen?.schedule[String(isoWeekday(day.date))] ?? day.open;

        return { ...day, working, bookings, lanes: layoutLanes(bookings) };
    });
});

function weekday(date: string): string {
    return new Intl.DateTimeFormat(locale.value, { weekday: 'short' }).format(new Date(`${date}T12:00:00`));
}

function visit(query: Record<string, string>): void {
    router.get(routes.planning, { location: props.location?.id, date: props.date, ...query }, { preserveScroll: true, preserveState: false });
}

function bookingStyle(booking: PlanningBooking, lanes: Record<string, { lane: number; lanes: number }>): Record<string, string> {
    const { lane, lanes: count } = lanes[booking.id] ?? { lane: 0, lanes: 1 };

    return {
        ...blockStyle(range.value, booking.start_minute, booking.end_minute),
        left: `calc(${(lane / count) * 100}% + 2px)`,
        width: `calc(${100 / count}% - 4px)`,
        borderColor: practitionerById.value.get(booking.practitioner_id)?.color ?? '#9ca3af',
    };
}

function describe(booking: PlanningBooking): string {
    const practitioner = practitionerById.value.get(booking.practitioner_id)?.name ?? '';

    return `${formatDate(booking.date)} ${booking.starts} – ${booking.ends}, ${booking.customer.name ?? booking.customer.phone ?? ''}, ${booking.service ?? ''}, ${practitioner}, ${t(`planning.status.${booking.status}`)}`;
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
                <SelectField
                    v-if="(practitioners?.length ?? 0) > 1"
                    v-model="practitionerFilter"
                    :label="t('planning.practitioner')"
                    :options="[
                        { value: '', label: t('planning.all_practitioners') },
                        ...(practitioners ?? []).map((item) => ({ value: item.id, label: item.name })),
                    ]"
                />
                <!-- Les flèches sont alignées sur le champ de date lui-même (pas sur son libellé) : même hauteur, bas aligné. -->
                <div class="flex items-end gap-2">
                    <BaseButton
                        variant="secondary"
                        class="shrink-0"
                        style="min-height: 46px"
                        :aria-label="t('planning.previous_week')"
                        @click="visit({ date: shiftDate(date!, -7) })"
                    >
                        ‹
                    </BaseButton>
                    <TextField :model-value="date!" type="date" :label="t('planning.date')" @update:model-value="(value) => value && visit({ date: value })" />
                    <BaseButton
                        variant="secondary"
                        class="shrink-0"
                        style="min-height: 46px"
                        :aria-label="t('planning.next_week')"
                        @click="visit({ date: shiftDate(date!, 7) })"
                    >
                        ›
                    </BaseButton>
                </div>
                <BaseButton
                    v-if="week && (today! < week.start || today! > week.end)"
                    variant="secondary"
                    style="min-height: 46px"
                    @click="visit({ date: today! })"
                >
                    {{ t('planning.this_week') }}
                </BaseButton>
            </div>

            <h2 class="mt-5 text-lg font-semibold text-nova-900">{{ weekLabel }}</h2>

            <AlertBox v-if="!practitioners?.length" variant="info" class="mt-4">{{ t('planning.no_practitioners') }}</AlertBox>

            <template v-else>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-700" :aria-label="t('planning.practitioner')">
                    <li v-for="practitioner in practitioners" :key="practitioner.id" class="flex items-center gap-1.5">
                        <span class="size-3 shrink-0 rounded-full" :style="{ backgroundColor: practitioner.color }" aria-hidden="true" />
                        {{ practitioner.name }}
                    </li>
                </ul>

                <div class="mt-3 overflow-x-auto rounded-card border border-neutral-200 bg-white">
                    <div class="flex min-w-[52rem]">
                        <div class="w-14 shrink-0 border-r border-neutral-200">
                            <div class="h-12 border-b border-neutral-200" />
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
                            v-for="day in days"
                            :key="day.date"
                            class="min-w-0 flex-1 basis-0 border-r border-neutral-200 last:border-r-0"
                            :aria-label="formatWeekdayDate(day.date, locale)"
                        >
                            <h3 class="h-12 border-b border-neutral-200">
                                <button
                                    type="button"
                                    class="flex size-full flex-col items-center justify-center text-sm leading-tight hover:bg-neutral-50 focus-visible:outline focus-visible:outline-2"
                                    :class="day.date === today ? 'bg-primary-50 text-primary-700' : 'text-neutral-900'"
                                    :aria-current="day.date === date ? 'date' : undefined"
                                    @click="visit({ date: day.date })"
                                >
                                    <span class="text-xs font-medium capitalize">{{ weekday(day.date) }}</span>
                                    <span :class="day.date === date ? 'font-bold underline underline-offset-4' : 'font-semibold'">{{
                                        formatDate(day.date)
                                    }}</span>
                                </button>
                            </h3>
                            <div class="relative bg-neutral-100" :style="{ height: gridHeight }">
                                <div
                                    v-for="(slot, index) in day.working"
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
                                    v-for="booking in day.bookings"
                                    :key="booking.id"
                                    type="button"
                                    class="absolute overflow-hidden rounded-md border-l-4 px-1.5 py-0.5 text-left text-xs shadow-sm hover:brightness-95 focus-visible:outline focus-visible:outline-2"
                                    :class="
                                        booking.status === 'pending'
                                            ? 'border-dashed bg-neutral-50'
                                            : booking.status === 'confirmed'
                                              ? 'bg-primary-50'
                                              : 'bg-neutral-200 text-neutral-600'
                                    "
                                    :style="bookingStyle(booking, day.lanes)"
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

                <p v-if="!visibleBookings.length" class="mt-3 text-sm text-neutral-600">{{ t('planning.empty_week') }}</p>
            </template>
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
                :date="selected.date"
                :practitioners="practitioners ?? []"
                @done="selectedId = null"
            />
        </ModalDialog>
    </AppLayout>
</template>
