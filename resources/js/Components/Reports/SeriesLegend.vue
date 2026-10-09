<script setup lang="ts">
import { useId } from 'vue';
import type { SeriesDef } from './chartTypes';
import ChartPatterns from './ChartPatterns.vue';

/** Légende d'un graphique : la même trame que les barres, pour distinguer les séries sans la couleur. */
defineProps<{ series: SeriesDef[] }>();
const id = useId();
</script>

<template>
    <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-neutral-800" data-testid="legend">
        <li v-for="item in series" :key="item.key" class="inline-flex items-center gap-1.5">
            <svg class="size-3.5 rounded-sm border border-neutral-500/40" viewBox="0 0 14 14" aria-hidden="true">
                <ChartPatterns :id="id" :series="[item]" />
                <rect width="14" height="14" :fill="item.pattern === 'solid' ? item.color : `url(#${id}-${item.key})`" />
            </svg>
            {{ item.label }}
        </li>
    </ul>
</template>
