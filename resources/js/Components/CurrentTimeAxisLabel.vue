<script setup lang="ts">
import { computed } from 'vue';
import { clockLabel, timeLineOffset, useClock } from '../composables/clock';
import { PIXELS_PER_MINUTE } from '../composables/planning';
import { useT } from '../composables/useT';

/**
 * Étiquette de l'heure actuelle sur l'axe des heures (« 10:07 »), alignée sur la ligne. Visible seulement quand aujourd'hui est
 * affiché et que l'heure est dans la plage ; texte lisible aussi par les lecteurs d'écran.
 */
const props = defineProps<{ dates: string[]; timezone: string; range: { from: number; to: number } }>();

const { t } = useT();
const clock = useClock(props.timezone);
const top = computed(() => (props.dates.includes(clock.date) ? timeLineOffset(props.range, clock.minutes) : null));
const label = computed(() => t('planning.now', { time: clockLabel(clock.minutes) }));
</script>

<template>
    <span
        v-if="top !== null"
        class="pointer-events-none absolute right-1 z-[1] -translate-y-1/2 rounded bg-danger-fg px-1 text-[0.7rem] font-bold leading-4 text-white transition-[top] duration-1000 ease-linear"
        :style="{ top: `${top * PIXELS_PER_MINUTE}px` }"
        :title="label"
    >
        {{ clockLabel(clock.minutes) }}<span class="sr-only"> — {{ label }}</span>
    </span>
</template>
