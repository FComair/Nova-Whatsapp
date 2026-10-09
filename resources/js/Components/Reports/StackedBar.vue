<script setup lang="ts">
import { computed, useId } from 'vue';
import { formatCount, formatRate } from '../../composables/reports';
import { useT } from '../../composables/useT';
import type { SeriesDef } from './chartTypes';
import ChartPatterns from './ChartPatterns.vue';

/**
 * Une seule barre horizontale découpée en parts (issues des rendez-vous), avec en dessous la liste chiffrée de chaque part : le
 * chiffre et le pourcentage sont toujours écrits, la barre n'est qu'un repère visuel.
 */
const props = defineProps<{ parts: { key: string; label: string; value: number; series: SeriesDef }[] }>();

const { t, locale } = useT();
const id = useId();
const total = computed(() => props.parts.reduce((sum, part) => sum + part.value, 0));
const widths = computed(() => props.parts.map((part) => (total.value > 0 ? (part.value / total.value) * 100 : 0)));
const offsets = computed(() => widths.value.map((_width, index) => widths.value.slice(0, index).reduce((sum, width) => sum + width, 0)));
const fill = (series: SeriesDef): string => (series.pattern === 'solid' ? series.color : `url(#${id}-${series.key})`);
</script>

<template>
    <div data-testid="stacked-bar">
        <svg
            viewBox="0 0 100 9"
            preserveAspectRatio="none"
            class="h-7 w-full rounded-control"
            role="img"
            :aria-label="parts.map((part) => `${part.label} ${part.value}`).join(', ')"
        >
            <ChartPatterns :id="id" :series="parts.map((part) => part.series)" />
            <rect width="100" height="9" fill="var(--color-neutral-100)" />
            <rect v-for="(part, index) in parts" :key="part.key" :x="offsets[index]" :width="widths[index]" height="9" :fill="fill(part.series)" />
        </svg>
        <ul class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
            <li v-for="part in parts" :key="part.key" class="flex items-center justify-between gap-3 border-b border-line py-1">
                <span class="inline-flex min-w-0 items-center gap-2">
                    <svg class="size-3.5 shrink-0 rounded-sm border border-neutral-500/40" viewBox="0 0 14 14" aria-hidden="true">
                        <ChartPatterns :id="`${id}-l`" :series="[part.series]" />
                        <rect width="14" height="14" :fill="part.series.pattern === 'solid' ? part.series.color : `url(#${id}-l-${part.series.key})`" />
                    </svg>
                    <span class="truncate text-neutral-800">{{ part.label }}</span>
                </span>
                <span class="shrink-0 font-semibold text-nova-900">
                    {{ formatCount(part.value, locale) }}
                    <span class="font-normal text-neutral-700">({{ formatRate(total > 0 ? part.value / total : null, locale) }})</span>
                </span>
            </li>
        </ul>
        <p class="sr-only">{{ t('reports.chart.total') }} : {{ total }}</p>
    </div>
</template>
