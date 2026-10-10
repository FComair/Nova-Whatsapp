<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

/**
 * Bascule « Clients | Fidélité » en tête des écrans clients. La fidélité est proposée au propriétaire, au responsable de site et à la
 * réceptionniste ; le praticien la voit seulement sur la fiche d'un client.
 */
const props = defineProps<{ active: 'clients' | 'loyalty' }>();
const { t } = useT();
const page = usePage<SharedProps>();
const showLoyalty = computed(() => (page.props.auth.organization?.permissions ?? []).includes('loyalty.view'));

const tabs = computed(() => [
    { key: 'clients', href: routes.customers, label: t('loyalty.tabs.clients') },
    ...(showLoyalty.value ? [{ key: 'loyalty', href: routes.loyalty, label: t('loyalty.tabs.loyalty') }] : []),
]);
</script>

<template>
    <nav v-if="tabs.length > 1" :aria-label="t('loyalty.tabs.aria')" class="mb-4 flex flex-wrap gap-2 border-b border-line pb-3">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2"
            :class="tab.key === props.active ? 'bg-primary-50 text-primary-700' : 'text-neutral-800 hover:bg-surface-2 hover:text-primary-600'"
            :aria-current="tab.key === props.active ? 'page' : undefined"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
