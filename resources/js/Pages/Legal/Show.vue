<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatDate } from '../../composables/dates';
import { useT } from '../../composables/useT';
import LegalLayout from '../../Layouts/LegalLayout.vue';

/**
 * Un document juridique (conditions d'utilisation, confidentialité, DPA), à une adresse stable et lisible sans connexion : titre, version, date d'effet,
 * sommaire cliquable, historique des versions, impression et téléchargement. Le HTML vient du serveur, qui retire tout HTML brut du Markdown.
 */
defineProps<{
    document: {
        code: string;
        slug: string;
        title: string;
        version: string;
        effective_on: string;
        html: string;
        toc: { id: string; title: string }[];
        language: string;
        fallback: boolean;
        is_current: boolean;
        current_url: string;
        changes: string | null;
        /** PDF déposé par l'administrateur (docs/35) : affiché dans la page à la place du texte. */
        file?: { url: string; name: string; size: number } | null;
    };
    versions: { version: string; effective_on: string; href: string; current: boolean; viewing: boolean }[];
    documents: { code: string; slug: string; title: string; active: boolean }[];
}>();

const { t, locale } = useT();
const languageName = (code: string): string => t(`common.languages.${code}`);

function print(): void {
    window.print();
}
</script>

<template>
    <LegalLayout :title="document.title">
        <nav class="mb-4 flex flex-wrap gap-2 print:hidden" :aria-label="t('legal.page.others')">
            <Link
                v-for="other in documents"
                :key="other.code"
                :href="`/legal/${other.slug}`"
                class="inline-flex min-h-10 items-center rounded-full px-4 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2"
                :class="other.active ? 'bg-primary-50 text-primary-700' : 'text-neutral-800 hover:bg-surface-2 hover:text-primary-600'"
                :aria-current="other.active ? 'page' : undefined"
            >
                {{ other.title }}
            </Link>
        </nav>

        <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
            <aside class="space-y-5 print:hidden lg:sticky lg:top-4 lg:self-start" aria-label="toc">
                <nav v-if="document.toc.length > 0" :aria-label="t('legal.page.toc')" class="rounded-card border border-line bg-surface p-4 text-sm shadow-xs">
                    <h2 class="font-semibold text-rendivoo-900">{{ t('legal.page.toc') }}</h2>
                    <ol class="mt-2 space-y-1.5">
                        <li v-for="item in document.toc" :key="item.id">
                            <a
                                :href="`#${item.id}`"
                                class="text-primary-700 underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2"
                                >{{ item.title }}</a
                            >
                        </li>
                    </ol>
                </nav>

                <section v-if="versions.length > 1" class="rounded-card border border-line bg-surface p-4 text-sm shadow-xs" aria-labelledby="legal-history">
                    <h2 id="legal-history" class="font-semibold text-rendivoo-900">{{ t('legal.page.history') }}</h2>
                    <ul class="mt-2 space-y-1.5">
                        <li v-for="row in versions" :key="row.version">
                            <Link :href="row.href" class="text-primary-700 underline-offset-2 hover:underline" :aria-current="row.viewing ? 'page' : undefined">
                                {{ row.version }}
                            </Link>
                            <span class="text-neutral-700"> · {{ formatDate(row.effective_on) }}</span>
                            <span v-if="row.current" class="ml-1 rounded-full bg-success-bg px-2 py-0.5 text-xs font-semibold text-success-fg">{{
                                t('legal.page.current')
                            }}</span>
                            <span v-else-if="row.viewing" class="ml-1 text-xs text-neutral-700">({{ t('legal.page.viewing') }})</span>
                        </li>
                    </ul>
                </section>
            </aside>

            <article
                id="document"
                class="rounded-card border border-line bg-surface p-6 shadow-xs sm:p-10 print:border-0 print:p-0 print:shadow-none"
                data-testid="legal-document"
            >
                <header class="border-b border-line pb-5">
                    <h1 class="text-3xl font-semibold leading-tight text-rendivoo-900">{{ document.title }}</h1>
                    <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-neutral-700">
                        <span data-testid="legal-version">{{ t('legal.page.version', { version: document.version }) }}</span>
                        <span data-testid="legal-effective">{{ t('legal.page.effective', { date: formatDate(document.effective_on) }) }}</span>
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2 print:hidden">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center rounded-full border-[1.5px] border-line-strong px-4 text-sm font-semibold text-neutral-900 hover:border-primary-600"
                            @click="print"
                        >
                            {{ t('legal.page.print') }}
                        </button>
                        <a
                            v-if="document.is_current"
                            :href="`/legal/${document.slug}/download`"
                            class="inline-flex min-h-10 items-center rounded-full border-[1.5px] border-line-strong px-4 text-sm font-semibold text-neutral-900 hover:border-primary-600"
                            download
                        >
                            {{ document.file ? t('legal.page.download_pdf') : t('legal.page.download') }}
                        </a>
                    </div>
                </header>

                <p v-if="!document.is_current" class="mt-5 rounded-xl bg-warning-bg px-4 py-3 text-sm text-warning-fg" role="note" data-testid="legal-old">
                    {{ t('legal.page.old') }}
                    <a :href="document.current_url" class="ml-1 font-semibold underline">{{ t('legal.page.see_current') }}</a>
                </p>
                <p v-if="document.fallback" class="mt-5 rounded-xl bg-info-bg px-4 py-3 text-sm text-info-fg" role="note" data-testid="legal-fallback">
                    {{ t('legal.page.fallback', { wanted: languageName(locale), shown: languageName(document.language) }) }}
                </p>
                <p v-if="document.changes" class="mt-5 rounded-xl bg-surface-2 px-4 py-3 text-sm text-neutral-900">
                    <span class="font-semibold">{{ t('legal.page.changes') }} :</span> {{ document.changes }}
                </p>

                <div v-if="document.file" class="mt-6" data-testid="legal-pdf">
                    <iframe
                        :src="document.file.url"
                        :title="document.title"
                        class="h-[80vh] w-full rounded-card border border-line bg-surface-2 print:hidden"
                    />
                    <p class="mt-3 text-sm text-neutral-700">
                        {{ t('legal.page.pdf_fallback') }}
                        <a :href="document.file.url" target="_blank" rel="noopener" class="font-semibold text-primary-700 underline">{{
                            t('legal.page.open_pdf')
                        }}</a>
                    </p>
                </div>
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div v-else class="legal-body mt-6" :lang="document.language" v-html="document.html" />
            </article>
        </div>
    </LegalLayout>
</template>

<style scoped>
.legal-body {
    max-width: 68ch;
    font-size: 1.0625rem;
    line-height: 1.75;
    color: var(--color-neutral-900);
}

.legal-body :deep(h2) {
    margin-top: 2.25rem;
    margin-bottom: 0.5rem;
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.35;
    color: var(--color-rendivoo-900);
    scroll-margin-top: 1.5rem;
}

.legal-body :deep(p),
.legal-body :deep(ul),
.legal-body :deep(ol) {
    margin-top: 0.75rem;
}

.legal-body :deep(ul) {
    list-style: disc;
    padding-left: 1.5rem;
}

.legal-body :deep(ol) {
    list-style: decimal;
    padding-left: 1.5rem;
}

.legal-body :deep(li + li) {
    margin-top: 0.35rem;
}

.legal-body :deep(a) {
    color: var(--color-primary-700);
    text-decoration: underline;
    text-underline-offset: 2px;
}

@media print {
    .legal-body {
        max-width: none;
        font-size: 11pt;
    }
}
</style>
