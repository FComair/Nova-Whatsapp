<script setup lang="ts">
import { computed } from 'vue';
import { useT } from '../../composables/useT';
import type { Heatmap } from '../../types/reports';

/**
 * Carte de chaleur jour × heure : un vrai tableau (lecteurs d'écran), cases plus foncées quand il y a plus de rendez-vous, avec
 * le nombre écrit dans chaque case. L'intensité reste lisible en niveaux de gris.
 */
const props = defineProps<{ data: Heatmap }>();

const { t } = useT();
const hours = computed(() => {
    // Au moins une journée de travail courante (8 h à 19 h) : quelques rendez-vous ne produisent pas trois colonnes géantes.
    const first = Math.min(props.data.first_hour, props.data.last_hour, 8);
    const last = Math.max(props.data.first_hour, props.data.last_hour, 19);

    return Array.from({ length: last - first + 1 }, (_unused, index) => first + index);
});
const level = (count: number): number => (props.data.max === 0 || count === 0 ? 0 : Math.max(0.12, count / props.data.max));
const textColor = (count: number): string => (level(count) > 0.55 ? '#fff' : 'var(--color-neutral-900)');
const hourLabel = (hour: number): string => `${String(hour).padStart(2, '0')}`;
</script>

<template>
    <div class="overflow-x-auto" tabindex="0" role="region" :aria-label="t('reports.section.heatmap')" data-testid="heatmap">
        <table class="w-full min-w-[34rem] border-separate border-spacing-0.5 text-center text-[0.7rem]">
            <thead>
                <tr>
                    <th scope="col" class="w-12 text-left font-semibold text-neutral-700">
                        <span class="sr-only">{{ t('reports.col.weekday') }}</span>
                    </th>
                    <th v-for="hour in hours" :key="hour" scope="col" class="font-semibold text-neutral-700">{{ hourLabel(hour) }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="day in 7" :key="day">
                    <th scope="row" class="pr-2 text-left font-semibold text-neutral-800">{{ t(`reports.weekday_short.${day}`) }}</th>
                    <td
                        v-for="hour in hours"
                        :key="hour"
                        class="h-8 min-w-6 rounded-md font-semibold"
                        :style="{
                            backgroundColor:
                                level(data.cells[day][hour]) === 0
                                    ? 'var(--color-neutral-100)'
                                    : `color-mix(in srgb, var(--color-primary-700) ${Math.round(level(data.cells[day][hour]) * 100)}%, white)`,
                            color: textColor(data.cells[day][hour]),
                        }"
                        :title="`${t(`reports.weekday.${day}`)} ${hourLabel(hour)}:00 — ${data.cells[day][hour]}`"
                    >
                        <span aria-hidden="true">{{ data.cells[day][hour] || '' }}</span>
                        <span class="sr-only">{{ t(`reports.weekday.${day}`) }} {{ hourLabel(hour) }}:00, {{ data.cells[day][hour] }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="mt-2 text-xs text-neutral-700">{{ t('reports.chart.peak', { value: data.max }) }}</p>
    </div>
</template>
