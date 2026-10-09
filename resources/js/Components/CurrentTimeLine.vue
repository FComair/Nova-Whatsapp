<script setup lang="ts">
import { computed } from 'vue';
import { timeLineOffset, useClock } from '../composables/clock';
import { PIXELS_PER_MINUTE } from '../composables/planning';

/**
 * Ligne « maintenant » d'une colonne du planning : seulement sur la colonne d'aujourd'hui (jour du fuseau du site) et seulement
 * si l'heure est dans la plage affichée. Posée sous les rendez-vous (ordre dans la page) et insensible à la souris : elle ne gêne
 * jamais un clic. Elle se met à jour seule ; le reste du planning n'est pas redessiné.
 */
const props = defineProps<{ date: string; timezone: string; range: { from: number; to: number } }>();

const clock = useClock(props.timezone);
const top = computed(() => (clock.date === props.date ? timeLineOffset(props.range, clock.minutes) : null));
</script>

<template>
    <div
        v-if="top !== null"
        class="pointer-events-none absolute inset-x-0 z-[1] border-t-2 border-danger-fg transition-[top] duration-1000 ease-linear"
        :style="{ top: `${top * PIXELS_PER_MINUTE}px` }"
        data-testid="current-time-line"
        aria-hidden="true"
    >
        <span class="absolute -left-1 -top-[6px] size-2.5 rounded-full bg-danger-fg" />
    </div>
</template>
