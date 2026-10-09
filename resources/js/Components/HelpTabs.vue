<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

/** Onglets de l'espace Aide : centre d'aide, contacter le support, vos tickets (avec le nombre à traiter). */
const { t } = useT();
const page = usePage<SharedProps>();
const url = computed(() => (page as unknown as { url?: string }).url ?? '');
const attention = computed(() => page.props.support?.attention ?? 0);

const tabs = computed(() => [
    {
        key: 'help',
        href: routes.help,
        label: t('support.tabs.help'),
        active: !url.value.startsWith(`${routes.help}/contact`) && !url.value.startsWith(`${routes.help}/tickets`),
    },
    { key: 'contact', href: `${routes.help}/contact`, label: t('support.tabs.contact'), active: url.value.startsWith(`${routes.help}/contact`) },
    { key: 'tickets', href: `${routes.help}/tickets`, label: t('support.tabs.tickets'), active: url.value.startsWith(`${routes.help}/tickets`) },
]);
</script>

<template>
    <nav :aria-label="t('help.title')" class="mb-6 flex flex-wrap gap-2 border-b border-line pb-3">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            class="inline-flex min-h-10 items-center gap-2 rounded-full px-4 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2"
            :class="tab.active ? 'bg-primary-50 text-primary-700' : 'text-neutral-800 hover:bg-surface-2 hover:text-primary-600'"
            :aria-current="tab.active ? 'page' : undefined"
        >
            {{ tab.label }}
            <span
                v-if="tab.key === 'tickets' && attention > 0"
                class="rounded-full bg-primary-600 px-2 py-0.5 text-xs font-bold text-white"
                :title="t('support.cta.attention', { count: attention })"
            >
                {{ attention }}<span class="sr-only"> {{ t('support.cta.attention', { count: attention }) }}</span>
            </span>
        </Link>
    </nav>
</template>
