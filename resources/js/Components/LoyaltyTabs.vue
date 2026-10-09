<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { LoyaltyAbilities } from '../types/loyalty';
import CustomersTabs from './CustomersTabs.vue';

/** En-tête commun des écrans de fidélité : bascule Clients | Fidélité, puis les sous-écrans permis par le rôle. */
const props = defineProps<{ current: 'overview' | 'customers' | 'redeem' | 'report' | 'program' | 'messages'; can: LoyaltyAbilities }>();
const { t } = useT();

const tabs = computed(() => [
    { key: 'overview', href: routes.loyalty, label: t('loyalty.tabs.overview'), show: true },
    { key: 'customers', href: `${routes.loyalty}/clients`, label: t('loyalty.tabs.customers'), show: true },
    { key: 'redeem', href: `${routes.loyalty}/utiliser`, label: t('loyalty.tabs.redeem'), show: props.can.redeem },
    { key: 'report', href: `${routes.loyalty}/rapport`, label: t('loyalty.tabs.report'), show: true },
    { key: 'program', href: `${routes.loyalty}/programme`, label: t('loyalty.tabs.program'), show: props.can.configure },
    { key: 'messages', href: `${routes.loyalty}/messages`, label: t('loyalty.tabs.messages'), show: props.can.configure },
]);
</script>

<template>
    <div>
        <CustomersTabs active="loyalty" />
        <nav :aria-label="t('loyalty.title')" class="mb-6 flex flex-wrap gap-x-1 gap-y-1">
            <template v-for="tab in tabs" :key="tab.key">
                <Link
                    v-if="tab.show"
                    :href="tab.href"
                    class="inline-flex min-h-9 items-center rounded-full px-3 text-sm font-medium transition focus-visible:outline focus-visible:outline-2"
                    :class="tab.key === props.current ? 'bg-neutral-900 text-white' : 'text-neutral-800 hover:bg-surface-2'"
                    :aria-current="tab.key === props.current ? 'page' : undefined"
                >
                    {{ tab.label }}
                </Link>
            </template>
        </nav>
    </div>
</template>
