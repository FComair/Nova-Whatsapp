<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import HelpTabs from '../../Components/HelpTabs.vue';
import TicketStatusBadge from '../../Components/TicketStatusBadge.vue';
import { formatDateTime } from '../../composables/dates';
import { urgencyVariant } from '../../composables/supportTickets';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';

defineProps<{
    ticket: { reference: string; subject: string; status: string; category: string; urgency: string; created_at: string };
    email: string;
}>();

const { t } = useT();
</script>

<template>
    <AppLayout :title="t('support.sent.title')">
        <HelpTabs />

        <div class="mx-auto max-w-xl text-center">
            <span class="mx-auto inline-flex size-14 items-center justify-center rounded-full bg-success-bg text-success-fg" aria-hidden="true">
                <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
            </span>
            <h1 class="mt-4 font-display text-2xl font-bold text-nova-900">{{ t('support.sent.title') }}</h1>
            <p class="mt-1 text-neutral-700">{{ t('support.sent.email_note', { email }) }}</p>

            <section class="mt-6 rounded-card border border-line bg-surface p-5 text-left shadow-xs" :aria-label="t('support.sent.summary')">
                <p class="text-sm text-neutral-700">{{ t('support.sent.reference') }}</p>
                <p class="font-display text-2xl font-bold text-nova-900">#{{ ticket.reference }}</p>
                <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="font-medium text-neutral-600">{{ t('support.tickets.subject') }}</dt>
                    <dd class="font-semibold text-neutral-900">{{ ticket.subject }}</dd>
                    <dt class="font-medium text-neutral-600">{{ t('support.tickets.category') }}</dt>
                    <dd>{{ t(`support.categories.${ticket.category}`) }}</dd>
                    <dt class="font-medium text-neutral-600">{{ t('support.tickets.urgency') }}</dt>
                    <dd>
                        <Badge :variant="urgencyVariant(ticket.urgency)">{{ t(`support.urgencies.${ticket.urgency}.label`) }}</Badge>
                    </dd>
                    <dt class="font-medium text-neutral-600">{{ t('support.tickets.status') }}</dt>
                    <dd><TicketStatusBadge :status="ticket.status" /></dd>
                    <dt class="font-medium text-neutral-600">{{ t('support.tickets.created') }}</dt>
                    <dd>{{ formatDateTime(ticket.created_at) }}</dd>
                </dl>
                <p class="mt-4 text-sm text-neutral-700">{{ t('support.response_time') }}</p>
            </section>

            <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                <Link :href="`${routes.help}/tickets/${ticket.reference}`">
                    <BaseButton>{{ t('support.sent.view_ticket') }}</BaseButton>
                </Link>
                <Link :href="`${routes.help}/tickets`">
                    <BaseButton variant="secondary">{{ t('support.sent.view_tickets') }}</BaseButton>
                </Link>
                <Link :href="routes.help">
                    <BaseButton variant="ghost">{{ t('support.sent.back_help') }}</BaseButton>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
