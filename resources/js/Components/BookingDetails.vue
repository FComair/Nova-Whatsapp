<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { centsToInput, formatPrice } from '../composables/money';
import { usePastTimeGuard } from '../composables/pastTime';
import { extraError } from '../composables/planning';
import { parseAmountCents, shareBalance, sharesToPayload, totalPaid } from '../composables/tips';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { PlanningBooking, PlanningPractitioner, PlanningService, SharedProps, Suggestion } from '../types';
import AlertBox from './AlertBox.vue';
import BaseButton from './BaseButton.vue';
import CheckboxField from './CheckboxField.vue';
import PractitionerField from './PractitionerField.vue';
import SelectField from './SelectField.vue';
import SlotSuggestions from './SlotSuggestions.vue';
import TextField from './TextField.vue';
import TipFields from './TipFields.vue';
import TimeFields from './TimeFields.vue';

const props = defineProps<{
    booking: PlanningBooking;
    date: string;
    practitioners: PlanningPractitioner[];
    services?: PlanningService[];
    canReassign?: boolean;
    /** Fuseau du site : l'heure « maintenant » du formulaire de déplacement. */
    timezone: string;
}>();
const emit = defineEmits<{ done: [] }>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const base = computed(() => `${routes.planning}/rendez-vous/${props.booking.id}`);
const mode = ref<'view' | 'move' | 'cancel' | 'payment'>('view');

const moveForm = useForm({ date: props.date, time: props.booking.starts, practitioner_id: props.booking.practitioner_id, notify: true });
// Déplacement : pas de nouvelle heure dans le passé, mais l'horaire actuel (même passé) reste valable tant qu'on n'y touche pas.
const past = usePastTimeGuard(
    props.timezone,
    () => moveForm.date,
    () => moveForm.time,
    () => ({ date: props.date, time: props.booking.starts }),
);
watch(
    () => [moveForm.date, moveForm.time],
    () => moveForm.clearErrors('date', 'time'),
);
function submitMove(): void {
    if (!past.validNow()) {
        moveForm.setError('time', past.message.value);

        return;
    }

    moveForm.put(`${base.value}/deplacer`, options);
}
const cancelForm = useForm({ reason: '', notify: true });
const finishForm = useForm({ outcome: 'completed' });
// Paiement : prix encaissé, statut, moyen — et le pourboire, un montant À PART (jamais ajouté au prix ni à l'encaissé du service).
// Le formulaire reprend le pourboire existant : l'enregistrer tel quel ne le modifie pas, le vider le supprime.
const existingTip = props.booking.tip;
const paymentForm = useForm({
    payment_status: props.booking.payment_status,
    amount: '',
    method: '',
    tip: existingTip === null ? '' : centsToInput(existingTip.amount_cents, locale.value),
    tip_allocations: [] as { practitioner_id: string; amount: string }[],
});
const assignedActive = computed(() => props.practitioners.some((practitioner) => practitioner.id === props.booking.practitioner_id));
const tipSplit = ref(
    existingTip !== null && (existingTip.allocations.length > 1 || existingTip.allocations[0]?.practitioner_id !== props.booking.practitioner_id),
);
const tipShares = reactive<Record<string, string>>({});
const tipSharesModel = computed({ get: () => tipShares, set: (value) => Object.assign(tipShares, value) });
(existingTip?.allocations ?? []).forEach((row) => (tipShares[row.practitioner_id] = centsToInput(row.amount_cents, locale.value)));

// En passant à la répartition, la totalité du pourboire est d'abord proposée au praticien du rendez-vous.
watch(tipSplit, (on) => {
    const cents = parseAmountCents(paymentForm.tip) ?? 0;

    if (on && cents > 0 && Object.keys(tipShares).length === 0 && assignedActive.value) {
        tipShares[props.booking.practitioner_id] = centsToInput(cents, locale.value);
    }
});
watch(
    () => paymentForm.tip,
    () => paymentForm.clearErrors('tip', 'tip_allocations'),
);

// Contrôles du formulaire avant l'envoi ; le serveur revérifie tout (montant, répartition, bénéficiaires, droits).
function submitPayment(): void {
    const raw = paymentForm.tip.trim();
    const cents = raw === '' ? 0 : parseAmountCents(raw);

    if (cents === null) {
        paymentForm.setError('tip', t('planning.tip.invalid_amount'));

        return;
    }

    const distribute = cents > 0 && (tipSplit.value || !assignedActive.value);

    if (distribute && shareBalance(cents, tipShares).state !== 'balanced') {
        paymentForm.setError('tip_allocations', t('planning.errors.tip_allocation_mismatch'));

        return;
    }

    paymentForm.transform((data) => ({ ...data, tip_allocations: distribute ? sharesToPayload(tipShares) : [] })).put(`${base.value}/paiement`, options);
}
const tipCents = computed(() => props.booking.tip?.amount_cents ?? 0);
const tipRecipients = computed(() =>
    (props.booking.tip?.allocations ?? [])
        .map((row) => `${row.practitioner_name ?? '—'} (${formatPrice(row.amount_cents, locale.value, props.booking.currency)})`)
        .join(' · '),
);
const money = (cents: number): string => formatPrice(cents, locale.value, props.booking.currency);

// Praticiens actifs du site qui réalisent le service de ce rendez-vous.
const eligible = computed(() => {
    const allowed = props.services?.find((service) => service.id === props.booking.service_id)?.practitioner_ids;

    return allowed === undefined ? props.practitioners : props.practitioners.filter((practitioner) => allowed.includes(practitioner.id));
});
const canChangePractitioner = computed(() => props.canReassign === true && isOpen.value);

const currentPhoto = computed(() => props.practitioners.find((practitioner) => practitioner.id === props.booking.practitioner_id)?.photo_version ?? null);
const isOpen = computed(() => props.booking.status === 'pending' || props.booking.status === 'confirmed');
const canFinish = computed(() => props.booking.status === 'confirmed' && props.booking.is_past);
const canPay = computed(() => ['confirmed', 'completed', 'no_show'].includes(props.booking.status));
const suggestions = computed(() => (extraError(moveForm.errors, 'slot') ? page.props.flash.suggestions : []));
const errors = computed(
    () =>
        extraError(moveForm.errors, 'slot') ?? [moveForm, cancelForm, finishForm, paymentForm].map((form) => extraError(form.errors, 'booking')).find(Boolean),
);

const options = { preserveScroll: true, onSuccess: () => emit('done') };

function finish(outcome: 'completed' | 'no_show'): void {
    finishForm.outcome = outcome;
    finishForm.post(`${base.value}/resultat`, options);
}

function pick(suggestion: Suggestion): void {
    moveForm.date = suggestion.date;
    moveForm.time = suggestion.time;
    moveForm.practitioner_id = suggestion.practitioner_id;
}
</script>

<template>
    <div class="space-y-4">
        <AlertBox v-if="errors" variant="error">{{ errors }}</AlertBox>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
            <dt class="font-medium text-neutral-600">{{ t('planning.form.time') }}</dt>
            <dd>{{ booking.starts }} – {{ booking.ends }} · {{ t(`planning.status.${booking.status}`) }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.form.service') }}</dt>
            <dd>{{ booking.service }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.form.practitioner') }}</dt>
            <dd><PractitionerField :booking="booking" :eligible="eligible" :can-change="canChangePractitioner" :photo-version="currentPhoto" /></dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.form.name') }}</dt>
            <dd>{{ booking.customer.name ?? '—' }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.form.phone') }}</dt>
            <dd>{{ booking.customer.phone }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.payment.status') }}</dt>
            <dd>{{ t(`planning.payment.${booking.payment_status}`) }}</dd>
            <!-- Le montant du service, le pourboire et le total payé sont trois lignes distinctes : le total dit qu'il inclut le pourboire. -->
            <dt class="font-medium text-neutral-600">{{ t('planning.tip.service_price') }}</dt>
            <dd data-testid="pay-price">{{ money(booking.price_cents) }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.tip.service_paid') }}</dt>
            <dd data-testid="pay-collected">{{ money(booking.amount_collected_cents) }}</dd>
            <dt class="font-medium text-neutral-600">{{ t('planning.tip.line') }}</dt>
            <dd data-testid="pay-tip">
                <template v-if="booking.tip">
                    {{ money(tipCents) }}
                    <span class="block text-xs text-neutral-700">{{ t('planning.tip.to', { list: tipRecipients }) }}</span>
                </template>
                <template v-else>{{ t('planning.tip.none') }}</template>
            </dd>
            <template v-if="booking.tip || booking.amount_collected_cents > 0">
                <dt class="font-medium text-neutral-600">{{ t('planning.tip.total_paid') }}</dt>
                <dd class="font-semibold" data-testid="pay-total">
                    {{ money(totalPaid(booking.amount_collected_cents, tipCents)) }}
                    <span class="block text-xs font-normal text-neutral-700">{{ t('planning.tip.includes_tip') }}</span>
                </dd>
            </template>
            <!-- Fidélité (docs/27) : progression du client, tampon gagné ici, récompense appliquée (elle ne change ni le prix ni le paiement). -->
            <template v-if="booking.loyalty">
                <dt class="font-medium text-neutral-600">{{ t('loyalty.booking.title') }}</dt>
                <dd data-testid="booking-loyalty">
                    <template v-if="booking.loyalty.enrolled && booking.loyalty.stamps !== null">
                        {{ t('loyalty.booking.progress', { stamps: booking.loyalty.stamps, required: booking.loyalty.required }) }}
                    </template>
                    <template v-else>{{ t('loyalty.booking.not_enrolled') }}</template>
                    <span v-if="booking.loyalty.earned_here" class="block text-xs text-neutral-700">{{ t('loyalty.booking.earned_here') }}</span>
                    <span v-if="booking.loyalty.reward_applied_cents !== null" class="block text-xs text-neutral-700">{{
                        t('loyalty.booking.reward_applied', { amount: money(booking.loyalty.reward_applied_cents) })
                    }}</span>
                    <span v-if="booking.loyalty.rewards_available > 0" class="block text-xs font-semibold text-success-fg">{{
                        t('loyalty.booking.available', { count: booking.loyalty.rewards_available })
                    }}</span>
                </dd>
            </template>
            <template v-if="booking.notes">
                <dt class="font-medium text-neutral-600">{{ t('planning.form.notes') }}</dt>
                <dd class="whitespace-pre-line">{{ booking.notes }}</dd>
            </template>
        </dl>

        <div v-if="mode === 'view'" class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
            <BaseButton v-if="canFinish" :loading="finishForm.processing" @click="finish('completed')">{{ t('planning.actions.completed') }}</BaseButton>
            <BaseButton v-if="canFinish" variant="secondary" :loading="finishForm.processing" @click="finish('no_show')">
                {{ t('planning.actions.no_show') }}
            </BaseButton>
            <BaseButton v-if="isOpen" variant="secondary" @click="mode = 'move'">{{ t('planning.actions.move') }}</BaseButton>
            <BaseButton v-if="canPay" variant="secondary" @click="mode = 'payment'">{{ t('planning.actions.payment') }}</BaseButton>
            <BaseButton v-if="isOpen" variant="danger" @click="mode = 'cancel'">{{ t('planning.actions.cancel') }}</BaseButton>
        </div>

        <form v-else-if="mode === 'move'" class="space-y-4" novalidate @submit.prevent="submitMove">
            <SlotSuggestions :suggestions="suggestions" :practitioners="practitioners" @pick="pick" />
            <SelectField
                v-model="moveForm.practitioner_id"
                :label="t('planning.form.practitioner')"
                :options="practitioners.map((practitioner) => ({ value: practitioner.id, label: practitioner.name }))"
                :error="moveForm.errors.practitioner_id"
            />
            <div class="grid gap-4 sm:grid-cols-2">
                <TextField
                    v-model="moveForm.date"
                    type="date"
                    :label="t('planning.form.date')"
                    required
                    :error="past.dateError.value ?? moveForm.errors.date"
                />
                <TimeFields
                    v-model="moveForm.time"
                    :label="t('planning.form.time')"
                    required
                    :min-minutes="past.minMinutes.value"
                    :error="past.timeError.value ?? moveForm.errors.time"
                />
            </div>
            <CheckboxField v-model="moveForm.notify" :label="t('planning.notify')" />
            <div class="flex flex-col gap-2 sm:flex-row">
                <BaseButton type="submit" :loading="moveForm.processing">{{ t('planning.actions.move_submit') }}</BaseButton>
                <BaseButton variant="secondary" @click="mode = 'view'">{{ t('common.cancel') }}</BaseButton>
            </div>
        </form>

        <form v-else-if="mode === 'cancel'" class="space-y-4" novalidate @submit.prevent="cancelForm.post(`${base}/annuler`, options)">
            <p class="text-sm text-neutral-700">{{ t('planning.cancel.body') }}</p>
            <TextField v-model="cancelForm.reason" :label="t('planning.cancel.reason')" :maxlength="255" :error="cancelForm.errors.reason" />
            <CheckboxField v-model="cancelForm.notify" :label="t('planning.notify')" />
            <div class="flex flex-col gap-2 sm:flex-row">
                <BaseButton type="submit" variant="danger" :loading="cancelForm.processing">{{ t('planning.cancel.confirm') }}</BaseButton>
                <BaseButton variant="secondary" @click="mode = 'view'">{{ t('common.back') }}</BaseButton>
            </div>
        </form>

        <form v-else class="space-y-4" novalidate @submit.prevent="submitPayment">
            <SelectField
                v-model="paymentForm.payment_status"
                :label="t('planning.payment.status')"
                :options="
                    ['unpaid', 'paid_onsite', 'partially_paid', 'waived', 'written_off'].map((value) => ({ value, label: t(`planning.payment.${value}`) }))
                "
                :error="paymentForm.errors.payment_status"
            />
            <TextField v-model="paymentForm.amount" inputmode="decimal" :label="t('planning.payment.amount')" :error="paymentForm.errors.amount" />
            <SelectField
                v-model="paymentForm.method"
                :label="t('planning.payment.method')"
                :placeholder="t('planning.form.choose')"
                :options="['cash', 'card', 'other'].map((value) => ({ value, label: t(`planning.payment.${value}`) }))"
                :error="paymentForm.errors.method"
            />
            <TipFields
                v-model:tip="paymentForm.tip"
                v-model:split="tipSplit"
                v-model:shares="tipSharesModel"
                :practitioners="practitioners"
                :assigned="{ id: booking.practitioner_id, name: booking.practitioner_name, active: assignedActive }"
                :currency="booking.currency"
                :payment-status="paymentForm.payment_status"
                :errors="{ tip: paymentForm.errors.tip, allocations: paymentForm.errors.tip_allocations }"
            />
            <div class="flex flex-col gap-2 sm:flex-row">
                <BaseButton type="submit" :loading="paymentForm.processing">{{ t('planning.actions.save') }}</BaseButton>
                <BaseButton variant="secondary" @click="mode = 'view'">{{ t('common.back') }}</BaseButton>
            </div>
        </form>
    </div>
</template>
