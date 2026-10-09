<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import BaseButton from './BaseButton.vue';

/**
 * Invitation discrète à donner son avis après un échange avec le support. Facultative et refusable : « Pas maintenant » ne la
 * repropose pas pour ce moment ; rien n'est exigé pour fermer le ticket ni pour continuer.
 */
const props = defineProps<{ prompt: { key: string; context: 'intervention' | 'ticket' }; reference: string }>();
const { t } = useT();
const hidden = ref(false);

function dismiss(): void {
    hidden.value = true;
    router.post(`${routes.help}/avis/plus-tard`, { key: props.prompt.key }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <section
        v-if="!hidden"
        class="mt-6 rounded-card border border-line bg-surface p-5 shadow-xs"
        aria-labelledby="feedback-prompt-title"
        data-testid="feedback-prompt"
    >
        <h2 id="feedback-prompt-title" class="font-semibold text-neutral-900">{{ t(`feedback.prompt.${prompt.context}`) }}</h2>
        <p class="mt-1 text-sm text-neutral-700">{{ t('feedback.prompt.body') }}</p>
        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <Link
                :href="`${routes.help}/avis?ticket=${reference}`"
                class="inline-flex min-h-11 items-center justify-center rounded-control bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
            >
                {{ t('feedback.prompt.give') }}
            </Link>
            <BaseButton variant="secondary" class="!border-line-strong" @click="dismiss">{{ t('feedback.prompt.dismiss') }}</BaseButton>
        </div>
        <p class="mt-3 text-xs text-neutral-700">{{ t('feedback.prompt.later') }}</p>
    </section>
</template>
