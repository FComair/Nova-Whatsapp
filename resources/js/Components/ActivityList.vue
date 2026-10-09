<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatDateTime } from '../composables/dates';
import { formatPrice } from '../composables/money';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { ActivityEntry } from '../types/loyalty';

/** Historique du programme (ou d'un client) : ce qui s'est passé, qui l'a fait, pourquoi. Jamais de donnée personnelle dans le détail. */
const props = defineProps<{ entries: ActivityEntry[]; showCustomer?: boolean }>();
const { t, locale } = useT();

function detail(entry: ActivityEntry): string | null {
    const data = entry.detail;

    if (data === null) {
        return null;
    }

    if (entry.kind === 'reward_redeemed' && typeof data.value_cents === 'number') {
        return t('loyalty.detail.value', { amount: formatPrice(data.value_cents, locale.value) });
    }

    if (entry.kind === 'config_changed') {
        if (data.created === true) {
            return t('loyalty.detail.created');
        }

        if (data.messages === true) {
            return t('loyalty.detail.messages');
        }

        const fields = (data.fields as string[] | undefined) ?? [];

        return fields.length > 0 ? t('loyalty.detail.fields', { fields: [...new Set(fields.map((field) => t(`loyalty.field.${field}`)))].join(', ') }) : null;
    }

    if (entry.kind === 'status_changed') {
        return t('loyalty.detail.status', { from: t(`loyalty.status.${String(data.from)}`), to: t(`loyalty.status.${String(data.to)}`) });
    }

    if (entry.kind === 'message_sent' && typeof data.message === 'string') {
        return t('loyalty.detail.message', { kind: t(`loyalty.messages.kinds.${data.message}.title`) });
    }

    if (entry.kind === 'stamp_reversed' && typeof data.shortfall === 'number') {
        return t('loyalty.detail.shortfall', { count: data.shortfall });
    }

    return null;
}

const delta = (entry: ActivityEntry): string => (entry.delta > 0 ? `+${entry.delta}` : String(entry.delta));
</script>

<template>
    <ul class="divide-y divide-line" data-testid="activity-list">
        <li v-for="entry in props.entries" :key="entry.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2.5 text-sm">
            <time :datetime="entry.at" class="w-32 shrink-0 text-xs text-neutral-700">{{ formatDateTime(entry.at) }}</time>
            <span class="font-semibold text-neutral-900">{{ t(`loyalty.kind.${entry.kind}`) }}</span>
            <span v-if="entry.delta !== 0" class="rounded bg-surface-2 px-1.5 text-xs font-semibold tabular-nums" :aria-label="`${delta(entry)}`">{{
                delta(entry)
            }}</span>
            <template v-if="props.showCustomer && entry.customer_id">
                <Link :href="`${routes.customers}/${entry.customer_id}`" class="text-primary-700 underline underline-offset-2">
                    {{ entry.customer ?? t('loyalty.customers.unnamed') }}
                </Link>
            </template>
            <span v-if="detail(entry)" class="text-neutral-700">· {{ detail(entry) }}</span>
            <span v-if="entry.reason" class="basis-full pl-[8.75rem] text-neutral-800">« {{ entry.reason }} »</span>
            <span v-if="entry.actor" class="text-xs text-neutral-600">{{ t('loyalty.index.activity.by', { name: entry.actor }) }}</span>
        </li>
    </ul>
</template>
