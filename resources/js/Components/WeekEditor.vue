<script setup lang="ts">
import { computed, useId } from 'vue';
import { addRange, copyDay, removeRange, setOpen, type DayState } from '../composables/hours';
import { endOptions, rangeIsValid, startOptions } from '../composables/timeOptions';
import { useT } from '../composables/useT';
import TimeSelect from './TimeSelect.vue';

defineProps<{
    /** Messages d'erreur par jour (index 0 = lundi). */
    errorsByDay?: string[][];
}>();

// Semaine en cours d'édition : possédée par le parent, éditée ici.
const days = defineModel<DayState[]>({ required: true });

const { t, locale } = useT();
const id = useId();

// Noms des jours dans la langue de l'interface : le 1er janvier 2024 est un lundi.
const weekdayNames = computed(() =>
    Array.from({ length: 7 }, (_unused, i) => new Intl.DateTimeFormat(locale.value, { weekday: 'long' }).format(new Date(2024, 0, 1 + i))),
);

function update(index: number, day: DayState): void {
    days.value = days.value.map((current, i) => (i === index ? day : current));
}
</script>

<template>
    <ul class="divide-y divide-neutral-200 rounded-lg border border-neutral-200">
        <li v-for="(day, index) in days" :key="day.weekday" class="p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <input
                        :id="`${id}-open-${day.weekday}`"
                        type="checkbox"
                        :checked="day.open"
                        class="size-5 rounded border-neutral-300 text-primary-600"
                        @change="update(index, setOpen(day, ($event.target as HTMLInputElement).checked))"
                    />
                    <label :for="`${id}-open-${day.weekday}`" class="font-medium capitalize text-nova-900">{{ weekdayNames[index] }}</label>
                    <span class="text-sm" :class="day.open ? 'text-success-600' : 'text-neutral-500'">
                        {{ day.open ? t('onboarding.hours.open') : t('onboarding.hours.closed') }}
                    </span>
                </div>

                <button
                    v-if="day.weekday === 1 && day.open"
                    type="button"
                    class="text-sm font-medium text-primary-700 hover:underline"
                    @click="days = copyDay(days, 1, [2, 3, 4, 5])"
                >
                    {{ t('onboarding.hours.copy_weekdays') }}
                </button>
            </div>

            <div v-if="day.open" class="mt-3 space-y-3">
                <div
                    v-for="(range, rangeIndex) in day.ranges"
                    :key="rangeIndex"
                    class="flex flex-wrap items-end gap-3"
                    role="group"
                    :aria-label="t('onboarding.hours.range_label', { number: rangeIndex + 1 })"
                >
                    <div>
                        <label :for="`${id}-s-${day.weekday}-${rangeIndex}`" class="mb-1 block text-xs font-medium text-neutral-700">{{
                            t('onboarding.hours.from')
                        }}</label>
                        <TimeSelect
                            :id="`${id}-s-${day.weekday}-${rangeIndex}`"
                            v-model="range.start"
                            :options="startOptions(range.start, range.end)"
                            :invalid="!rangeIsValid(range)"
                        />
                    </div>
                    <div>
                        <label :for="`${id}-e-${day.weekday}-${rangeIndex}`" class="mb-1 block text-xs font-medium text-neutral-700">{{
                            t('onboarding.hours.to')
                        }}</label>
                        <TimeSelect
                            :id="`${id}-e-${day.weekday}-${rangeIndex}`"
                            v-model="range.end"
                            :options="endOptions(range.start, range.end)"
                            :invalid="!rangeIsValid(range)"
                        />
                    </div>
                    <button type="button" class="pb-2 text-sm font-medium text-danger-700 hover:underline" @click="update(index, removeRange(day, rangeIndex))">
                        {{ t('onboarding.hours.remove_range') }}
                    </button>
                </div>

                <button
                    v-if="day.ranges.length < 3"
                    type="button"
                    class="text-sm font-medium text-primary-700 hover:underline"
                    @click="update(index, addRange(day))"
                >
                    + {{ t('onboarding.hours.add_range') }}
                </button>
            </div>

            <p v-if="day.open && day.ranges.some((range) => !rangeIsValid(range))" class="mt-2 text-sm font-medium text-danger-700" role="alert">
                {{ t('onboarding.errors.hours_end_after_start') }}
            </p>
            <p v-for="message in errorsByDay?.[index] ?? []" :key="message" class="mt-2 text-sm font-medium text-danger-700" role="alert">{{ message }}</p>
        </li>
    </ul>
</template>
