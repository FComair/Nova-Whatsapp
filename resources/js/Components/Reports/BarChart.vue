<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { niceScale } from '../../composables/reports';
import { useT } from '../../composables/useT';
import type { BarItem, SeriesDef } from './chartTypes';
import ChartPatterns from './ChartPatterns.vue';
import SeriesLegend from './SeriesLegend.vue';

/**
 * Barres verticales, empilées ou groupées, en SVG : axe à graduations lisibles, étiquettes espacées pour ne jamais se chevaucher,
 * info-bulle au survol ET au focus clavier (chaque groupe est atteignable avec Tab), trames en plus des couleurs pour le noir et blanc.
 * Le même contenu est disponible en tableau (voir ChartCard).
 */
const props = withDefaults(
    defineProps<{
        items: BarItem[];
        series: SeriesDef[];
        stacked?: boolean;
        format?: (value: number) => string;
        height?: number;
    }>(),
    { stacked: true, format: (value: number) => String(value), height: 220 },
);

const { t } = useT();
const id = useId();
const active = ref<number | null>(null);

const WIDTH = 720;
const LEFT = 44;
const BOTTOM = 26;
const TOP = 10;

const totals = computed(() => props.items.map((item) => (props.stacked ? item.values.reduce((sum, value) => sum + value, 0) : Math.max(0, ...item.values))));
const scale = computed(() => niceScale(Math.max(0, ...totals.value)));
const plotHeight = computed(() => props.height - TOP - BOTTOM);
const slot = computed(() => (WIDTH - LEFT) / Math.max(1, props.items.length));
const barWidth = computed(() => Math.max(2, Math.min(40, slot.value * 0.7)));
const ticks = computed(() => Array.from({ length: Math.round(scale.value.max / scale.value.step) + 1 }, (_unused, index) => index * scale.value.step));
const labelEvery = computed(() => Math.max(1, Math.ceil(props.items.length / 12)));
const y = (value: number): number => TOP + plotHeight.value - (value / scale.value.max) * plotHeight.value;

interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
    series: SeriesDef;
}

function rects(item: BarItem, index: number): Rect[] {
    const center = LEFT + slot.value * index + slot.value / 2;
    const result: Rect[] = [];

    if (props.stacked) {
        let base = 0;
        item.values.forEach((value, seriesIndex) => {
            if (value > 0) {
                result.push({
                    x: center - barWidth.value / 2,
                    y: y(base + value),
                    width: barWidth.value,
                    height: y(base) - y(base + value),
                    series: props.series[seriesIndex],
                });
                base += value;
            }
        });

        return result;
    }

    const each = barWidth.value / props.series.length;
    item.values.forEach((value, seriesIndex) => {
        if (value > 0) {
            result.push({
                x: center - barWidth.value / 2 + each * seriesIndex,
                y: y(value),
                width: Math.max(1, each - 1),
                height: y(0) - y(value),
                series: props.series[seriesIndex],
            });
        }
    });

    return result;
}

const fill = (series: SeriesDef): string => (series.pattern === 'solid' ? series.color : `url(#${id}-${series.key})`);
const describe = (item: BarItem): string =>
    `${item.title}: ${props.series.map((series, index) => `${series.label} ${props.format(item.values[index])}`).join(', ')}`;
const tooltipLeft = computed(() => (active.value === null ? 0 : ((LEFT + slot.value * active.value + slot.value / 2) / WIDTH) * 100));
</script>

<template>
    <div class="relative" data-testid="bar-chart">
        <svg :viewBox="`0 0 ${WIDTH} ${height}`" class="w-full" role="group" :aria-label="t('reports.chart.legend')" @mouseleave="active = null">
            <ChartPatterns :id="id" :series="series" />
            <g aria-hidden="true">
                <template v-for="tick in ticks" :key="tick">
                    <line :x1="LEFT" :x2="WIDTH" :y1="y(tick)" :y2="y(tick)" stroke="var(--color-line)" stroke-width="1" />
                    <text :x="LEFT - 6" :y="y(tick) + 3" text-anchor="end" font-size="10" fill="var(--color-neutral-700)">{{ format(tick) }}</text>
                </template>
            </g>
            <g
                v-for="(item, index) in items"
                :key="item.key"
                tabindex="0"
                role="img"
                :aria-label="describe(item)"
                class="outline-none focus-visible:[&>rect.focus]:stroke-nova-900"
                @mouseenter="active = index"
                @focus="active = index"
                @blur="active = null"
            >
                <rect
                    class="focus"
                    :x="LEFT + slot * index"
                    :y="TOP"
                    :width="slot"
                    :height="plotHeight"
                    :fill="active === index ? 'var(--color-primary-50)' : 'transparent'"
                    stroke="transparent"
                    stroke-width="2"
                />
                <rect
                    v-for="rect in rects(item, index)"
                    :key="rect.series.key"
                    :x="rect.x"
                    :y="rect.y"
                    :width="rect.width"
                    :height="rect.height"
                    :fill="fill(rect.series)"
                    rx="1.5"
                />
                <text
                    v-if="index % labelEvery === 0"
                    :x="LEFT + slot * index + slot / 2"
                    :y="height - 8"
                    text-anchor="middle"
                    font-size="10"
                    fill="var(--color-neutral-700)"
                    aria-hidden="true"
                >
                    {{ item.label }}
                </text>
            </g>
        </svg>
        <div
            v-if="active !== null"
            class="pointer-events-none absolute top-0 z-10 w-max max-w-[14rem] -translate-x-1/2 rounded-control border border-line bg-surface p-2.5 text-xs shadow-md print:hidden"
            :style="{ left: `${Math.min(85, Math.max(15, tooltipLeft))}%` }"
            role="status"
            data-testid="chart-tooltip"
        >
            <p class="font-semibold text-nova-900">{{ items[active].title }}</p>
            <p v-for="(entry, index) in props.series" :key="entry.key" class="text-neutral-800">
                {{ entry.label }} : {{ format(items[active].values[index]) }}
            </p>
        </div>
        <SeriesLegend :series="series" />
    </div>
</template>
