<script setup lang="ts">
import { inject, ref, useId } from 'vue';
import { useT } from '../../composables/useT';

/**
 * Carte d'un graphique : titre, description pour les lecteurs d'écran, bascule « Graphique / Tableau » (le tableau donne les mêmes
 * chiffres), état vide. À l'impression le graphique ET son tableau sont tous deux imprimés, sans bouton de bascule.
 */
const props = withDefaults(defineProps<{ title: string; empty?: boolean; hideToggle?: boolean; note?: string }>(), {
    empty: false,
    hideToggle: false,
    note: undefined,
});

const { t } = useT();
const printing = inject<boolean>('reportPrint', false);
const view = ref<'chart' | 'table'>('chart');
const id = useId();
</script>

<template>
    <section
        class="min-w-0 rounded-card border border-line bg-surface p-4 shadow-xs sm:p-5 print:break-inside-avoid print:border-neutral-400 print:p-3 print:shadow-none"
        :aria-labelledby="id"
        data-testid="chart-card"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h3 :id="id" class="text-base font-semibold text-nova-900">{{ title }}</h3>
            <div
                v-if="!hideToggle && !empty && !printing"
                class="inline-flex rounded-full border border-line-strong p-0.5 text-xs font-semibold print:hidden"
                role="group"
                :aria-label="title"
            >
                <button
                    v-for="mode in ['chart', 'table'] as const"
                    :key="mode"
                    type="button"
                    class="rounded-lg px-3 py-1 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/20"
                    :class="view === mode ? 'bg-primary-600 text-white' : 'text-neutral-800 hover:bg-primary-50'"
                    :aria-pressed="view === mode"
                    :data-testid="`view-${mode}`"
                    @click="view = mode"
                >
                    {{ t(`reports.chart.view_${mode}`) }}
                </button>
            </div>
        </div>
        <p v-if="note" class="mt-1 text-xs text-neutral-700">{{ note }}</p>
        <p class="sr-only">{{ t('reports.chart.description', { title: props.title }) }}</p>

        <p v-if="empty" class="mt-4 rounded-control bg-neutral-50 px-4 py-6 text-center text-sm text-neutral-700" data-testid="chart-empty">
            {{ t('reports.empty.section') }}
        </p>
        <div v-else class="mt-4">
            <div v-show="printing || view === 'chart'"><slot name="chart" /></div>
            <div v-show="printing || view === 'table'" :class="printing ? 'mt-4' : ''"><slot name="table" /></div>
        </div>
    </section>
</template>
