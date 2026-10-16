<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import BookingDetails from '../../Components/BookingDetails.vue';
import BookingForm from '../../Components/BookingForm.vue';
import CurrentTimeAxisLabel from '../../Components/CurrentTimeAxisLabel.vue';
import CurrentTimeLine from '../../Components/CurrentTimeLine.vue';
import ModalDialog from '../../Components/ModalDialog.vue';
import PractitionerAvatar from '../../Components/PractitionerAvatar.vue';
import PractitionerSelect from '../../Components/PractitionerSelect.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { formatDate, formatWeekdayDate } from '../../composables/dates';
import { differsFrom, zoneLabel } from '../../composables/timezone';
import { blockStyle, hourMarks, isoWeekday, layoutLanes, minutesToTime, PIXELS_PER_MINUTE, shiftDate, visibleDays } from '../../composables/planning';
import { columnMode, gridMinWidth, hidesClosedDays, PLANNING_VIEWS, parseView, stepDays, stepKey } from '../../composables/planningViews';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { PlanningBooking, PlanningPractitioner, PlanningService, PlanningWeek, SharedProps } from '../../types';

const props = defineProps<{
    locations: { id: string; name: string }[];
    location: { id: string; name: string; timezone: string } | null;
    date?: string;
    view?: string;
    today?: string;
    range?: { from: number; to: number };
    week?: PlanningWeek;
    practitioners?: PlanningPractitioner[];
    bookings?: PlanningBooking[];
    services?: PlanningService[];
    canCreate?: boolean;
    canReassign?: boolean;
    can?: { reschedule: boolean; cancel: boolean; complete: boolean; no_show: boolean; payment: boolean };
    seesNotes?: boolean;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();

const creating = ref(false);
const selectedId = ref<string | null>(null);
const selected = computed(() => props.bookings?.find((booking) => booking.id === selectedId.value) ?? null);

const view = computed(() => parseView(props.view));

/** Filtres mémorisés le temps de la session : ils survivent au changement de jour, de semaine ou de vue (la page est rechargée). */
const FILTERS_KEY = 'nova.planning.filters';

function readFilters(): { practitioner: string; chosen: string[] } {
    const known = new Set((props.practitioners ?? []).map((practitioner) => practitioner.id));

    try {
        const stored = JSON.parse(sessionStorage.getItem(FILTERS_KEY) ?? '{}') as { practitioner?: string; chosen?: string[] };

        return {
            practitioner: stored.practitioner !== undefined && known.has(stored.practitioner) ? stored.practitioner : '',
            chosen: (stored.chosen ?? []).filter((id) => known.has(id)),
        };
    } catch {
        return { practitioner: '', chosen: [] };
    }
}

const initial = readFilters();

/** Filtre de praticien des vues Jour, 3 jours et Semaine (vide = tous) : purement visuel, les jours affichés restent chargés. */
const practitionerFilter = ref(initial.practitioner);

/** Praticiens choisis dans la vue « Praticiens » (vide = tous). */
const chosenPractitioners = ref<string[]>(initial.chosen);

watch([practitionerFilter, chosenPractitioners], () => {
    try {
        sessionStorage.setItem(FILTERS_KEY, JSON.stringify({ practitioner: practitionerFilter.value, chosen: chosenPractitioners.value }));
    } catch {
        // Stockage indisponible (navigation privée) : les filtres valent pour cette page seulement.
    }
});

function togglePractitioner(id: string): void {
    chosenPractitioners.value = chosenPractitioners.value.includes(id)
        ? chosenPractitioners.value.filter((item) => item !== id)
        : [...chosenPractitioners.value, id];
}

/** Avatar du praticien sur les cartes : utile quand plusieurs praticiens partagent la même colonne de jour. */
const showCardAvatar = computed(() => mode.value === 'days' && (props.practitioners?.length ?? 0) > 1);

const range = computed(() => props.range ?? { from: 540, to: 1080 });
const gridHeight = computed(() => `${(range.value.to - range.value.from) * PIXELS_PER_MINUTE}px`);
const practitionerById = computed(() => new Map((props.practitioners ?? []).map((practitioner) => [practitioner.id, practitioner])));

/** Praticiens affichés par la vue « Praticiens » : ceux qu'on a choisis, ou tous. */
const shownPractitioners = computed(() => {
    const all = props.practitioners ?? [];

    return chosenPractitioners.value.length === 0 ? all : all.filter((practitioner) => chosenPractitioners.value.includes(practitioner.id));
});
const mode = computed(() => columnMode(view.value, shownPractitioners.value.length));
const step = computed(() => stepDays(view.value, shownPractitioners.value.length));
const stepLabelKey = computed(() => stepKey(view.value, shownPractitioners.value.length));

/** Filtre appliqué aux colonnes de jours : le praticien unique de la vue « Praticiens », sinon la liste déroulante. */
const dayFilter = computed(() =>
    view.value === 'practitioners' ? (shownPractitioners.value.length === 1 ? shownPractitioners.value[0]!.id : '') : practitionerFilter.value,
);

const visibleBookings = computed(() => {
    const shown = new Set(shownPractitioners.value.map((practitioner) => practitioner.id));

    return (props.bookings ?? []).filter((booking) =>
        mode.value === 'practitioners' ? shown.has(booking.practitioner_id) : dayFilter.value === '' || booking.practitioner_id === dayFilter.value,
    );
});

interface Column {
    key: string;
    date: string;
    working: { start: number; end: number }[];
    bookings: PlanningBooking[];
    lanes: Record<string, { lane: number; lanes: number }>;
    practitioner: PlanningPractitioner | null;
}

/**
 * Colonnes de la grille. Jours (Jour, 3 jours, Semaine, ou un seul praticien) : les heures en blanc sont celles du praticien filtré,
 * ou celles d'ouverture du site ; la semaine et la vue « Praticiens » masquent les jours sans horaires. Praticiens (plusieurs
 * affichés) : une colonne par praticien pour le jour choisi, avec ses propres heures de travail.
 */
const columns = computed<Column[]>(() => {
    if (mode.value === 'practitioners') {
        const date = props.date ?? '';

        return shownPractitioners.value.map((practitioner) => {
            const bookings = visibleBookings.value.filter((booking) => booking.practitioner_id === practitioner.id && booking.date === date);

            return {
                key: practitioner.id,
                date,
                working: practitioner.schedule[String(isoWeekday(date))] ?? [],
                bookings,
                lanes: layoutLanes(bookings),
                practitioner,
            };
        });
    }

    const bookedDates = new Set(visibleBookings.value.map((booking) => booking.date));
    const chosen = practitionerById.value.get(dayFilter.value);
    const all = props.week?.days ?? [];
    const shown = hidesClosedDays(view.value) ? visibleDays(all, props.practitioners ?? [], bookedDates, dayFilter.value) : all;

    return shown.map((day) => {
        const bookings = visibleBookings.value.filter((booking) => booking.date === day.date);

        return {
            key: day.date,
            date: day.date,
            working: chosen?.schedule[String(isoWeekday(day.date))] ?? day.open,
            bookings,
            lanes: layoutLanes(bookings),
            practitioner: null,
        };
    });
});

/** Titre de la période affichée. */
const heading = computed(() => {
    if (props.week === undefined || props.date === undefined) {
        return '';
    }

    if (view.value === 'day' || mode.value === 'practitioners') {
        return formatWeekdayDate(props.date, locale.value);
    }

    const period = `${formatDate(props.week.start)} – ${formatDate(props.week.end)}`;
    const only = view.value === 'practitioners' ? shownPractitioners.value[0] : undefined;

    return only === undefined ? period : `${only.name} · ${period}`;
});

/** « Aujourd'hui » n'apparaît que quand la période affichée ne contient pas déjà aujourd'hui. */
const showToday = computed(() => {
    if (props.week === undefined || props.today === undefined || props.date === undefined) {
        return false;
    }

    return mode.value === 'practitioners' ? props.today !== props.date : props.today < props.week.start || props.today > props.week.end;
});

/** Défilement vertical de la grille (la marge de 2 h reste visible au-dessus, l'utilisateur garde la main ensuite). */
const scroller = ref<HTMLElement | null>(null);

/** Horaires d'ouverture ou de travail de la semaine : première ouverture et dernière fermeture, repères de l'axe. */
const opening = computed(() => {
    const slots = columns.value.flatMap((column) => column.working);

    return slots.length === 0 ? null : { start: Math.min(...slots.map((slot) => slot.start)), end: Math.max(...slots.map((slot) => slot.end)) };
});

/** Au chargement : l'ouverture du jour choisi (ou le premier rendez-vous s'il est plus tôt) moins 30 minutes en haut de la grille. */
function scrollToOpening(): void {
    const element = scroller.value;

    if (element === null) {
        return;
    }

    const selectedColumns = columns.value.filter((column) => column.date === props.date);
    const starts = selectedColumns.flatMap((column) => [
        ...column.working.map((slot) => slot.start),
        ...column.bookings.map((booking) => booking.start_minute),
    ]);
    const target = starts.length > 0 ? Math.min(...starts) : (opening.value?.start ?? range.value.from);

    element.scrollTop = Math.max(0, (target - 30 - range.value.from) * PIXELS_PER_MINUTE);
}

onMounted(() => {
    // Sur un petit écran, sans vue choisie : la vue « Jour », plus lisible qu'une semaine entière.
    if (props.location !== null && window.innerWidth < 640 && !new URLSearchParams(window.location.search).has('view')) {
        visit({ view: 'day' });

        return;
    }

    void nextTick(scrollToOpening);
});

function weekday(date: string): string {
    return new Intl.DateTimeFormat(locale.value, { weekday: 'short' }).format(new Date(`${date}T12:00:00`));
}

function visit(query: Record<string, string>): void {
    router.get(routes.planning, { location: props.location?.id, date: props.date, view: view.value, ...query }, { preserveScroll: true, preserveState: false });
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
            <!-- Sélecteur de vue : boutons sur écran large, liste déroulante sur téléphone. -->
            <div class="mt-5 hidden flex-wrap gap-2 sm:flex" role="group" :aria-label="t('planning.views.label')">
                <button
                    v-for="item in PLANNING_VIEWS"
                    :key="item"
                    type="button"
                    class="min-h-10 rounded-full border-[1.5px] px-4 text-sm font-semibold transition"
                    :class="
                        view === item
                            ? 'border-primary-600 bg-primary-50 text-primary-700'
                            : 'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600'
                    "
                    :aria-pressed="view === item"
                    @click="visit({ view: item })"
                >
                    {{ t(`planning.views.${item === '3days' ? 'days3' : item}`) }}
                </button>
            </div>
            <div class="mt-5 sm:hidden">
                <SelectField
                    :model-value="view"
                    :label="t('planning.views.label')"
                    :options="PLANNING_VIEWS.map((item) => ({ value: item, label: t(`planning.views.${item === '3days' ? 'days3' : item}`) }))"
                    @update:model-value="(value) => visit({ view: value })"
                />
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3">
                <SelectField
                    v-if="locations.length > 1"
                    :model-value="location.id"
                    :label="t('planning.location')"
                    :options="locations.map((item) => ({ value: item.id, label: item.name }))"
                    @update:model-value="(id) => visit({ location: id })"
                />
                <div v-if="view !== 'practitioners' && (practitioners?.length ?? 0) > 1" class="min-w-60">
                    <PractitionerSelect
                        v-model="practitionerFilter"
                        :label="t('planning.practitioner')"
                        :options="[
                            { value: '', label: t('planning.all_practitioners'), plain: true },
                            ...(practitioners ?? []).map((item) => ({ value: item.id, label: item.name, photo_version: item.photo_version })),
                        ]"
                    />
                </div>
                <!-- Les flèches sont alignées sur le champ de date lui-même (pas sur son libellé) : même hauteur, bas aligné. -->
                <div class="flex items-end gap-2">
                    <BaseButton
                        variant="secondary"
                        class="shrink-0"
                        style="min-height: 46px"
                        :aria-label="t(`planning.step.${stepLabelKey}.previous`)"
                        @click="visit({ date: shiftDate(date!, -step) })"
                    >
                        ‹
                    </BaseButton>
                    <TextField :model-value="date!" type="date" :label="t('planning.date')" @update:model-value="(value) => value && visit({ date: value })" />
                    <BaseButton
                        variant="secondary"
                        class="shrink-0"
                        style="min-height: 46px"
                        :aria-label="t(`planning.step.${stepLabelKey}.next`)"
                        @click="visit({ date: shiftDate(date!, step) })"
                    >
                        ›
                    </BaseButton>
                </div>
                <BaseButton v-if="showToday" variant="secondary" style="min-height: 46px" @click="visit({ date: today! })">
                    {{ stepLabelKey === 'week' ? t('planning.this_week') : t('planning.today') }}
                </BaseButton>
            </div>

            <div v-if="view === 'practitioners' && (practitioners?.length ?? 0) > 1" class="mt-3">
                <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="t('planning.views.shown_practitioners')">
                    <button
                        v-for="item in practitioners"
                        :key="item.id"
                        type="button"
                        class="inline-flex min-h-9 items-center gap-2 rounded-full border-[1.5px] px-3.5 text-sm font-semibold transition"
                        :class="
                            chosenPractitioners.includes(item.id)
                                ? 'border-primary-600 bg-primary-50 text-primary-700'
                                : 'border-neutral-300 bg-surface text-neutral-900 hover:border-primary-600'
                        "
                        :aria-pressed="chosenPractitioners.includes(item.id)"
                        @click="togglePractitioner(item.id)"
                    >
                        <PractitionerAvatar :id="item.id" :name="item.name" :version="item.photo_version" size="xs" />
                        {{ item.name }}
                    </button>
                    <BaseButton v-if="chosenPractitioners.length" variant="ghost" size="sm" @click="chosenPractitioners = []">
                        {{ t('planning.all_practitioners') }}
                    </BaseButton>
                </div>
                <p v-if="!chosenPractitioners.length" class="mt-1 text-sm text-neutral-700">{{ t('planning.views.all_shown') }}</p>
            </div>

            <h2 class="mt-5 text-lg font-semibold text-nova-900">{{ heading }}</h2>

            <AlertBox v-if="!practitioners?.length" variant="info" class="mt-4">{{ t('planning.no_practitioners') }}</AlertBox>

            <template v-else>
                <ul
                    v-if="view !== 'practitioners'"
                    class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-700"
                    :aria-label="t('planning.practitioner')"
                >
                    <li v-for="practitioner in practitioners" :key="practitioner.id" class="flex items-center gap-1.5">
                        <PractitionerAvatar :id="practitioner.id" :name="practitioner.name" :version="practitioner.photo_version" size="xs" />
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: practitioner.color }" aria-hidden="true" />
                        {{ practitioner.name }}
                    </li>
                </ul>

                <ul class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-neutral-700" :aria-label="t('planning.legend.title')">
                    <li class="flex items-center gap-1.5">
                        <span class="inline-block h-4 w-6 rounded-sm border-y border-primary-600/30 bg-surface" aria-hidden="true" />
                        {{ t('planning.legend.open') }}
                    </li>
                    <li class="flex items-center gap-1.5">
                        <span class="nova-offhours inline-block h-4 w-6 rounded-sm border border-line" aria-hidden="true" />
                        {{ t('planning.legend.outside') }}
                    </li>
                </ul>

                <p v-if="location && differsFrom(location.timezone)" class="mt-3 text-sm text-neutral-700" data-testid="grid-timezone">
                    {{ t('timezone.grid_note', { zone: location.timezone, label: zoneLabel(location.timezone) }) }}
                </p>

                <div ref="scroller" class="mt-3 max-h-[75vh] overflow-auto rounded-card border border-line bg-surface shadow-xs">
                    <div class="flex" :style="{ minWidth: `${gridMinWidth(columns.length, view)}px` }">
                        <div class="w-14 shrink-0 border-r border-line">
                            <div class="sticky top-0 z-20 h-12 border-b border-line bg-surface" />
                            <div class="relative" :style="{ height: gridHeight }">
                                <span
                                    v-for="mark in hourMarks(range)"
                                    :key="mark"
                                    class="absolute right-2 text-xs text-neutral-500"
                                    :class="mark === range.from ? 'translate-y-0.5' : '-translate-y-1/2'"
                                    :style="{ top: `${(mark - range.from) * PIXELS_PER_MINUTE}px` }"
                                >
                                    {{ minutesToTime(mark) }}
                                </span>
                                <template v-if="opening">
                                    <span
                                        class="pointer-events-none absolute inset-x-0 border-t-2 border-primary-600"
                                        :style="{ top: `${(opening.start - range.from) * PIXELS_PER_MINUTE}px` }"
                                        :title="t('planning.opening', { time: minutesToTime(opening.start) })"
                                    />
                                    <span
                                        class="pointer-events-none absolute inset-x-0 border-t-2 border-primary-600"
                                        :style="{ top: `${(opening.end - range.from) * PIXELS_PER_MINUTE}px` }"
                                        :title="t('planning.closing', { time: minutesToTime(opening.end) })"
                                    />
                                </template>
                                <CurrentTimeAxisLabel
                                    v-if="location"
                                    :dates="columns.map((column) => column.date)"
                                    :timezone="location.timezone"
                                    :range="range"
                                />
                            </div>
                        </div>

                        <section
                            v-for="day in columns"
                            :key="day.key"
                            class="min-w-0 flex-1 basis-0 border-r border-line last:border-r-0"
                            :aria-label="day.practitioner ? day.practitioner.name : formatWeekdayDate(day.date, locale)"
                        >
                            <h3 class="sticky top-0 z-20 h-12 border-b border-line bg-surface">
                                <span
                                    v-if="day.practitioner"
                                    class="flex size-full items-center justify-center gap-2 px-2 text-sm font-semibold text-neutral-900"
                                >
                                    <PractitionerAvatar
                                        :id="day.practitioner.id"
                                        :name="day.practitioner.name"
                                        :version="day.practitioner.photo_version"
                                        size="sm"
                                    />
                                    <span class="truncate">{{ day.practitioner.name }}</span>
                                </span>
                                <button
                                    v-else
                                    type="button"
                                    class="flex size-full flex-col items-center justify-center text-sm leading-tight hover:bg-surface-2 focus-visible:outline focus-visible:outline-2"
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
                            <div class="nova-offhours relative" :style="{ height: gridHeight }">
                                <div
                                    v-for="(slot, index) in day.working"
                                    :key="index"
                                    class="absolute inset-x-0 border-y border-primary-600/30 bg-surface"
                                    :style="blockStyle(range, slot.start, slot.end)"
                                    :title="t('planning.working_hours')"
                                />
                                <span
                                    v-for="mark in hourMarks(range)"
                                    :key="mark"
                                    class="pointer-events-none absolute inset-x-0 border-t border-line"
                                    :style="{ top: `${(mark - range.from) * PIXELS_PER_MINUTE}px` }"
                                />
                                <CurrentTimeLine v-if="location" :date="day.date" :timezone="location.timezone" :range="range" />
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
                                    <PractitionerAvatar
                                        v-if="showCardAvatar && practitionerById.get(booking.practitioner_id)"
                                        :id="booking.practitioner_id"
                                        :name="practitionerById.get(booking.practitioner_id)!.name"
                                        :version="practitionerById.get(booking.practitioner_id)!.photo_version"
                                        size="xs"
                                        class="absolute right-1 top-1"
                                    />
                                    <span class="block truncate font-semibold">{{ booking.starts }} {{ booking.customer.name ?? booking.customer.phone }}</span>
                                    <span class="block truncate">{{ booking.service }}</span>
                                    <!-- La vue « Jour » a la place : praticien et état en plus. Les autres vues restent compactes. -->
                                    <span v-if="view === 'day'" class="block truncate text-neutral-700">
                                        {{ practitionerById.get(booking.practitioner_id)?.name }} · {{ t(`planning.status.${booking.status}`) }}
                                    </span>
                                </button>
                            </div>
                        </section>
                    </div>
                </div>

                <p v-if="!visibleBookings.length" class="mt-3 text-sm text-neutral-600">{{ t('planning.empty_view') }}</p>
            </template>
        </template>

        <ModalDialog v-if="location" :open="creating" :title="t('planning.form.title')" @close="creating = false">
            <BookingForm
                :location-id="location.id"
                :date="date!"
                :practitioners="practitioners ?? []"
                :services="services ?? []"
                :sees-notes="seesNotes ?? false"
                :timezone="location.timezone"
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
                :services="services ?? []"
                :can-reassign="canReassign ?? false"
                :can="can"
                :timezone="location?.timezone ?? 'UTC'"
                @done="selectedId = null"
            />
        </ModalDialog>
    </AppLayout>
</template>
