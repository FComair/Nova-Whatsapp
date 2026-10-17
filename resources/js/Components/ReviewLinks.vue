<script setup lang="ts">
import { computed } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';

/**
 * Avis publics facultatifs (Google, Trustpilot). Toujours les mêmes options, dans le même ordre, pour tout le monde : jamais selon la
 * note, jamais présentées comme une publication automatique. Nouvel onglet, avec la mention qu'on quitte Rendivoo.
 */
const props = withDefaults(defineProps<{ links: string[]; feedbackId?: string | null }>(), { feedbackId: null });
const { t } = useT();

const items = computed(() =>
    props.links.map((platform) => ({
        platform,
        href: `${routes.help}/avis/lien/${platform}${props.feedbackId ? `?feedback=${encodeURIComponent(props.feedbackId)}` : ''}`,
        label: t(`feedback.public.${platform}`),
    })),
);
</script>

<template>
    <section
        v-if="items.length"
        class="rounded-card border border-line bg-surface p-5 shadow-xs"
        aria-labelledby="review-links-title"
        data-testid="review-links"
    >
        <h2 id="review-links-title" class="font-semibold text-neutral-900">{{ t('feedback.public.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-700">{{ t('feedback.public.intro') }}</p>
        <ul class="mt-4 flex flex-col gap-2 sm:flex-row">
            <li v-for="item in items" :key="item.platform">
                <a
                    :href="item.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    :data-platform="item.platform"
                    class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control border-[1.5px] border-line-strong bg-surface px-4 text-sm font-semibold text-primary-700 hover:border-primary-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                >
                    {{ item.label }}
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
                    </svg>
                </a>
            </li>
        </ul>
        <p class="mt-3 text-xs text-neutral-700">{{ t('feedback.public.leaving') }}</p>
        <p class="mt-1 text-xs text-neutral-700">{{ t('feedback.public.separate') }}</p>
        <p class="mt-1 text-xs text-neutral-700">{{ t('feedback.public.neutral') }}</p>
        <p class="mt-1 text-xs text-neutral-700">{{ t('feedback.public.tracking') }}</p>
    </section>
</template>
