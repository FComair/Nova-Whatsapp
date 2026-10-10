<script setup lang="ts">
import BaseButton from '../../Components/BaseButton.vue';
import { formatPrice } from '../../composables/money';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import AppLayout from '../../Layouts/AppLayout.vue';

interface Party {
    name?: string | null;
    vat_number?: string | null;
    address?: string | Record<string, string | null> | null;
    country?: string | null;
}

defineProps<{
    invoice: {
        number: string;
        status: string;
        issued_at: string;
        paid_at: string | null;
        period_start: string;
        period_end: string;
        subtotal_cents: number;
        vat_rate: number;
        vat_cents: number;
        total_cents: number;
        seller: Party;
        customer: Party;
        lines: { description: string; quantity: number; unit_cents: number; total_cents: number }[];
    };
}>();

const { t, locale } = useT();

const date = (iso: string): string => formatDate(iso);
const price = (cents: number): string => formatPrice(cents, locale.value);

function print(): void {
    window.print();
}

function address(party: Party): string {
    const value = party.address;

    return typeof value === 'string'
        ? value
        : Object.values(value ?? {})
              .filter(Boolean)
              .join(', ');
}
</script>

<template>
    <AppLayout :title="t('billing.invoice_title', { number: invoice.number })">
        <div class="flex flex-wrap items-center justify-end gap-3 print:hidden">
            <BaseButton variant="secondary" @click="print">{{ t('billing.invoice_print') }}</BaseButton>
        </div>

        <article class="mt-4 max-w-3xl rounded-card border border-line bg-surface shadow-xs p-6 print:border-0 print:p-0">
            <h1 class="text-2xl font-semibold text-nova-900">{{ t('billing.invoice_title', { number: invoice.number }) }}</h1>
            <p class="mt-1 text-sm text-neutral-600">
                {{ t('billing.invoice_date') }} : {{ date(invoice.issued_at) }} · {{ t('billing.invoice_period') }} : {{ date(invoice.period_start) }} –
                {{ date(invoice.period_end) }}
            </p>
            <p v-if="invoice.paid_at" class="mt-1 text-sm font-medium text-success-600">{{ t('billing.invoice_paid', { date: date(invoice.paid_at) }) }}</p>

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('billing.invoice_seller') }}</h2>
                    <p class="mt-1 font-medium text-neutral-900">{{ invoice.seller.name }}</p>
                    <p class="text-sm text-neutral-700">{{ address(invoice.seller) }}</p>
                    <p v-if="invoice.seller.vat_number" class="text-sm text-neutral-700">{{ invoice.seller.vat_number }}</p>
                </div>
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('billing.invoice_customer') }}</h2>
                    <p class="mt-1 font-medium text-neutral-900">{{ invoice.customer.name }}</p>
                    <p class="text-sm text-neutral-700">{{ address(invoice.customer) }}</p>
                    <p v-if="invoice.customer.vat_number" class="text-sm text-neutral-700">{{ invoice.customer.vat_number }}</p>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-line-strong text-neutral-600">
                        <tr>
                            <th class="py-2 pr-3 font-medium">{{ t('billing.invoice_description') }}</th>
                            <th class="px-3 py-2 font-medium">{{ t('billing.invoice_quantity') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ t('billing.invoice_unit') }}</th>
                            <th class="py-2 pl-3 text-right font-medium">{{ t('billing.invoice_subtotal') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(line, index) in invoice.lines" :key="index">
                            <td class="py-2 pr-3">{{ line.description }}</td>
                            <td class="px-3 py-2">{{ line.quantity }}</td>
                            <td class="px-3 py-2 text-right">{{ price(line.unit_cents) }}</td>
                            <td class="py-2 pl-3 text-right">{{ price(line.total_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <dl class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
                <div class="flex justify-between">
                    <dt>{{ t('billing.invoice_subtotal') }}</dt>
                    <dd>{{ price(invoice.subtotal_cents) }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt>{{ t('billing.invoice_vat', { rate: invoice.vat_rate }) }}</dt>
                    <dd>{{ price(invoice.vat_cents) }}</dd>
                </div>
                <div class="flex justify-between border-t border-line-strong pt-1 text-base font-semibold">
                    <dt>{{ t('billing.invoice_total') }}</dt>
                    <dd>{{ price(invoice.total_cents) }}</dd>
                </div>
            </dl>
        </article>
    </AppLayout>
</template>
