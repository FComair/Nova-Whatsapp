<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import HelpBreadcrumb from '../../Components/HelpBreadcrumb.vue';
import HelpSearch from '../../Components/HelpSearch.vue';
import { type HelpEntry, MIN_QUERY, searchHelp } from '../../composables/helpSearch';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{ q: string; index: HelpEntry[] }>();

const { t } = useT();
const results = computed(() => searchHelp(props.index, props.q, 50));
const searched = computed(() => props.q.trim().length >= MIN_QUERY);

/** Résultats regroupés par catégorie, dans l'ordre où elles apparaissent. */
const groups = computed(() => {
    const map = new Map<string, HelpEntry[]>();

    for (const entry of results.value) {
        map.set(entry.category, [...(map.get(entry.category) ?? []), entry]);
    }

    return [...map.entries()];
});
</script>

<template>
    <AppLayout :title="t('help.title')">
        <HelpBreadcrumb :current="searched ? t('help.results_title', { q }) : t('help.search_label')" />

        <div class="mt-4 max-w-2xl"><HelpSearch :index="index" :initial="q" /></div>

        <p v-if="!searched" class="mt-6 text-neutral-700">{{ t('help.results_prompt') }}</p>

        <div v-else-if="!results.length" class="mt-6 rounded-card border border-line bg-surface px-6 py-10 text-center shadow-xs">
            <h1 class="text-lg font-semibold text-neutral-900">{{ t('help.no_results_title', { q }) }}</h1>
            <p class="mt-2 text-neutral-700">{{ t('help.no_results_body') }}</p>
            <Link :href="routes.help" class="mt-4 inline-block font-semibold text-primary-700 underline">{{ t('help.back') }}</Link>
        </div>

        <template v-else>
            <h1 class="mt-6 text-lg font-semibold text-nova-900">{{ t('help.results_title', { q }) }}</h1>
            <p class="text-sm text-neutral-700" aria-live="polite">{{ t('help.results_count', { count: results.length }) }}</p>

            <section v-for="[category, entries] in groups" :key="category" class="mt-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t(`help.categories.${category}.name`) }}</h2>
                <ul class="mt-2 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                    <li v-for="entry in entries" :key="entry.slug">
                        <Link
                            :href="`${routes.help}/${entry.category}/${entry.slug}`"
                            class="block px-5 py-4 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2"
                        >
                            <span class="font-semibold text-neutral-900">{{ entry.title }}</span>
                            <span class="mt-0.5 block text-sm text-neutral-700">{{ entry.summary }}</span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>
    </AppLayout>
</template>
