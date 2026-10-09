<script setup lang="ts">
import { computed } from 'vue';
import { changeOf, formatMetric } from '../../composables/reports';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import type { ReportMetric } from '../../types/reports';
import InfoTip from './InfoTip.vue';

/**
 * Chiffre clé : valeur dans son unité, définition en info-bulle, comparaison avec la période précédente. L'évolution est neutre :
 * flèche, signe et mots (jamais vert ou rouge) ; sans pourcentage quand la base est nulle ou trop petite, avec sa raison.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        metric: ReportMetric | null;
        help?: string;
        currency?: string;
        previous?: { from: string; to: string } | null;
        /** Chiffre tourné vers l'avenir (rendez-vous à venir) : aucune comparaison. */
        forward?: boolean;
        digits?: number;
        /** Texte de remplacement quand le chiffre n'existe pas pour ce périmètre. */
        unavailable?: boolean;
    }>(),
    { help: undefined, currency: 'EUR', previous: null, forward: false, digits: 0, unavailable: false },
);

const { t, locale } = useT();

const value = computed(() =>
    props.metric === null || props.unavailable ? '—' : formatMetric(props.metric.value, props.metric.kind, locale.value, props.currency, props.digits),
);
const change = computed(() => (props.metric === null || props.forward ? null : changeOf(props.metric, locale.value, props.currency, props.digits)));
const range = computed(() => (props.previous ? `${formatDate(props.previous.from)} – ${formatDate(props.previous.to)}` : ''));
const previousText = computed(() =>
    props.metric === null || props.metric.previous === null
        ? ''
        : formatMetric(props.metric.previous, props.metric.kind, locale.value, props.currency, props.digits),
);
const directionText = computed(() =>
    change.value?.direction === 'up'
        ? t('reports.compare.increase')
        : change.value?.direction === 'down'
          ? t('reports.compare.decrease')
          : t('reports.compare.same'),
);
</script>

<template>
    <div class="min-w-0 rounded-card border border-line bg-surface p-4 shadow-xs print:break-inside-avoid print:shadow-none" data-testid="kpi">
        <dt class="flex items-start justify-between gap-2 text-xs font-medium text-neutral-700">
            <span>{{ label }}</span>
            <InfoTip v-if="help" :text="help" />
        </dt>
        <dd class="mt-1 break-words font-display text-2xl font-bold text-nova-900 sm:text-3xl" data-testid="kpi-value">{{ value }}</dd>
        <dd class="mt-1.5 min-h-[2.25rem] text-xs leading-snug text-neutral-700" data-testid="kpi-change">
            <template v-if="forward">{{ t('reports.compare.forward') }}</template>
            <template v-else-if="change && change.direction !== 'none'">
                <span class="inline-flex items-center gap-1 font-semibold text-neutral-800">
                    <svg class="size-3" viewBox="0 0 12 12" fill="currentColor" aria-hidden="true">
                        <path v-if="change.direction === 'up'" d="M6 2 11 10H1z" />
                        <path v-else-if="change.direction === 'down'" d="M6 10 1 2h10z" />
                        <rect v-else x="1" y="5" width="10" height="2" rx="1" />
                    </svg>
                    <span class="sr-only">{{ directionText }}</span>
                    {{ change.delta }}<template v-if="change.pct"> ({{ change.pct }})</template>
                </span>
                <span v-if="change.note" class="block">{{ t(`reports.compare.${change.note}`) }}</span>
                <span class="block text-neutral-600" :title="range">{{ t('reports.compare.vs', { value: previousText }) }}</span>
            </template>
            <template v-else-if="change && change.note">{{ t(`reports.compare.${change.note}`) }}</template>
            <template v-else-if="metric && metric.previous === null && !unavailable">{{ t('reports.compare.no_baseline') }}</template>
        </dd>
    </div>
</template>
