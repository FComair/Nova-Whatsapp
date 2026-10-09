<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { formatDate } from '../composables/dates';
import { programVariant, rewardVariant } from '../composables/loyalty';
import { formatPrice } from '../composables/money';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';
import type { CustomerPanelData, LoyaltyAbilities } from '../types/loyalty';
import ActivityList from './ActivityList.vue';
import AlertBox from './AlertBox.vue';
import Badge from './Badge.vue';
import BaseButton from './BaseButton.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import StampRow from './StampRow.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';

/**
 * Fidélité d'un client, pour le personnel : progression en tampons, récompenses avec leur état, historique, et les actions permises par le rôle
 * (inscrire, ajuster avec motif, faire quitter le programme, annuler l'utilisation d'une récompense avec motif). Sert à la fiche client et à
 * l'écran d'utilisation d'une récompense.
 */
const props = defineProps<{ customerId: string; panel: CustomerPanelData; can: LoyaltyAbilities; configured?: boolean }>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const base = computed(() => `${routes.loyalty}/membres/${props.customerId}`);
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const member = computed(() => props.panel.member);
const program = computed(() => props.panel.program);
const active = computed(() => member.value?.status === 'active');
const earning = computed(() => program.value?.status === 'active');
const usable = (status: string, expires: string | null): boolean => status === 'available' && (expires === null || new Date(expires) > new Date());
const money = (cents: number): string => formatPrice(cents, locale.value);

const adjust = useForm({ delta: '', reason: '' });
const optOut = ref(false);
const busy = ref(false);
const reversing = ref<string | null>(null);
const reverse = useForm({ reason: '' });

function submitAdjust(): void {
    adjust
        .transform((data) => ({ delta: Number(data.delta), reason: data.reason }))
        .post(`${base.value}/ajuster`, { preserveScroll: true, onSuccess: () => adjust.reset() });
}

function post(path: string, data: Record<string, string | number | boolean | null> = {}): void {
    busy.value = true;
    router.post(`${base.value}/${path}`, data, { preserveScroll: true, onFinish: () => ((busy.value = false), (optOut.value = false)) });
}

function submitReverse(rewardId: string): void {
    reverse.post(`${routes.loyalty}/recompenses/${rewardId}/annuler`, { preserveScroll: true, onSuccess: () => ((reversing.value = null), reverse.reset()) });
}
</script>

<template>
    <section class="space-y-5" aria-labelledby="loyalty-panel-title" data-testid="loyalty-panel">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="loyalty-panel-title" class="text-lg font-semibold text-nova-900">{{ t('loyalty.customer.title') }}</h2>
            <Badge v-if="program" :variant="programVariant(program.status)">{{ t(`loyalty.status.${program.status}`) }}</Badge>
        </div>

        <AlertBox v-if="errors.loyalty" variant="error">{{ errors.loyalty }}</AlertBox>
        <AlertBox v-if="errors.reward" variant="error">{{ errors.reward }}</AlertBox>

        <p v-if="program === null" class="text-sm text-neutral-700">{{ t('loyalty.customer.no_program') }}</p>

        <template v-else>
            <p class="text-sm text-neutral-800">{{ t('loyalty.customer.program', { name: program.name }) }}</p>
            <p v-if="!earning" class="text-sm text-warning-fg">
                {{ t('loyalty.customer.program_inactive', { status: t(`loyalty.status.${program.status}`).toLowerCase() }) }}
            </p>

            <!-- Progression -->
            <div v-if="member" class="rounded-xl border border-line p-4">
                <StampRow :stamps="member.stamps" :required="member.required" />
                <p class="mt-2 font-semibold text-neutral-900">{{ t('loyalty.progress.of', { stamps: member.stamps, required: member.required }) }}</p>
                <p class="text-sm text-neutral-800">
                    {{ member.remaining === 0 ? t('loyalty.progress.ready') : t('loyalty.progress.remaining', { count: member.remaining }) }}
                </p>
                <dl class="mt-3 space-y-0.5 text-xs text-neutral-700">
                    <div>
                        {{
                            t('loyalty.customer.joined', {
                                date: formatDate(member.joined_at),
                                via: t(member.joined_via === 'auto' ? 'loyalty.customer.via_auto' : 'loyalty.customer.via_staff'),
                            })
                        }}
                    </div>
                    <div v-if="member.last_visit_at">{{ t('loyalty.customer.last_visit', { date: formatDate(member.last_visit_at) }) }}</div>
                    <div>{{ t('loyalty.customer.lifetime', { count: member.lifetime }) }}</div>
                </dl>
                <p v-if="!active" class="mt-3 rounded-lg bg-warning-bg px-3 py-2 text-sm text-warning-fg">{{ t('loyalty.customer.opted_out') }}</p>
            </div>
            <p v-else class="rounded-xl border border-dashed border-line-strong p-4 text-sm text-neutral-800">{{ t('loyalty.customer.not_enrolled') }}</p>

            <!-- Récompenses -->
            <div>
                <h3 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('loyalty.customer.rewards') }}</h3>
                <p v-if="panel.rewards.length === 0" class="text-sm text-neutral-700">{{ t('loyalty.empty.no_rewards') }}</p>
                <ul v-else class="space-y-3">
                    <li
                        v-for="reward in panel.rewards"
                        :key="reward.id"
                        class="rounded-xl border border-line p-3 text-sm"
                        :data-testid="`reward-${reward.status}`"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-neutral-900">{{ reward.description }}</span>
                            <Badge :variant="rewardVariant(reward.status)">{{ t(`loyalty.customer.reward_status.${reward.status}`) }}</Badge>
                        </div>
                        <p class="mt-1 text-xs text-neutral-700">
                            {{ t('loyalty.customer.earned_at', { date: formatDate(reward.earned_at) }) }} ·
                            {{ reward.expires_at ? t('loyalty.redeem.expires', { date: formatDate(reward.expires_at) }) : t('loyalty.redeem.no_expiry') }}
                            <template v-if="reward.redeemed_at"> · {{ t('loyalty.customer.redeemed_on', { date: formatDate(reward.redeemed_at) }) }}</template>
                            <template v-if="reward.applied_value_cents">
                                · {{ t('loyalty.customer.value_applied', { amount: money(reward.applied_value_cents) }) }}
                            </template>
                        </p>
                        <p v-if="reward.terms" class="mt-1 text-xs text-neutral-700">{{ reward.terms }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <Link
                                v-if="can.redeem && usable(reward.status, reward.expires_at)"
                                :href="`${routes.loyalty}/utiliser?customer=${customerId}`"
                                class="rounded-full bg-primary-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary-700"
                            >
                                {{ t('loyalty.customer.use') }}
                            </Link>
                            <BaseButton
                                v-if="can.adjust && reward.status === 'redeemed'"
                                size="sm"
                                variant="secondary"
                                class="!border-line-strong"
                                @click="reversing = reversing === reward.id ? null : reward.id"
                            >
                                {{ t('loyalty.customer.reverse') }}
                            </BaseButton>
                        </div>
                        <form
                            v-if="reversing === reward.id"
                            class="mt-3 space-y-3 border-t border-line pt-3"
                            novalidate
                            @submit.prevent="submitReverse(reward.id)"
                        >
                            <p class="text-xs text-neutral-700">{{ t('loyalty.customer.reverse_body') }}</p>
                            <TextareaField
                                v-model="reverse.reason"
                                :label="t('loyalty.customer.reason')"
                                :hint="t('loyalty.customer.reason_hint')"
                                :rows="2"
                                :maxlength="500"
                                :error="reverse.errors.reason"
                            />
                            <BaseButton type="submit" size="sm" variant="danger" :loading="reverse.processing">
                                {{ t('loyalty.customer.reverse_confirm') }}
                            </BaseButton>
                        </form>
                    </li>
                </ul>
            </div>

            <!-- Actions du personnel -->
            <div v-if="can.adjust && program.status !== 'draft'" class="space-y-4 rounded-xl bg-surface-2 p-4">
                <div v-if="!member" class="flex flex-wrap items-center gap-3">
                    <BaseButton size="sm" :loading="busy" @click="post('inscrire')">{{ t('loyalty.customer.enroll') }}</BaseButton>
                </div>
                <div v-else-if="!active">
                    <BaseButton size="sm" :loading="busy" @click="post('reprendre')">{{ t('loyalty.customer.rejoin') }}</BaseButton>
                </div>

                <form v-if="member" class="space-y-3" novalidate @submit.prevent="submitAdjust">
                    <h3 class="text-sm font-semibold text-neutral-900">{{ t('loyalty.customer.adjust') }}</h3>
                    <p class="text-xs text-neutral-700">{{ t('loyalty.customer.adjust_hint') }}</p>
                    <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <TextField
                            v-model="adjust.delta"
                            inputmode="numeric"
                            :label="t('loyalty.customer.adjust_delta')"
                            :hint="t('loyalty.customer.adjust_delta_hint')"
                            :error="adjust.errors.delta"
                        />
                        <TextField
                            v-model="adjust.reason"
                            :label="t('loyalty.customer.reason')"
                            :hint="t('loyalty.customer.reason_hint')"
                            :maxlength="500"
                            :error="adjust.errors.reason"
                        />
                    </div>
                    <BaseButton type="submit" size="sm" :loading="adjust.processing" :disabled="adjust.delta.trim() === ''">
                        {{ t('loyalty.customer.adjust_submit') }}
                    </BaseButton>
                </form>

                <div v-if="member && active">
                    <BaseButton size="sm" variant="secondary" class="!border-line-strong" @click="optOut = true">
                        {{ t('loyalty.customer.opt_out') }}
                    </BaseButton>
                </div>
            </div>

            <!-- Historique -->
            <div v-if="panel.history.length > 0">
                <h3 class="mb-1 text-sm font-semibold text-neutral-900">{{ t('loyalty.customer.history') }}</h3>
                <ActivityList :entries="panel.history" />
            </div>

            <div class="space-y-2 border-t border-line pt-3 text-xs text-neutral-700">
                <p v-if="program.terms">
                    <span class="font-semibold">{{ t('loyalty.customer.terms') }} :</span> {{ program.terms }}
                </p>
                <p>{{ t('loyalty.customer.data_use') }}</p>
                <p>{{ t('loyalty.customer.marketing_note') }}</p>
            </div>
        </template>

        <ConfirmDialog
            :open="optOut"
            :title="t('loyalty.customer.opt_out_title')"
            :body="t('loyalty.customer.opt_out_body')"
            :confirm-label="t('loyalty.customer.opt_out_confirm')"
            :loading="busy"
            @confirm="post('quitter')"
            @cancel="optOut = false"
        />
    </section>
</template>
