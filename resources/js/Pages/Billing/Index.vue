<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import TextField from '../../Components/TextField.vue';
import { formatPrice } from '../../composables/money';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

interface PlanItem {
    id: string;
    code: string;
    name: string;
    price_cents: number;
    limits: Record<string, number | null>;
}

const props = defineProps<{
    status: string;
    trialEndsAt: string | null;
    suspensionReason: string | null;
    plans: PlanItem[];
    subscription: {
        status: 'active' | 'past_due' | 'canceled';
        plan: string | null;
        pending_plan: string | null;
        period_end: string;
        cancel_at: string | null;
        failed_attempts: number;
        next_attempt_at: string | null;
    } | null;
    hasActiveSubscription: boolean;
    method: { brand: string | null; last4: string | null; exp_month: number | null; exp_year: number | null } | null;
    vatRate: number;
    invoices: { id: string; number: string; issued_at: string; total_cents: number; status: string }[];
    identity: { billing_name: string; vat_number: string; line1: string; postal_code: string; city: string };
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const identity = useForm({ ...props.identity });

const date = (iso: string): string => formatDate(iso);
const price = (cents: number): string => formatPrice(cents, locale.value);
const systemReason = computed(() => (props.suspensionReason?.startsWith('system.') ? props.suspensionReason.slice(7) : null));
const banner = computed(() => {
    if (props.status !== 'suspended' || systemReason.value === null) {
        return null;
    }

    return { payment_failed: 'suspended_payment', trial_ended: 'suspended_trial', subscription_ended: 'suspended_ended' }[systemReason.value] ?? null;
});

function choose(plan: PlanItem): void {
    router.post(`${routes.billing}/abonnement`, { plan: plan.code });
}

function post(path: string): void {
    router.post(`${routes.billing}/${path}`, {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('billing.title')">
        <h1 class="text-2xl font-semibold text-nova-900">{{ t('billing.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('billing.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-3xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.billing" variant="error" class="mt-4 max-w-3xl">{{ errors.billing }}</AlertBox>
        <AlertBox v-if="banner" variant="warning" class="mt-4 max-w-3xl">{{ t(`billing.${banner}`) }}</AlertBox>

        <section class="mt-6 max-w-3xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="state">
            <h2 id="state" class="text-lg font-semibold text-nova-900">
                {{
                    t(
                        `billing.status.${subscription && subscription.status !== 'canceled' ? subscription.status : status === 'trial' ? 'trial' : subscription ? 'canceled' : status}`,
                    )
                }}
            </h2>
            <p v-if="status === 'trial' && trialEndsAt && !hasActiveSubscription" class="mt-1 text-sm text-neutral-700">
                {{ t('billing.trial_until', { date: date(trialEndsAt) }) }}
            </p>
            <template v-if="subscription && subscription.status !== 'canceled'">
                <p v-if="subscription.cancel_at" class="mt-1 text-sm text-neutral-700">{{ t('billing.cancels_on', { date: date(subscription.cancel_at) }) }}</p>
                <p v-else-if="subscription.status === 'past_due' && subscription.next_attempt_at" class="mt-1 text-sm text-danger-700">
                    {{ t('billing.retry_on', { count: subscription.failed_attempts, date: date(subscription.next_attempt_at) }) }}
                </p>
                <p v-else class="mt-1 text-sm text-neutral-700">{{ t('billing.renews_on', { date: date(subscription.period_end) }) }}</p>
                <div class="mt-3">
                    <BaseButton
                        v-if="subscription.cancel_at"
                        variant="secondary"
                        @click="router.delete(`${routes.billing}/annulation`, { preserveScroll: true })"
                    >
                        {{ t('billing.resume') }}
                    </BaseButton>
                    <template v-else>
                        <p class="mb-2 text-sm text-neutral-600">{{ t('billing.cancel_hint') }}</p>
                        <BaseButton variant="secondary" @click="post('annulation')">{{ t('billing.cancel') }}</BaseButton>
                    </template>
                </div>
            </template>
        </section>

        <section class="mt-8 max-w-3xl" aria-labelledby="plans">
            <h2 id="plans" class="text-lg font-semibold text-nova-900">{{ t('billing.plans_title') }}</h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <article
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex flex-col rounded-card border bg-surface p-5"
                    :class="subscription?.plan === plan.code && hasActiveSubscription ? 'border-primary-600' : 'border-line'"
                >
                    <h3 class="text-lg font-semibold text-nova-900">{{ plan.name }}</h3>
                    <p class="mt-1 text-sm text-neutral-700">{{ t('billing.per_month', { price: price(plan.price_cents) }) }}</p>
                    <ul class="mt-3 flex-1 space-y-1 text-sm text-neutral-700">
                        <li v-if="plan.limits.practitioners">{{ t('billing.limit_practitioners', { count: plan.limits.practitioners }) }}</li>
                        <li v-if="plan.limits.conversations">{{ t('billing.limit_conversations', { count: plan.limits.conversations }) }}</li>
                        <li v-if="plan.limits.appointments">{{ t('billing.limit_appointments', { count: plan.limits.appointments }) }}</li>
                    </ul>
                    <p v-if="subscription?.pending_plan === plan.code" class="mt-3 text-sm font-medium text-warning-600">{{ t('billing.pending') }}</p>
                    <p v-if="subscription?.plan === plan.code && hasActiveSubscription" class="mt-3 text-sm font-semibold text-primary-700">
                        {{ t('billing.current') }}
                    </p>
                    <BaseButton v-else class="mt-4" @click="choose(plan)">{{ hasActiveSubscription ? t('billing.change') : t('billing.choose') }}</BaseButton>
                </article>
            </div>
            <p class="mt-3 text-sm text-neutral-600">{{ t('billing.vat_note', { rate: vatRate }) }}</p>
        </section>

        <section class="mt-8 max-w-3xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="method">
            <h2 id="method" class="text-lg font-semibold text-nova-900">{{ t('billing.method_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">
                {{
                    method
                        ? t('billing.method_value', {
                              brand: method.brand ?? '',
                              last4: method.last4 ?? '',
                              month: method.exp_month ?? '',
                              year: method.exp_year ?? '',
                          })
                        : t('billing.method_none')
                }}
            </p>
            <p class="mt-1 text-sm text-neutral-600">{{ t('billing.method_hint') }}</p>
            <div v-if="hasActiveSubscription" class="mt-3">
                <BaseButton variant="secondary" @click="post('moyen-de-paiement')">{{ t('billing.method_change') }}</BaseButton>
            </div>
        </section>

        <section class="mt-8 max-w-3xl" aria-labelledby="invoices">
            <h2 id="invoices" class="text-lg font-semibold text-nova-900">{{ t('billing.invoices_title') }}</h2>
            <p v-if="!invoices.length" class="mt-2 text-sm text-neutral-600">{{ t('billing.invoices_empty') }}</p>
            <ul v-else class="mt-3 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                <li v-for="invoice in invoices" :key="invoice.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                    <span class="font-medium text-neutral-900">{{ invoice.number }} · {{ date(invoice.issued_at) }}</span>
                    <span class="flex items-center gap-3">
                        <span class="text-neutral-700">{{ price(invoice.total_cents) }} · {{ t(`billing.invoice_status.${invoice.status}`) }}</span>
                        <Link :href="`${routes.billing}/factures/${invoice.id}`" class="font-medium text-primary-700 underline">{{
                            t('billing.invoice_open')
                        }}</Link>
                    </span>
                </li>
            </ul>
        </section>

        <section class="mt-8 max-w-3xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="identity">
            <h2 id="identity" class="text-lg font-semibold text-nova-900">{{ t('billing.identity_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ t('billing.identity_hint') }}</p>
            <form class="mt-4 space-y-4" novalidate @submit.prevent="identity.put(`${routes.billing}/identite`, { preserveScroll: true })">
                <TextField v-model="identity.billing_name" :label="t('billing.identity_name')" :maxlength="255" :error="identity.errors.billing_name" />
                <TextField v-model="identity.vat_number" :label="t('billing.identity_vat')" :maxlength="30" :error="identity.errors.vat_number" />
                <TextField v-model="identity.line1" :label="t('billing.identity_address')" :maxlength="255" :error="identity.errors.line1" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <TextField v-model="identity.postal_code" :label="t('billing.identity_postal')" :maxlength="20" :error="identity.errors.postal_code" />
                    <TextField v-model="identity.city" :label="t('billing.identity_city')" :maxlength="120" :error="identity.errors.city" />
                </div>
                <BaseButton type="submit" :loading="identity.processing">{{ t('billing.identity_save') }}</BaseButton>
            </form>
        </section>
    </AppLayout>
</template>
