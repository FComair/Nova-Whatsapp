<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import HelpBreadcrumb from '../../Components/HelpBreadcrumb.vue';
import HelpIcon from '../../Components/HelpIcon.vue';
import { useHelpCategories } from '../../composables/helpCategories';
import { useT } from '../../composables/useT';
import HelpTabs from '../../Components/HelpTabs.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

const props = defineProps<{
    category: { slug: string; icon: string };
    articles: { category: string; slug: string; title: string; summary: string }[];
}>();

const { t } = useT();
const { categoryName, categoryDescription } = useHelpCategories();
const name = computed(() => categoryName(props.category.slug));
</script>

<template>
    <AppLayout :title="name">
        <HelpTabs />

        <HelpBreadcrumb :category="{ slug: category.slug, name }" />

        <header class="mt-4 flex items-start gap-4">
            <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl bg-mint text-primary-700"><HelpIcon :name="category.icon" /></span>
            <div>
                <h1 class="font-display text-2xl font-bold text-nova-900">{{ name }}</h1>
                <p class="mt-1 text-neutral-700">{{ categoryDescription(category.slug) }}</p>
            </div>
        </header>

        <p v-if="!articles.length" class="mt-6 text-neutral-700">{{ t('help.empty_category') }}</p>
        <ul v-else class="mt-6 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
            <li v-for="article in articles" :key="article.slug">
                <Link
                    :href="`${routes.help}/${article.category}/${article.slug}`"
                    class="block px-5 py-4 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2"
                >
                    <span class="font-semibold text-neutral-900">{{ article.title }}</span>
                    <span class="mt-0.5 block text-sm text-neutral-700">{{ article.summary }}</span>
                </Link>
            </li>
        </ul>
    </AppLayout>
</template>
