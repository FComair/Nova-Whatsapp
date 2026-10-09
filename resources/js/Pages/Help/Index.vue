<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import HelpIcon from '../../Components/HelpIcon.vue';
import HelpSearch from '../../Components/HelpSearch.vue';
import type { HelpEntry } from '../../composables/helpSearch';
import { useT } from '../../composables/useT';
import HelpTabs from '../../Components/HelpTabs.vue';
import BaseButton from '../../Components/BaseButton.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

defineProps<{
    categories: { slug: string; icon: string; count: number }[];
    popular: { category: string; slug: string; title: string; summary: string }[];
    index: HelpEntry[];
    total: number;
}>();

const { t } = useT();
</script>

<template>
    <AppLayout :title="t('help.title')">
        <HelpTabs />

        <section class="rounded-card bg-primary-50 px-5 py-10 text-center sm:px-10 sm:py-14">
            <h1 class="font-display text-3xl font-bold text-nova-900 sm:text-4xl">{{ t('help.hero_title') }}</h1>
            <p class="mx-auto mt-2 max-w-xl text-neutral-700">{{ t('help.hero_subtitle') }}</p>
            <div class="mx-auto mt-6 max-w-2xl text-left"><HelpSearch :index="index" /></div>
        </section>

        <section class="mt-10" aria-labelledby="help-topics">
            <h2 id="help-topics" class="text-lg font-semibold text-nova-900">{{ t('help.categories_title') }}</h2>
            <ul class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <li v-for="category in categories" :key="category.slug">
                    <Link
                        :href="`${routes.help}/${category.slug}`"
                        class="flex h-full flex-col gap-2 rounded-card border border-line bg-surface p-4 shadow-xs transition hover:border-primary-600 hover:shadow-md focus-visible:outline focus-visible:outline-2"
                    >
                        <span class="inline-flex size-10 items-center justify-center rounded-xl bg-mint text-primary-700"
                            ><HelpIcon :name="category.icon"
                        /></span>
                        <span class="font-semibold text-neutral-900">{{ t(`help.categories.${category.slug}.name`) }}</span>
                        <span class="hidden text-sm text-neutral-700 sm:block">{{ t(`help.categories.${category.slug}.description`) }}</span>
                        <span class="mt-auto pt-1 text-xs font-semibold text-neutral-600">{{ t('help.articles_count', { count: category.count }) }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section v-if="popular.length" class="mt-10" aria-labelledby="help-popular">
            <h2 id="help-popular" class="text-lg font-semibold text-nova-900">{{ t('help.popular') }}</h2>
            <ul class="mt-4 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                <li v-for="article in popular" :key="`${article.category}/${article.slug}`">
                    <Link
                        :href="`${routes.help}/${article.category}/${article.slug}`"
                        class="block px-5 py-4 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2"
                    >
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-neutral-900">{{ article.title }}</span>
                            <span class="rounded-full bg-mint px-2.5 py-0.5 text-xs font-semibold text-primary-700">{{
                                t(`help.categories.${article.category}.name`)
                            }}</span>
                        </span>
                        <span class="mt-0.5 block text-sm text-neutral-700">{{ article.summary }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="mt-10 rounded-card border border-line bg-surface p-6 text-center shadow-xs sm:p-8">
            <h2 class="text-lg font-semibold text-nova-900">{{ t('support.cta.title') }}</h2>
            <p class="mx-auto mt-1 max-w-xl text-neutral-700">{{ t('support.cta.body') }}</p>
            <div class="mt-4 flex flex-col justify-center gap-3 sm:flex-row">
                <Link :href="`${routes.help}/contact`">
                    <BaseButton>{{ t('support.cta.contact') }}</BaseButton>
                </Link>
                <Link :href="`${routes.help}/tickets`">
                    <BaseButton variant="secondary">{{ t('support.cta.tickets') }}</BaseButton>
                </Link>
            </div>
            <p class="mt-3 text-sm text-neutral-600">{{ t('support.response_time') }}</p>
        </section>
    </AppLayout>
</template>
