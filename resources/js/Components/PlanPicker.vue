<script setup lang="ts">
import { computed, useId } from 'vue';
import { formatPrice } from '../composables/money';
import { periodLabel } from '../composables/pricing';
import { type Offers, TRIAL, limitLines } from '../composables/signupPlans';
import { useT } from '../composables/useT';

/**
 * Comparaison des formules à l'inscription : une carte par choix (essai gratuit, puis chaque formule payante active), avec prix, limites et
 * conditions de l'essai, toutes issues de la configuration. Boutons radio natifs : flèches du clavier, focus visible, lecteurs d'écran ;
 * la sélection se voit aussi sans la couleur (coche et libellé « Formule sélectionnée »). « Recommandée » seulement si la plateforme l'a désignée.
 */
const props = defineProps<{ offers: Offers; error?: string }>();
const model = defineModel<string>({ required: true });
const { t, locale } = useT();
const name = useId();

const cards = computed(() => [
    {
        code: TRIAL,
        title: t('register.plans.trial'),
        price: t('register.trial_price', { count: props.offers.trial.days, days: props.offers.trial.days }),
        note: [props.offers.trial.based_on ? t('register.trial_limits', { plan: props.offers.trial.based_on }) : '', t('register.trial_after')].filter(Boolean),
        limits: limitLines(props.offers.trial.limits, t),
        recommended: false,
    },
    ...props.offers.plans.map((plan) => ({
        code: plan.code,
        title: plan.name,
        price: t('billing.price_per', { price: formatPrice(plan.price_cents, locale.value, plan.currency), period: periodLabel(plan.interval_months ?? 1, t) }),
        note: [] as string[],
        limits: limitLines(plan.limits, t),
        recommended: plan.recommended,
    })),
]);
</script>

<template>
    <fieldset>
        <legend class="sr-only">{{ t('register.plan_group') }}</legend>
        <div class="grid gap-3" :class="cards.length > 2 ? 'md:grid-cols-3' : cards.length === 2 ? 'md:grid-cols-2' : ''" data-testid="plan-cards">
            <label
                v-for="card in cards"
                :key="card.code"
                class="relative flex cursor-pointer flex-col rounded-card border-2 bg-surface p-4 transition has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-600"
                :class="model === card.code ? 'border-primary-600 bg-primary-50 shadow-sm' : 'border-line hover:border-primary-300'"
                :data-testid="`plan-${card.code}`"
            >
                <input v-model="model" type="radio" :name="name" :value="card.code" class="sr-only" :aria-describedby="`${name}-${card.code}`" />
                <span class="flex items-start justify-between gap-2">
                    <span class="font-semibold text-nova-900">{{ card.title }}</span>
                    <span v-if="card.recommended" class="rounded-full bg-amber px-2 py-0.5 text-xs font-bold text-nova-900" data-testid="recommended">{{
                        t('register.recommended')
                    }}</span>
                </span>
                <span :id="`${name}-${card.code}`" class="mt-1 block text-sm font-medium text-neutral-900">{{ card.price }}</span>
                <ul v-if="card.limits.length > 0" class="mt-3 space-y-1 text-sm text-neutral-800">
                    <li v-for="line in card.limits" :key="line" class="flex gap-2">
                        <svg
                            viewBox="0 0 16 16"
                            class="mt-0.5 size-4 shrink-0 text-primary-600"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="m3.5 8.4 3 3 6-6.4" />
                        </svg>
                        {{ line }}
                    </li>
                </ul>
                <p v-for="line in card.note" :key="line" class="mt-2 text-xs text-neutral-700">{{ line }}</p>
                <span
                    v-if="model === card.code"
                    class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700"
                    data-testid="selected-mark"
                >
                    <svg
                        viewBox="0 0 16 16"
                        class="size-4"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <circle cx="8" cy="8" r="6.25" />
                        <path d="m5.3 8.2 1.8 1.8 3.6-3.8" />
                    </svg>
                    {{ t('register.selected') }}
                </span>
            </label>
        </div>
        <p v-if="error" class="mt-2 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </fieldset>
</template>
