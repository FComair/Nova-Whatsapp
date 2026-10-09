<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import StampRow from '../../Components/StampRow.vue';
import TextField from '../../Components/TextField.vue';
import { formatDate } from '../../composables/dates';
import { rewardValueCents } from '../../composables/loyalty';
import { formatPrice } from '../../composables/money';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';
import type { CustomerPanelData, LoyaltyAbilities, ProgramSummary, RedeemableBooking, RewardRow } from '../../types/loyalty';

/**
 * Utiliser une récompense (personnel) : retrouver le client et vérifier son identité, voir la récompense et ses conditions, choisir le rendez-vous,
 * confirmer. Le prix du rendez-vous et le paiement enregistré ne changent pas : l'écran indique seulement le montant à encaisser.
 */
const props = defineProps<{
    can: LoyaltyAbilities;
    program: ProgramSummary | null;
    q: string;
    results: { customer_id: string; name: string | null; phone: string; rewards: number }[];
    customer: { id: string; name: string | null; phone: string; language: string | null } | null;
    panel: CustomerPanelData | null;
    bookings: RedeemableBooking[];
    done: { reward_id: string; value_cents: number; price_cents: number | null; booking_id: string | null; description: string } | null;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const money = (cents: number): string => formatPrice(cents, locale.value);
const term = ref(props.q);
const rewardId = ref<string | null>(null);
const bookingId = ref<string>('');
const otherOffer = ref(false);
const confirming = ref(false);
const busy = ref(false);
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const usableRewards = computed(() => (props.panel?.rewards ?? []).filter((reward) => reward.status === 'available'));
const reward = computed<RewardRow | null>(() => usableRewards.value.find((row) => row.id === rewardId.value) ?? usableRewards.value[0] ?? null);
const booking = computed(() => props.bookings.find((row) => row.id === bookingId.value) ?? null);

/** Pourquoi un rendez-vous ne peut pas recevoir cette récompense (le serveur refait tous ces contrôles). */
function refusal(row: RedeemableBooking): string | null {
    const current = reward.value;

    if (current === null) {
        return null;
    }

    if (row.has_reward) {
        return t('loyalty.errors.redeem.already_applied');
    }

    if (current.service_ids !== null && !current.service_ids.includes(row.service_id)) {
        return t('loyalty.errors.redeem.service_not_eligible');
    }

    if (current.redeem_timing === 'next_visit' && current.earned_booking_id === row.id) {
        return t('loyalty.errors.redeem.same_visit');
    }

    return null;
}

const value = computed(() => (reward.value && booking.value ? rewardValueCents(reward.value, booking.value.price_cents) : 0));
const due = computed(() => (booking.value ? Math.max(0, booking.value.price_cents - value.value) : 0));
const noBookingAllowed = computed(() => reward.value !== null && !reward.value.requires_appointment);
const ready = computed(
    () =>
        reward.value !== null &&
        (bookingId.value !== '' ? booking.value !== null && refusal(booking.value) === null : noBookingAllowed.value) &&
        !(otherOffer.value && !reward.value.combinable),
);

watch(
    () => props.customer?.id,
    () => {
        rewardId.value = null;
        bookingId.value = '';
        otherOffer.value = false;
    },
);

function search(): void {
    router.get(`${routes.loyalty}/utiliser`, { q: term.value || undefined }, { preserveState: true });
}

function apply(): void {
    if (reward.value === null) {
        return;
    }

    busy.value = true;
    router.post(
        `${routes.loyalty}/recompenses/${reward.value.id}/utiliser`,
        { booking_id: bookingId.value || null, other_offer: otherOffer.value },
        { preserveScroll: true, onFinish: () => ((busy.value = false), (confirming.value = false)) },
    );
}
</script>

<template>
    <AppLayout :title="t('loyalty.redeem.title')">
        <LoyaltyTabs current="redeem" :can="can" />

        <h1 class="text-xl font-semibold text-nova-900">{{ t('loyalty.redeem.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-700">{{ t('loyalty.redeem.intro') }}</p>

        <AlertBox v-if="errors.reward" variant="error" class="mt-4">{{ errors.reward }}</AlertBox>

        <!-- Confirmation d'utilisation -->
        <section v-if="done" class="mt-5 rounded-card border-2 border-success-fg/30 bg-success-bg p-5" role="status" data-testid="redeem-done">
            <h2 class="font-semibold text-success-fg">{{ t('loyalty.redeem.done_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-900">{{ t('loyalty.redeem.done_body', { description: done.description }) }}</p>
            <p class="mt-1 text-sm text-neutral-900">
                {{
                    done.price_cents !== null && done.value_cents > 0
                        ? t('loyalty.redeem.done_value', { amount: money(done.value_cents), due: money(Math.max(0, done.price_cents - done.value_cents)) })
                        : t('loyalty.redeem.done_no_value')
                }}
            </p>
            <p class="mt-1 text-xs text-neutral-800">{{ t('loyalty.redeem.pricing_note') }}</p>
            <div class="mt-3 flex flex-wrap gap-3 text-sm font-semibold">
                <Link :href="`${routes.loyalty}/utiliser`" class="text-primary-700 underline underline-offset-2">{{ t('loyalty.redeem.done_again') }}</Link>
                <Link v-if="customer" :href="`${routes.customers}/${customer.id}`" class="text-primary-700 underline underline-offset-2">
                    {{ t('loyalty.redeem.open_customer') }}
                </Link>
            </div>
        </section>

        <div v-if="program === null" class="mt-5 rounded-card border border-dashed border-line-strong p-6 text-sm text-neutral-800">
            {{ t('loyalty.empty.not_set_up') }}
        </div>

        <template v-else-if="!done">
            <!-- 1. Retrouver le client -->
            <section class="mt-5 rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="find-title">
                <h2 id="find-title" class="font-semibold text-nova-900">{{ t('loyalty.redeem.step_find') }}</h2>
                <form class="mt-3 flex flex-col items-end gap-3 sm:flex-row" role="search" @submit.prevent="search">
                    <div class="w-full sm:flex-1"><TextField v-model="term" :label="t('loyalty.redeem.search')" /></div>
                    <BaseButton type="submit">{{ t('loyalty.redeem.search_action') }}</BaseButton>
                </form>
                <p v-if="q !== '' && results.length === 0" class="mt-3 text-sm text-neutral-700">{{ t('loyalty.empty.no_results') }}</p>
                <ul v-else-if="results.length > 0" class="mt-3 divide-y divide-line">
                    <li v-for="row in results" :key="row.customer_id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0"
                            ><span class="font-medium">{{ row.name ?? t('loyalty.customers.unnamed') }}</span>
                            <span class="text-neutral-700">{{ row.phone }}</span></span
                        >
                        <span class="flex shrink-0 items-center gap-3">
                            <Badge v-if="row.rewards > 0" variant="success">{{ t('loyalty.redeem.rewards_available', { count: row.rewards }) }}</Badge>
                            <Link
                                :href="`${routes.loyalty}/utiliser?customer=${row.customer_id}&q=${encodeURIComponent(q)}`"
                                class="font-semibold text-primary-700 underline underline-offset-2"
                                >{{ t('loyalty.customers.redeem') }}</Link
                            >
                        </span>
                    </li>
                </ul>
            </section>

            <!-- 2 et 3. Récompense, conditions, rendez-vous -->
            <section v-if="customer && panel" class="mt-5 rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="reward-title">
                <h2 id="reward-title" class="font-semibold text-nova-900">{{ t('loyalty.redeem.step_reward') }}</h2>
                <p class="mt-2 rounded-lg bg-surface-2 px-3 py-2 text-sm font-medium text-neutral-900" data-testid="identity">
                    {{ t('loyalty.redeem.identity', { name: customer.name ?? t('loyalty.customers.unnamed'), phone: customer.phone }) }}
                </p>

                <div v-if="panel.member" class="mt-4"><StampRow :stamps="panel.member.stamps" :required="panel.member.required" /></div>

                <p v-if="usableRewards.length === 0" class="mt-4 text-sm text-neutral-800" data-testid="no-reward">{{ t('loyalty.redeem.none_available') }}</p>

                <template v-else>
                    <fieldset class="mt-4 space-y-2">
                        <legend class="text-sm font-semibold text-neutral-900">
                            {{ t('loyalty.redeem.rewards_available', { count: usableRewards.length }) }}
                        </legend>
                        <label
                            v-for="row in usableRewards"
                            :key="row.id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm"
                            :class="reward?.id === row.id ? 'border-primary-600 bg-primary-50' : 'border-line'"
                        >
                            <input v-model="rewardId" type="radio" name="reward" :value="row.id" class="nova-check mt-0.5" :checked="reward?.id === row.id" />
                            <span>
                                <span class="block font-semibold text-neutral-900">{{ row.description }}</span>
                                <span class="block text-xs text-neutral-700"
                                    >{{ t('loyalty.redeem.earned_on', { date: formatDate(row.earned_at) }) }} ·
                                    {{
                                        row.expires_at ? t('loyalty.redeem.expires', { date: formatDate(row.expires_at) }) : t('loyalty.redeem.no_expiry')
                                    }}</span
                                >
                                <span v-if="row.terms" class="mt-1 block text-xs text-neutral-800"
                                    ><span class="font-semibold">{{ t('loyalty.redeem.terms') }} :</span> {{ row.terms }}</span
                                >
                            </span>
                        </label>
                    </fieldset>

                    <fieldset class="mt-5">
                        <legend class="text-sm font-semibold text-neutral-900">{{ t('loyalty.redeem.step_apply') }}</legend>
                        <p class="mb-2 text-xs text-neutral-700">{{ t('loyalty.redeem.choose_booking') }}</p>
                        <p v-if="bookings.length === 0" class="text-sm text-neutral-800">{{ t('loyalty.redeem.no_bookings') }}</p>
                        <div class="space-y-1.5">
                            <label
                                v-for="row in bookings"
                                :key="row.id"
                                class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm"
                                :class="[
                                    bookingId === row.id ? 'border-primary-600 bg-primary-50' : 'border-line',
                                    refusal(row) ? 'opacity-60' : 'cursor-pointer',
                                ]"
                            >
                                <input
                                    v-model="bookingId"
                                    type="radio"
                                    name="booking"
                                    :value="row.id"
                                    class="nova-check mt-0.5"
                                    :disabled="refusal(row) !== null"
                                />
                                <span>
                                    {{
                                        t('loyalty.redeem.booking_line', {
                                            date: formatDate(row.date),
                                            time: row.time,
                                            service: row.service ?? '',
                                            price: money(row.price_cents),
                                        })
                                    }}
                                    <span v-if="refusal(row)" class="block text-xs text-danger-fg">{{ refusal(row) }}</span>
                                </span>
                            </label>
                            <label
                                class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm"
                                :class="[
                                    bookingId === '' ? 'border-primary-600 bg-primary-50' : 'border-line',
                                    noBookingAllowed ? 'cursor-pointer' : 'opacity-60',
                                ]"
                            >
                                <input v-model="bookingId" type="radio" name="booking" value="" class="nova-check mt-0.5" :disabled="!noBookingAllowed" />
                                <span
                                    >{{ t('loyalty.redeem.no_booking_option')
                                    }}<span v-if="!noBookingAllowed" class="block text-xs text-neutral-700">{{
                                        t('loyalty.redeem.no_booking_option_disabled')
                                    }}</span></span
                                >
                            </label>
                        </div>
                    </fieldset>

                    <div class="mt-4">
                        <label class="flex items-start gap-3 text-sm"
                            ><input v-model="otherOffer" type="checkbox" class="nova-check mt-0.5" />{{ t('loyalty.redeem.other_offer') }}</label
                        >
                        <p v-if="otherOffer && reward && !reward.combinable" class="mt-1 pl-8 text-sm font-medium text-danger-fg" role="alert">
                            {{ t('loyalty.redeem.not_combinable') }}
                        </p>
                    </div>

                    <!-- Effet sur le prix : aucun -->
                    <dl
                        v-if="booking && reward && reward.type !== 'custom'"
                        class="mt-5 grid max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1 rounded-xl bg-surface-2 p-4 text-sm"
                        data-testid="amounts"
                    >
                        <dt>{{ t('loyalty.redeem.price') }}</dt>
                        <dd class="text-right tabular-nums">{{ money(booking.price_cents) }}</dd>
                        <dt>{{ t('loyalty.redeem.value') }}</dt>
                        <dd class="text-right tabular-nums">− {{ money(value) }}</dd>
                        <dt class="font-semibold">{{ t('loyalty.redeem.to_collect') }}</dt>
                        <dd class="text-right font-semibold tabular-nums">{{ money(due) }}</dd>
                    </dl>
                    <p class="mt-2 max-w-xl text-xs text-neutral-700">
                        {{ reward && reward.type === 'custom' ? t('loyalty.redeem.pricing_note_custom') : t('loyalty.redeem.pricing_note') }}
                    </p>

                    <BaseButton class="mt-5" :disabled="!ready" @click="confirming = true">{{ t('loyalty.redeem.apply') }}</BaseButton>
                </template>
            </section>
        </template>

        <ConfirmDialog
            :open="confirming"
            :title="t('loyalty.redeem.confirm_title')"
            :body="t('loyalty.redeem.confirm_body', { description: reward?.description ?? '', name: customer?.name ?? customer?.phone ?? '' })"
            :confirm-label="t('loyalty.redeem.confirm')"
            :loading="busy"
            @confirm="apply"
            @cancel="confirming = false"
        />
    </AppLayout>
</template>
