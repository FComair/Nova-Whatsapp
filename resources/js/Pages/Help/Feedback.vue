<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import HelpTabs from '../../Components/HelpTabs.vue';
import ReviewLinks from '../../Components/ReviewLinks.vue';
import StarRating from '../../Components/StarRating.vue';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

/** Avis privé à l'équipe Rendivoo (docs/26) : étoiles, commentaire facultatif, rattaché à un ticket si on vient de l'un d'eux. */
const props = defineProps<{
    ticket: { reference: string; subject: string } | null;
    already_sent: boolean;
    sent: string | null;
    links: string[];
    limits: { comment: number };
}>();

const { t } = useT();
const form = useForm({ rating: null as number | null, comment: '', ticket: props.ticket?.reference ?? '' });
const failed = ref(false);

const back = computed(() => (props.ticket ? `${routes.help}/tickets/${props.ticket.reference}` : routes.help));

function submit(): void {
    failed.value = false;
    form.transform((data) => ({ ...data, ticket: data.ticket || undefined })).post(`${routes.help}/avis`, {
        preserveScroll: true,
        onSuccess: () => form.reset('rating', 'comment'),
        // Échec réseau ou serveur : la note et le texte restent dans le formulaire pour réessayer.
        onHttpException: () => ((failed.value = true), false),
        onNetworkError: () => ((failed.value = true), false),
    });
}
</script>

<template>
    <AppLayout :title="t('feedback.title')">
        <HelpTabs />

        <div class="mx-auto max-w-2xl space-y-6">
            <header>
                <h1 class="text-xl font-semibold text-rendivoo-900">{{ t('feedback.title') }}</h1>
                <p class="mt-1 text-neutral-700">{{ t('feedback.intro') }}</p>
                <p v-if="ticket" class="mt-2 text-sm font-semibold text-primary-700">
                    {{ t('feedback.context', { reference: ticket.reference, subject: ticket.subject }) }}
                </p>
            </header>

            <AlertBox v-if="sent" variant="success" role="status" data-testid="feedback-thanks">
                <p class="font-semibold">{{ t('feedback.thanks_title') }}</p>
                <p>{{ t('feedback.thanks') }}</p>
                <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold">
                    <Link v-if="!ticket" :href="`${routes.help}/avis`" class="inline-flex min-h-9 items-center underline underline-offset-4">
                        {{ t('feedback.another') }}
                    </Link>
                    <Link :href="back" class="inline-flex min-h-9 items-center underline underline-offset-4">
                        {{ ticket ? ticket.reference : t('support.tabs.help') }}
                    </Link>
                </div>
            </AlertBox>

            <AlertBox v-else-if="already_sent" variant="info" role="status">{{ t('feedback.already_sent') }}</AlertBox>

            <form v-else class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-xs" novalidate @submit.prevent="submit">
                <AlertBox v-if="failed" variant="error" role="alert">{{ t('feedback.failed') }}</AlertBox>
                <AlertBox v-if="form.errors.ticket" variant="error" role="alert">{{ form.errors.ticket }}</AlertBox>

                <StarRating v-model="form.rating" :label="t('feedback.rating_label')" :error="form.errors.rating" />

                <div>
                    <label for="feedback-comment" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('feedback.comment_label') }}</label>
                    <textarea
                        id="feedback-comment"
                        v-model="form.comment"
                        rows="5"
                        :maxlength="limits.comment"
                        aria-describedby="feedback-comment-hint"
                        :aria-invalid="form.errors.comment ? 'true' : undefined"
                        class="block w-full rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 focus:outline-none focus:ring-2"
                        :class="
                            form.errors.comment
                                ? 'border-danger-fg focus:ring-danger-fg/20'
                                : 'border-line-strong focus:border-primary-600 focus:ring-primary-600/20'
                        "
                    />
                    <div id="feedback-comment-hint" class="mt-1.5 flex justify-between gap-3 text-sm text-muted">
                        <span>{{ t('feedback.comment_hint') }}</span>
                        <span data-testid="feedback-counter">{{ t('feedback.counter', { count: form.comment.length, max: limits.comment }) }}</span>
                    </div>
                    <p v-if="form.errors.comment" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ form.errors.comment }}</p>
                </div>

                <p class="text-sm text-neutral-700">{{ t('feedback.privacy') }}</p>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <BaseButton type="submit" :loading="form.processing">{{ form.processing ? t('feedback.sending') : t('feedback.submit') }}</BaseButton>
                    <Link
                        :href="back"
                        class="inline-flex min-h-11 items-center justify-center rounded-control border-[1.5px] border-line-strong px-5 text-sm font-semibold text-neutral-900 hover:border-primary-600"
                    >
                        {{ t('feedback.not_now') }}
                    </Link>
                </div>
            </form>

            <ReviewLinks :links="links" :feedback-id="sent" />
        </div>
    </AppLayout>
</template>
