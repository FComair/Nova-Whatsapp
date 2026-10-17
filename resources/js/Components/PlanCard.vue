<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '../composables/money';
import { type PricedPlan, planPriceLines } from '../composables/pricing';
import { limitLines } from '../composables/signupPlans';
import { useT } from '../composables/useT';

/**
 * Carte d'une formule sur la page tarifs publique (docs/40) : nom, description courte, prix et période, limites, fonctionnalités (incluse, limitée,
 * indisponible : toujours dites par un mot caché et un symbole, jamais par la couleur seule), formule recommandée et bouton d'action.
 */
const props = defineProps<{ plan: PricedPlan }>();
const { t, locale } = useT();

const price = computed(() => planPriceLines(props.plan, t, (cents) => formatPrice(cents, locale.value, props.plan.currency)));
const limits = computed(() => limitLines(props.plan.limits, t));
const cta = computed(() => t(`pricing.cta.${props.plan.cta.type}`));
const MARKS = { included: '✓', limited: '◐', unavailable: '✕' } as const;
</script>

<template>
    <article
        class="relative flex flex-col rounded-card border-2 bg-surface p-5 shadow-xs"
        :class="plan.recommended ? 'border-primary-600' : 'border-line'"
        :data-testid="`pricing-${plan.code}`"
    >
        <span
            v-if="plan.recommended"
            class="absolute -top-3 right-4 rounded-full bg-amber px-2.5 py-0.5 text-xs font-bold text-rendivoo-900"
            data-testid="recommended"
            >{{ t('pricing.recommended') }}</span
        >

        <h2 class="text-lg font-semibold text-rendivoo-900">{{ plan.name }}</h2>
        <p v-if="plan.description" class="mt-1 text-sm text-neutral-700">{{ plan.description }}</p>

        <p class="mt-4 font-display text-3xl font-bold text-rendivoo-900" data-testid="price">
            {{ price.main }}<span v-if="price.period" class="text-base font-medium text-neutral-700"> / {{ price.period }}</span>
        </p>
        <p v-if="price.tax" class="mt-1 text-xs text-muted">{{ price.tax }}</p>
        <p v-if="price.setup" class="text-xs text-muted">{{ price.setup }}</p>
        <p v-if="price.trial" class="text-xs font-medium text-primary-700">{{ price.trial }}</p>

        <ul class="mt-4 flex-1 space-y-1.5 text-sm text-neutral-800">
            <li v-for="line in limits" :key="line" class="flex gap-2">
                <span class="w-4 shrink-0 font-bold text-success-fg" aria-hidden="true">✓</span>
                <span>{{ line }}</span>
            </li>
            <li
                v-for="(feature, index) in plan.features"
                :key="index"
                class="flex gap-2"
                :class="feature.state === 'unavailable' ? 'text-muted line-through' : ''"
            >
                <span
                    class="w-4 shrink-0 font-bold no-underline"
                    :class="feature.state === 'included' ? 'text-success-fg' : feature.state === 'limited' ? 'text-warning-fg' : 'text-danger-fg'"
                    aria-hidden="true"
                    >{{ MARKS[feature.state] }}</span
                >
                <span>
                    {{ feature.label }}<span v-if="feature.value" class="text-muted"> — {{ feature.value }}</span>
                    <span class="sr-only"> ({{ t(`pricing.state.${feature.state}`) }})</span>
                </span>
            </li>
        </ul>

        <a
            :href="plan.cta.url"
            class="mt-5 inline-flex min-h-10 items-center justify-center rounded-lg border border-transparent px-4 py-2 text-sm font-semibold shadow-xs transition-colors"
            :class="plan.recommended ? 'bg-primary-600 text-white hover:bg-primary-700' : 'border-line-strong bg-surface text-neutral-900 hover:bg-surface-2'"
            data-testid="cta"
            >{{ cta }}</a
        >
    </article>
</template>
