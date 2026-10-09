<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FileAttachments from '../../Components/FileAttachments.vue';
import HelpTabs from '../../Components/HelpTabs.vue';
import TicketStatusBadge from '../../Components/TicketStatusBadge.vue';
import { formatDateTime } from '../../composables/dates';
import { type AttachmentLimits, formatSize, urgencyVariant } from '../../composables/supportTickets';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface Item {
    type: 'message' | 'event';
    id: string;
    at: string;
    from?: 'you' | 'staff' | 'colleague';
    body?: string;
    attachments?: { id: string; name: string; size: number; mime: string }[];
    action?: string;
    to?: string | null;
    reason?: string | null;
}

const props = defineProps<{
    ticket: {
        reference: string;
        subject: string;
        status: string;
        category: string;
        urgency: string;
        created_at: string;
        last_message_at: string;
        reply_email: string;
        mine: boolean;
    };
    thread: Item[];
    can: { reply: boolean; close: boolean; solved: boolean; reopen: boolean };
    limits: AttachmentLimits;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const base = `${routes.help}/tickets/${props.ticket.reference}`;
const form = useForm({ body: '', attachments: [] as File[] });
const confirmClose = ref(false);
const busy = ref(false);
const replyBox = ref<HTMLElement | null>(null);
const end = ref<HTMLElement | null>(null);

const attachmentError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('attachments'))?.[1]);

/** Texte d'une ligne de l'historique (état modifié, rouvert, fermé). */
function eventText(item: Item): string {
    if (item.action === 'closed') {
        return t(
            item.reason === 'client' ? 'support.events.closed_client' : item.reason === 'staff' ? 'support.events.closed_staff' : 'support.events.closed_auto',
        );
    }

    return item.action === 'reopened'
        ? t('support.events.reopened')
        : t('support.events.status_changed', { status: t(`support.statuses.${item.to ?? 'open'}`) });
}

function scrollToEnd(): void {
    end.value?.scrollIntoView({ block: 'end' });
}

function focusReply(): void {
    replyBox.value?.scrollIntoView({ block: 'center' });
    replyBox.value?.querySelector('textarea')?.focus();
}

function send(): void {
    form.post(`${base}/reponse`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            void nextTick(scrollToEnd);
        },
    });
}

function act(path: string): void {
    busy.value = true;
    router.post(`${base}/${path}`, {}, { preserveScroll: true, onFinish: () => ((busy.value = false), (confirmClose.value = false)) });
}

onMounted(() => void nextTick(scrollToEnd));
</script>

<template>
    <AppLayout :title="`#${ticket.reference} · ${ticket.subject}`">
        <HelpTabs />

        <Link :href="`${routes.help}/tickets`" class="text-sm font-semibold text-primary-700 underline underline-offset-4">{{ t('support.ticket.back') }}</Link>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4" role="status">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="form.errors.body && !can.reply" variant="error" class="mt-4">{{ form.errors.body }}</AlertBox>

        <header class="mt-4 rounded-card border border-line bg-surface p-5 shadow-xs">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-display text-lg font-bold text-primary-700">#{{ ticket.reference }}</p>
                    <h1 class="text-xl font-semibold text-nova-900">{{ ticket.subject }}</h1>
                </div>
                <TicketStatusBadge :status="ticket.status" />
            </div>
            <p class="mt-2 text-sm text-neutral-700">{{ t(`support.status_help.${ticket.status}`) }}</p>
            <dl class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-neutral-700">
                <div>{{ t(`support.categories.${ticket.category}`) }}</div>
                <div>
                    <Badge :variant="urgencyVariant(ticket.urgency)">{{ t(`support.urgencies.${ticket.urgency}.label`) }}</Badge>
                </div>
                <div>{{ t('support.ticket.created_on', { date: formatDateTime(ticket.created_at) }) }}</div>
                <div>{{ t('support.ticket.last_update', { date: formatDateTime(ticket.last_message_at) }) }}</div>
                <div v-if="!ticket.mine">{{ t('support.ticket.opened_by', { email: ticket.reply_email }) }}</div>
            </dl>

            <div v-if="can.reply || can.close || can.solved || can.reopen" class="mt-4 flex flex-wrap gap-2">
                <BaseButton v-if="can.reply" size="sm" @click="focusReply">{{ t('support.ticket.reply_action') }}</BaseButton>
                <BaseButton v-if="can.solved" variant="secondary" class="!border-line-strong" size="sm" :loading="busy" @click="act('resolu')">
                    {{ t('support.ticket.solved') }}
                </BaseButton>
                <BaseButton v-if="can.reopen" variant="secondary" class="!border-line-strong" size="sm" :loading="busy" @click="act('rouvrir')">
                    {{ t('support.ticket.reopen') }}
                </BaseButton>
                <BaseButton v-if="can.close" variant="secondary" class="!border-line-strong" size="sm" @click="confirmClose = true">
                    {{ t('support.ticket.close') }}
                </BaseButton>
            </div>
        </header>

        <ol class="mt-6 space-y-4" :aria-label="ticket.subject">
            <li v-for="item in thread" :key="item.id">
                <p v-if="item.type === 'event'" class="text-center text-xs text-neutral-600">{{ eventText(item) }} · {{ formatDateTime(item.at) }}</p>

                <div v-else class="flex" :class="item.from === 'staff' ? 'justify-start' : 'justify-end'">
                    <article
                        class="max-w-[90%] rounded-card border p-4 sm:max-w-[80%]"
                        :class="item.from === 'staff' ? 'border-line border-l-4 border-l-primary-600 bg-surface' : 'border-transparent bg-mint'"
                    >
                        <header class="flex flex-wrap items-baseline justify-between gap-x-4 text-xs">
                            <span class="font-bold text-neutral-900">{{ t(`support.thread.${item.from}`) }}</span>
                            <time :datetime="item.at" class="text-neutral-700">{{ formatDateTime(item.at) }}</time>
                        </header>
                        <p class="mt-2 whitespace-pre-line break-words text-neutral-900">{{ item.body }}</p>
                        <ul v-if="item.attachments?.length" class="mt-3 flex flex-wrap gap-2" :aria-label="t('support.ticket.attachments')">
                            <li v-for="file in item.attachments" :key="file.id">
                                <a
                                    :href="`${base}/pieces/${file.id}`"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-xs font-semibold text-primary-700 hover:border-primary-600"
                                >
                                    {{ file.name }} <span class="font-normal text-neutral-600">· {{ formatSize(file.size) }}</span>
                                </a>
                            </li>
                        </ul>
                    </article>
                </div>
            </li>
        </ol>
        <div ref="end" />

        <section v-if="can.reply" ref="replyBox" class="mt-6 rounded-card border border-line bg-surface p-5 shadow-xs" :aria-label="t('support.reply.label')">
            <form class="space-y-4" novalidate @submit.prevent="send">
                <div>
                    <label for="ticket-reply" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('support.reply.label') }}</label>
                    <textarea
                        id="ticket-reply"
                        v-model="form.body"
                        rows="5"
                        maxlength="5000"
                        :placeholder="t('support.reply.placeholder')"
                        :aria-invalid="form.errors.body ? 'true' : undefined"
                        class="block w-full rounded-control border-[1.5px] bg-surface px-3.5 py-2.5 text-base text-neutral-900 focus:outline-none focus:ring-4"
                        :class="
                            form.errors.body
                                ? 'border-danger-fg focus:ring-danger-fg/20'
                                : 'border-line-strong focus:border-primary-600 focus:ring-primary-600/20'
                        "
                    />
                    <p v-if="form.errors.body" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ form.errors.body }}</p>
                </div>
                <FileAttachments v-model="form.attachments" :limits="limits" :error="attachmentError" />
                <div class="flex flex-col gap-2 sm:flex-row">
                    <BaseButton type="submit" :loading="form.processing" :disabled="form.body.trim().length < 2">{{ t('support.reply.send') }}</BaseButton>
                    <BaseButton variant="secondary" class="!border-line-strong" :disabled="form.processing" @click="form.reset()">
                        {{ t('support.reply.cancel') }}
                    </BaseButton>
                </div>
            </form>
        </section>

        <AlertBox v-else variant="info" class="mt-6">
            {{ t('support.closed_notice') }}
            <Link :href="`${routes.help}/contact`" class="ml-1 font-semibold underline">{{ t('support.ticket.closed_cta') }}</Link>
        </AlertBox>

        <ConfirmDialog
            :open="confirmClose"
            :title="t('support.ticket.close_title')"
            :body="t('support.ticket.close_body')"
            :confirm-label="t('support.ticket.close_confirm')"
            :loading="busy"
            @confirm="act('fermer')"
            @cancel="confirmClose = false"
        />
    </AppLayout>
</template>
