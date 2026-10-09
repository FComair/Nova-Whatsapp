<script setup lang="ts">
import { computed } from 'vue';
import { formatDate } from '../composables/dates';
import { useT } from '../composables/useT';

/**
 * Évolution des tampons gagnés et des récompenses utilisées : barres groupées, avec le même contenu en tableau (lecteurs d'écran, impression).
 * Affiche un message au lieu d'un graphique quand il n'y a pas assez de points : on ne dessine pas une courbe sur une seule valeur.
 */
const props = defineProps<{ series: { key: string; earned: number; redeemed: number }[]; granularity: 'day' | 'week' | 'month'; tableLabel: string }>();
const { t } = useT();

const enough = computed(() => props.series.length >= 2);
const max = computed(() => Math.max(1, ...props.series.flatMap((row) => [row.earned, row.redeemed])));
const label = (key: string): string => (props.granularity === 'month' ? `${key.slice(5, 7)}/${key.slice(0, 4)}` : formatDate(key));
const height = (value: number): string => `${Math.max(value === 0 ? 0 : 4, Math.round((value / max.value) * 100))}%`;
</script>

<template>
    <div data-testid="loyalty-trend">
        <p v-if="!enough" class="text-sm text-neutral-700">{{ t('loyalty.index.performance.not_enough') }}</p>
        <template v-else>
            <div class="flex items-center gap-4 text-xs text-neutral-800">
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-3 rounded-sm bg-primary-600" aria-hidden="true" />{{ t('loyalty.index.performance.earned') }}</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span class="size-3 rounded-sm bg-amber" aria-hidden="true" />{{ t('loyalty.index.performance.redeemed') }}</span
                >
            </div>
            <div class="mt-3 flex h-40 items-end gap-1 overflow-x-auto" aria-hidden="true">
                <div
                    v-for="row in series"
                    :key="row.key"
                    class="flex h-full min-w-6 flex-1 items-end justify-center gap-0.5"
                    :title="`${label(row.key)} : ${row.earned} / ${row.redeemed}`"
                >
                    <div class="w-2.5 rounded-t bg-primary-600" :style="{ height: height(row.earned) }" />
                    <div class="w-2.5 rounded-t bg-amber" :style="{ height: height(row.redeemed) }" />
                </div>
            </div>
            <details class="mt-3 text-sm">
                <summary class="cursor-pointer font-semibold text-primary-700">{{ tableLabel }}</summary>
                <div class="mt-2 max-h-64 overflow-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-line text-xs text-neutral-700">
                                <th scope="col" class="py-1.5 pr-3 font-semibold">{{ t('loyalty.report.period') }}</th>
                                <th scope="col" class="py-1.5 pr-3 text-right font-semibold">{{ t('loyalty.index.performance.earned') }}</th>
                                <th scope="col" class="py-1.5 text-right font-semibold">{{ t('loyalty.index.performance.redeemed') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in series" :key="row.key" class="border-b border-line/60">
                                <th scope="row" class="py-1.5 pr-3 font-normal">{{ label(row.key) }}</th>
                                <td class="py-1.5 pr-3 text-right tabular-nums">{{ row.earned }}</td>
                                <td class="py-1.5 text-right tabular-nums">{{ row.redeemed }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </template>
    </div>
</template>
