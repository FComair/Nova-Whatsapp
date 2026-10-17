<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import LoyaltyPanel from '../../Components/LoyaltyPanel.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { Locale, SharedProps } from '../../types';
import type { CustomerPanelData, LoyaltyAbilities } from '../../types/loyalty';

const props = defineProps<{
    customer: { id: string; name: string | null; email: string | null; phone: string; language: Locale | null; is_blocked: boolean };
    history: { id: string; date: string; time: string; service: string | null; status: string; notes: string | null }[];
    loyalty: { can: LoyaltyAbilities; panel: CustomerPanelData; bookings: unknown[]; configured: boolean } | null;
}>();

const { t } = useT();
const page = usePage<SharedProps>();

const form = useForm({ name: props.customer.name ?? '', email: props.customer.email ?? '', language: props.customer.language ?? '' });
const blocking = useForm({ blocked: !props.customer.is_blocked });

const erasing = ref(false);
const canErase = computed(() => (page.props.auth.organization?.permissions ?? []).includes('customers.erase'));
const eraseError = computed(() => (page.props.errors as Record<string, string | undefined>).erase);

function erase(): void {
    router.post(`${routes.customers}/${props.customer.id}/effacement`, {}, { onFinish: () => (erasing.value = false) });
}

function toggleBlock(): void {
    blocking.blocked = !props.customer.is_blocked;
    blocking.put(`${routes.customers}/${props.customer.id}/blocage`, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="customer.name ?? customer.phone">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-rendivoo-900">{{ customer.name ?? t('customers.unnamed') }}</h1>
            <span v-if="customer.is_blocked" class="rounded-full bg-danger-50 px-2.5 py-0.5 text-xs font-semibold text-danger-700">{{
                t('customers.blocked_badge')
            }}</span>
        </div>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>

        <div class="mt-6 grid gap-8 lg:grid-cols-2">
            <section aria-labelledby="details">
                <h2 id="details" class="text-lg font-semibold text-rendivoo-900">{{ t('customers.details') }}</h2>
                <form class="mt-4 space-y-4" novalidate @submit.prevent="form.put(`${routes.customers}/${customer.id}`, { preserveScroll: true })">
                    <TextField :model-value="customer.phone" :label="t('customers.phone')" :hint="t('customers.phone_hint')" disabled />
                    <TextField v-model="form.name" :label="t('customers.name')" :maxlength="120" :error="form.errors.name" />
                    <TextField v-model="form.email" type="email" :label="t('customers.email')" :error="form.errors.email" />
                    <SelectField
                        v-model="form.language"
                        :label="t('customers.language')"
                        :options="[
                            { value: '', label: t('customers.language_unknown') },
                            ...(['fr', 'nl', 'en'] as const).map((value) => ({ value, label: t(`common.languages.${value}`) })),
                        ]"
                        :error="form.errors.language"
                    />
                    <BaseButton type="submit" :loading="form.processing">{{ t('customers.save') }}</BaseButton>
                </form>

                <div class="mt-8 rounded-card border border-line bg-surface shadow-xs p-4">
                    <p class="text-sm text-neutral-700">{{ t('customers.block_hint') }}</p>
                    <BaseButton class="mt-3" :variant="customer.is_blocked ? 'secondary' : 'danger'" :loading="blocking.processing" @click="toggleBlock">
                        {{ customer.is_blocked ? t('customers.unblock') : t('customers.block') }}
                    </BaseButton>
                </div>

                <div v-if="canErase" class="mt-4 rounded-card border border-line bg-surface shadow-xs p-4">
                    <p class="text-sm text-neutral-700">{{ t('customers.erase_hint') }}</p>
                    <AlertBox v-if="eraseError" variant="error" class="mt-3">{{ eraseError }}</AlertBox>
                    <BaseButton class="mt-3" variant="danger" @click="erasing = true">{{ t('customers.erase') }}</BaseButton>
                </div>
            </section>

            <section aria-labelledby="history">
                <h2 id="history" class="text-lg font-semibold text-rendivoo-900">{{ t('customers.history') }}</h2>
                <p v-if="!history.length" class="mt-4 text-sm text-neutral-600">{{ t('customers.no_history') }}</p>
                <ul v-else class="mt-4 divide-y divide-line rounded-card border border-line bg-surface shadow-xs">
                    <li v-for="item in history" :key="item.id" class="px-4 py-3 text-sm">
                        <div class="flex flex-wrap justify-between gap-2">
                            <span class="font-medium text-neutral-900">{{ formatDate(item.date) }} · {{ item.time }}</span>
                            <span class="text-neutral-600">{{ t(`customers.status.${item.status}`) }}</span>
                        </div>
                        <p class="text-neutral-700">{{ item.service }}</p>
                        <p v-if="item.notes" class="mt-1 whitespace-pre-line text-neutral-600">{{ item.notes }}</p>
                    </li>
                </ul>
            </section>
        </div>

        <div v-if="loyalty" class="mt-8 rounded-card border border-line bg-surface p-5 shadow-xs">
            <LoyaltyPanel :customer-id="customer.id" :panel="loyalty.panel" :can="loyalty.can" :configured="loyalty.configured" />
            <p v-if="!loyalty.configured && loyalty.can.configure" class="mt-3 text-sm">
                <Link :href="`${routes.loyalty}/programme`" class="font-semibold text-primary-700 underline underline-offset-2">
                    {{ t('loyalty.empty.set_up') }}
                </Link>
            </p>
        </div>

        <ConfirmDialog
            :open="erasing"
            :title="t('customers.erase_title')"
            :body="t('customers.erase_body')"
            :confirm-label="t('customers.erase_confirm')"
            @confirm="erase"
            @cancel="erasing = false"
        />
    </AppLayout>
</template>
