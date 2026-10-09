<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import HelpBreadcrumb from '../../Components/HelpBreadcrumb.vue';
import { formatDate } from '../../composables/dates';
import { useT } from '../../composables/useT';
import HelpTabs from '../../Components/HelpTabs.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface Card {
    category: string;
    slug: string;
    title: string;
    summary: string;
}

const props = defineProps<{
    article: Card & { updated: string; html: string; headings: { id: string; text: string }[] };
    related: Card[];
    answered: boolean;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const categoryName = computed(() => t(`help.categories.${props.article.category}.name`));

const sent = ref(props.answered);
const asking = ref(false);
const comment = ref('');
const busy = ref(false);

function send(helpful: boolean, text: string | null = null): void {
    busy.value = true;
    router.post(
        `${routes.help}/retour`,
        { category: props.article.category, slug: props.article.slug, helpful, comment: text || undefined },
        { preserveScroll: true, preserveState: true, onSuccess: () => ((sent.value = true), (asking.value = false)), onFinish: () => (busy.value = false) },
    );
}

/** Les liens vers d'autres articles passent par la navigation de l'application, sans recharger la page. */
function onProseClick(event: MouseEvent): void {
    const link = (event.target as HTMLElement).closest('a');
    const href = link?.getAttribute('href') ?? '';

    if (link && href.startsWith(`${routes.help}/`) && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
        event.preventDefault();
        router.visit(href);
    }
}
</script>

<template>
    <AppLayout :title="article.title">
        <HelpTabs />

        <HelpBreadcrumb :category="{ slug: article.category, name: categoryName }" :current="article.title" />

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>

        <div class="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_16rem]">
            <article class="min-w-0">
                <header>
                    <h1 class="font-display text-3xl font-bold leading-tight text-nova-900">{{ article.title }}</h1>
                    <p class="mt-2 max-w-[72ch] text-lg text-neutral-700">{{ article.summary }}</p>
                    <p v-if="article.updated" class="mt-2 text-sm text-neutral-600">{{ t('help.updated', { date: formatDate(article.updated) }) }}</p>
                </header>

                <details v-if="article.headings.length > 1" class="mt-6 rounded-card border border-line bg-surface p-4 lg:hidden">
                    <summary class="cursor-pointer font-semibold text-neutral-900">{{ t('help.in_article') }}</summary>
                    <ul class="mt-2 space-y-1">
                        <li v-for="heading in article.headings" :key="heading.id">
                            <a :href="`#${heading.id}`" class="text-primary-700 underline underline-offset-4">{{ heading.text }}</a>
                        </li>
                    </ul>
                </details>

                <!-- Contenu rendu côté serveur à partir de nos fichiers Markdown : le HTML brut en est retiré. -->
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div class="nova-prose mt-6" @click="onProseClick" v-html="article.html" />

                <section class="mt-10 rounded-card border border-line bg-surface p-5" aria-labelledby="help-feedback">
                    <h2 id="help-feedback" class="font-semibold text-neutral-900">{{ t('help.feedback.question') }}</h2>

                    <p v-if="sent" class="mt-2 text-neutral-700" role="status">{{ answered ? t('help.feedback.answered') : t('help.feedback.thanks') }}</p>

                    <div v-else-if="!asking" class="mt-3 flex gap-3">
                        <BaseButton variant="secondary" size="sm" class="!border-line-strong" :loading="busy" @click="send(true)">
                            {{ t('help.feedback.yes') }}
                        </BaseButton>
                        <BaseButton variant="secondary" size="sm" class="!border-line-strong" @click="asking = true">{{ t('help.feedback.no') }}</BaseButton>
                    </div>

                    <form v-else class="mt-3 space-y-3" @submit.prevent="send(false, comment.trim())">
                        <label for="help-comment" class="block text-sm font-semibold text-neutral-900">{{ t('help.feedback.comment') }}</label>
                        <textarea
                            id="help-comment"
                            v-model="comment"
                            rows="3"
                            maxlength="500"
                            class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3.5 py-2.5 text-base text-neutral-900 focus:border-primary-600 focus:outline-none focus:ring-4 focus:ring-primary-600/20"
                        />
                        <div class="flex gap-3">
                            <BaseButton type="submit" size="sm" :loading="busy">{{ t('help.feedback.send') }}</BaseButton>
                            <BaseButton variant="secondary" size="sm" :loading="busy" @click="send(false)">{{ t('help.feedback.skip') }}</BaseButton>
                        </div>
                    </form>
                </section>
                <p class="mt-6 text-sm text-neutral-700">
                    <Link
                        :href="`${routes.help}/contact?category=${article.category}&article=${article.slug}`"
                        class="font-semibold text-primary-700 underline underline-offset-4"
                    >
                        {{ t('support.cta.still_stuck') }}
                    </Link>
                </p>

                <section v-if="related.length" class="mt-10" aria-labelledby="help-related">
                    <h2 id="help-related" class="text-lg font-semibold text-nova-900">{{ t('help.related') }}</h2>
                    <ul class="mt-3 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                        <li v-for="item in related" :key="`${item.category}/${item.slug}`">
                            <Link
                                :href="`${routes.help}/${item.category}/${item.slug}`"
                                class="block px-5 py-4 hover:bg-surface-2 focus-visible:outline focus-visible:outline-2"
                            >
                                <span class="font-semibold text-neutral-900">{{ item.title }}</span>
                                <span class="mt-0.5 block text-sm text-neutral-700">{{ item.summary }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <p class="mt-8">
                    <Link :href="routes.help" class="font-semibold text-primary-700 underline underline-offset-4">{{ t('help.back') }}</Link>
                </p>
            </article>

            <aside v-if="article.headings.length > 1" class="hidden lg:block" :aria-label="t('help.in_article')">
                <div class="sticky top-24 rounded-card border border-line bg-surface p-4">
                    <p class="text-sm font-semibold text-neutral-900">{{ t('help.in_article') }}</p>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        <li v-for="heading in article.headings" :key="heading.id">
                            <a :href="`#${heading.id}`" class="text-neutral-700 hover:text-primary-700 hover:underline">{{ heading.text }}</a>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
