<script setup lang="ts">
import { computed } from 'vue';
import PlanCard from '../Components/PlanCard.vue';
import type { PricedPlan } from '../composables/pricing';
import { useT } from '../composables/useT';
import LegalLayout from '../Layouts/LegalLayout.vue';

/**
 * Page tarifs publique : les formules publiées par le Super Admin, dans son ordre, avec le bouton d'action de chacune. Rien n'est écrit en dur ici :
 * prix, descriptions, limites et fonctionnalités viennent de la configuration (PricingController).
 */
const props = defineProps<{
    plans: PricedPlan[];
    trial: { days: number; url: string };
    vat: { rate: number; country: string };
}>();

const { t } = useT();
const columns = computed(() => (props.plans.length >= 3 ? 'lg:grid-cols-3' : props.plans.length === 2 ? 'sm:grid-cols-2' : ''));
</script>

<template>
    <LegalLayout :title="t('pricing.title')">
        <div class="mx-auto max-w-5xl">
            <h1 class="text-3xl font-bold text-rendivoo-900">{{ t('pricing.title') }}</h1>
            <p class="mt-2 max-w-2xl text-neutral-700">{{ t('pricing.subtitle', { days: trial.days }) }}</p>

            <p v-if="plans.length === 0" class="mt-8 rounded-card border border-line bg-surface p-5 text-neutral-800" data-testid="no-plans">
                {{ t('pricing.empty') }}
                <a :href="trial.url" class="font-semibold text-primary-700 underline underline-offset-2">{{ t('pricing.cta.start_trial') }}</a>
            </p>

            <div v-else class="mt-8 grid items-stretch gap-5" :class="columns" data-testid="pricing-cards">
                <PlanCard v-for="plan in plans" :key="plan.code" :plan="plan" />
            </div>

            <p class="mt-6 text-sm text-muted">{{ t('pricing.vat_note', { rate: vat.rate }) }}</p>
        </div>
    </LegalLayout>
</template>
