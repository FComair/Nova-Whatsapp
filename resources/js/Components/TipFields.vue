<script setup lang="ts">
import { computed } from 'vue';
import { formatPrice } from '../composables/money';
import { parseAmountCents, shareBalance } from '../composables/tips';
import { useT } from '../composables/useT';
import CheckboxField from './CheckboxField.vue';
import TextField from './TextField.vue';

/**
 * Champ « Pourboire » du formulaire de paiement : un montant À PART du prix et du montant encaissé, avec son bénéficiaire. Par
 * défaut le pourboire revient au praticien du rendez-vous ; on peut le répartir entre plusieurs praticiens (les parts doivent
 * totaliser le pourboire). Sans praticien actif attribué, il faut choisir le bénéficiaire. Vide ou 0 : pas de pourboire.
 */
const props = defineProps<{
    practitioners: { id: string; name: string }[];
    /** Praticien du rendez-vous ; `active` est faux s'il n'est plus actif ou a été supprimé. */
    assigned: { id: string; name: string | null; active: boolean };
    currency: string;
    paymentStatus: string;
    errors?: { tip?: string; allocations?: string };
}>();

const tip = defineModel<string>('tip', { required: true });
const split = defineModel<boolean>('split', { required: true });
const shares = defineModel<Record<string, string>>('shares', { required: true });

const { t, locale } = useT();
const cents = computed(() => (tip.value.trim() === '' ? 0 : parseAmountCents(tip.value)));
const hasTip = computed(() => (cents.value ?? 0) > 0);
const mustChoose = computed(() => !props.assigned.active);
const showShares = computed(() => hasTip.value && (split.value || mustChoose.value));
const balance = computed(() => shareBalance(cents.value ?? 0, shares.value));
const money = (value: number): string => formatPrice(Math.abs(value), locale.value, props.currency);
const needsPayment = computed(() => hasTip.value && !['paid_onsite', 'partially_paid', 'waived'].includes(props.paymentStatus));
const amountError = computed(() => props.errors?.tip ?? (tip.value.trim() !== '' && cents.value === null ? t('planning.tip.invalid_amount') : undefined));

function setShare(id: string, value: string): void {
    shares.value = { ...shares.value, [id]: value };
}
</script>

<template>
    <div class="space-y-3" data-testid="tip-fields">
        <TextField v-model="tip" inputmode="decimal" :label="t('planning.tip.label')" :hint="t('planning.tip.hint')" :error="amountError" />

        <template v-if="hasTip">
            <p v-if="mustChoose" class="text-sm font-medium text-warning-fg" role="status" data-testid="tip-no-practitioner">
                {{ t('planning.tip.no_practitioner') }}
            </p>
            <p v-else-if="!split" class="text-sm text-neutral-800" data-testid="tip-recipient">
                {{ t('planning.tip.goes_to', { name: assigned.name ?? '' }) }}
            </p>

            <CheckboxField v-if="!mustChoose && practitioners.length > 1" v-model="split" :label="t('planning.tip.split')" />

            <fieldset v-if="showShares" class="space-y-2 rounded-control border border-line p-3" data-testid="tip-shares">
                <legend class="px-1 text-sm font-semibold text-neutral-900">{{ t('planning.tip.recipient') }}</legend>
                <TextField
                    v-for="practitioner in practitioners"
                    :key="practitioner.id"
                    :model-value="shares[practitioner.id] ?? ''"
                    inputmode="decimal"
                    :label="t('planning.tip.split_amount', { name: practitioner.name })"
                    @update:model-value="setShare(practitioner.id, $event)"
                />
                <p
                    class="text-sm font-medium"
                    :class="balance.state === 'balanced' ? 'text-success-fg' : 'text-warning-fg'"
                    role="status"
                    data-testid="tip-balance"
                >
                    <template v-if="balance.state === 'balanced'">{{ t('planning.tip.balanced') }}</template>
                    <template v-else-if="balance.state === 'invalid'">{{ t('planning.tip.invalid_amount') }}</template>
                    <template v-else-if="balance.state === 'short'">{{ t('planning.tip.remaining', { remaining: money(balance.remaining) }) }}</template>
                    <template v-else>{{ t('planning.tip.over', { over: money(balance.remaining) }) }}</template>
                    <span class="block text-xs font-normal text-neutral-700">{{
                        t('planning.tip.allocated', { allocated: money(balance.allocated), total: money(cents ?? 0) })
                    }}</span>
                </p>
                <p v-if="errors?.allocations" class="text-sm font-medium text-danger-fg" role="alert">{{ errors.allocations }}</p>
            </fieldset>

            <p v-if="needsPayment" class="text-sm font-medium text-warning-fg" role="status" data-testid="tip-needs-payment">
                {{ t('planning.errors.tip_requires_payment') }}
            </p>
        </template>
    </div>
</template>
