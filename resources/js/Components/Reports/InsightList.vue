<script setup lang="ts">
import { useT } from '../../composables/useT';
import { formatPrice } from '../../composables/money';
import type { Insight } from '../../types/reports';

/** Constats en une phrase, produits par le serveur seulement quand les chiffres les soutiennent. Toujours neutres. */
defineProps<{ insights: Insight[]; currency?: string }>();

const { t, locale } = useT();

function text(insight: Insight, currency: string): string {
    const params: Record<string, string | number> = { ...insight.params };

    if (typeof params.day === 'number') {
        params.day = t(`reports.weekday.${params.day}`);
    }

    if (typeof params.amount === 'number') {
        params.amount = formatPrice(params.amount, locale.value, currency);
    }

    if (insight.key === 'inactive_customers') {
        return t(`reports.insight.${insight.key}`, { ...params, count: Number(params.count) });
    }

    return t(`reports.insight.${insight.key}`, params);
}
</script>

<template>
    <section
        v-if="insights.length > 0"
        class="rounded-card border border-line bg-primary-50 p-4 print:break-inside-avoid print:bg-transparent"
        :aria-label="t('reports.section.insights')"
        data-testid="insights"
    >
        <h3 class="text-sm font-semibold text-rendivoo-900">{{ t('reports.section.insights') }}</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-neutral-900">
            <li v-for="insight in insights" :key="insight.key">{{ text(insight, currency ?? 'EUR') }}</li>
        </ul>
    </section>
</template>
