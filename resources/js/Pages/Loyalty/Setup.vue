<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import LoyaltyCard, { type CardTexts } from '../../Components/LoyaltyCard.vue';
import LoyaltyTabs from '../../Components/LoyaltyTabs.vue';
import RuleSummary from '../../Components/RuleSummary.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import TextareaField from '../../Components/TextareaField.vue';
import { eurosToCents, pickText, programVariant } from '../../composables/loyalty';
import { centsToInput } from '../../composables/money';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';
import type { ImpactData, ProgramStatus, ProgramValues, RewardType } from '../../types/loyalty';

/**
 * Configuration guidée du programme (propriétaire) : cinq étapes courtes avec des valeurs par défaut, un résumé de la règle toujours visible,
 * puis l'aperçu côté client et l'activation. Un programme invalide ne s'active pas ; un changement qui touche des clients qui ont déjà
 * progressé est d'abord présenté, jamais appliqué en silence.
 */
const props = defineProps<{
    program: { status: ProgramStatus; activated_at: string | null } | null;
    values: ProgramValues;
    problems: string[];
    incomplete_languages: string[];
    has_progress: boolean;
    impact: ImpactData | null;
    services: { id: string; name: string; price_cents: number }[];
    locales: string[];
    preview_i18n: Record<string, CardTexts>;
}>();

const { t, locale } = useT();
const page = usePage<SharedProps>();
const can = { view: true, configure: true, redeem: true, adjust: true };

const v = props.values;
const form = useForm({
    name: { ...v.name },
    reward_description: { ...v.reward_description },
    terms: { ...v.terms },
    required_visits: String(v.required_visits),
    services_mode: v.services_mode,
    earn_services: [...v.earn_services],
    min_amount: v.min_amount_cents === null ? '' : centsToInput(v.min_amount_cents, locale.value),
    require_payment: v.require_payment,
    auto_enroll: v.auto_enroll,
    reward_type: v.reward_type as RewardType,
    reward_percent: v.reward_type === 'percent_discount' && v.reward_value !== null ? String(v.reward_value) : '',
    reward_amount: v.reward_type === 'fixed_amount' && v.reward_value !== null ? centsToInput(v.reward_value, locale.value) : '',
    reward_max: v.reward_max_cents === null ? '' : centsToInput(v.reward_max_cents, locale.value),
    reward_services_mode: v.reward_services_mode,
    reward_services: [...v.reward_services],
    redeem_timing: v.redeem_timing,
    combinable: v.combinable,
    requires_appointment: v.requires_appointment,
    expiry_mode: v.expiry_days === null ? 'none' : 'days',
    expiry_days: v.expiry_days === null ? '30' : String(v.expiry_days),
    expiry_reminder_days: String(v.expiry_reminder_days),
    confirmed: false,
});

const STEPS = ['program', 'earn', 'reward', 'redeem', 'review'] as const;
const step = ref(0);
const language = ref(props.locales.includes(locale.value) ? locale.value : (props.locales[0] ?? 'fr'));
const previewLanguage = ref(language.value);
const dialog = ref<'activate' | 'pause' | 'archive' | null>(null);
const busy = ref(false);

const status = computed(() => props.program?.status ?? null);
const live = computed(() => status.value !== null && status.value !== 'archived');
const serviceName = (id: string): string => props.services.find((service) => service.id === id)?.name ?? '';

const rewardValue = computed<number | null>(() => {
    if (form.reward_type === 'percent_discount') {
        return form.reward_percent === '' ? null : Number(form.reward_percent);
    }

    return form.reward_type === 'fixed_amount' ? eurosToCents(form.reward_amount) : null;
});
const rule = computed(() => ({
    required_visits: Number(form.required_visits) || 0,
    services_mode: form.services_mode,
    earn_services: form.earn_services.map(serviceName).filter(Boolean),
    min_amount_cents: form.min_amount.trim() === '' ? null : eurosToCents(form.min_amount),
    require_payment: form.require_payment,
    auto_enroll: form.auto_enroll,
    reward_type: form.reward_type,
    reward_value: rewardValue.value,
    reward_max_cents: form.reward_max.trim() === '' ? null : eurosToCents(form.reward_max),
    reward_services_mode: form.reward_services_mode,
    reward_services: form.reward_services.map(serviceName).filter(Boolean),
    reward_description: pickText(form.reward_description, locale.value),
    redeem_timing: form.redeem_timing,
    combinable: form.combinable,
    requires_appointment: form.requires_appointment,
    expiry_days: form.expiry_mode === 'days' && form.expiry_days !== '' ? Number(form.expiry_days) : null,
}));

/** Ce qui manque, calculé en direct (le serveur refait ce contrôle à l'activation). */
const localProblems = computed(() => {
    const list: string[] = [];

    for (const field of ['name', 'reward_description', 'terms'] as const) {
        if (pickText(form[field], 'fr') === '') {
            list.push(field);
        }
    }

    if (!(rule.value.required_visits >= 1 && rule.value.required_visits <= 100)) {
        list.push('required_visits');
    }

    if (form.reward_type === 'percent_discount' && !(rewardValue.value !== null && rewardValue.value >= 1 && rewardValue.value <= 100)) {
        list.push('reward_value');
    } else if (form.reward_type === 'fixed_amount' && !(rewardValue.value !== null && rewardValue.value >= 1)) {
        list.push('reward_value');
    }

    if (form.services_mode === 'selected' && form.earn_services.length === 0) {
        list.push('earn_services');
    }

    if (form.reward_services_mode === 'selected' && form.reward_services.length === 0) {
        list.push('reward_services');
    }

    return list;
});
const missingLanguages = computed(() =>
    props.locales
        .filter((code) => ['name', 'reward_description', 'terms'].some((field) => (form[field as 'name'][code] ?? '').trim() === ''))
        .map((code) => t(`common.languages.${code}`)),
);
const canActivate = computed(() => ['draft', 'paused'].includes(status.value ?? '') && !form.isDirty && props.problems.length === 0);

function toggle(list: string[], id: string): void {
    const at = list.indexOf(id);

    if (at === -1) {
        list.push(id);
    } else {
        list.splice(at, 1);
    }
}

function payload() {
    return {
        name: form.name,
        reward_description: form.reward_description,
        terms: form.terms,
        required_visits: form.required_visits,
        services_mode: form.services_mode,
        earn_services: form.services_mode === 'selected' ? form.earn_services : [],
        min_amount: form.min_amount,
        require_payment: form.require_payment,
        auto_enroll: form.auto_enroll,
        reward_type: form.reward_type,
        reward_percent: form.reward_type === 'percent_discount' ? form.reward_percent : '',
        reward_amount: form.reward_type === 'fixed_amount' ? form.reward_amount : '',
        reward_max: form.reward_type === 'custom' ? '' : form.reward_max,
        reward_services_mode: form.reward_services_mode,
        reward_services: form.reward_services_mode === 'selected' ? form.reward_services : [],
        redeem_timing: form.redeem_timing,
        combinable: form.combinable,
        requires_appointment: form.requires_appointment,
        expiry_days: form.expiry_mode === 'days' ? form.expiry_days : '',
        expiry_reminder_days: form.expiry_reminder_days,
        confirmed: form.confirmed,
    };
}

function save(confirmed = false): void {
    form.confirmed = confirmed;
    form.transform(() => payload()).put(`${routes.loyalty}/programme`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (visit) => {
            if (!(visit.props as { impact?: unknown }).impact) {
                form.defaults();
            }
        },
        onFinish: () => (form.confirmed = false),
    });
}

function act(path: 'activer' | 'pause' | 'archiver'): void {
    busy.value = true;
    router.post(`${routes.loyalty}/programme/${path}`, {}, { preserveScroll: true, onFinish: () => ((busy.value = false), (dialog.value = null)) });
}

function cancelImpact(): void {
    router.get(`${routes.loyalty}/programme`, {}, { preserveScroll: true });
}

const errors = computed(() => form.errors as Record<string, string | undefined>);
const programError = computed(() => (page.props.errors as Record<string, string | undefined>).program);
const cardTexts = computed<CardTexts>(() => props.preview_i18n[previewLanguage.value] ?? (Object.values(props.preview_i18n)[0] as CardTexts));
const previewProgram = computed(() => ({
    name: pickText(form.name, previewLanguage.value),
    reward: pickText(form.reward_description, previewLanguage.value),
    terms: pickText(form.terms, previewLanguage.value),
    required: Math.max(1, rule.value.required_visits || 1),
}));
const previewStamps = computed(() => Math.min(6, Math.max(0, previewProgram.value.required - 2)));
</script>

<template>
    <AppLayout :title="t('loyalty.setup.title')">
        <LoyaltyTabs current="program" :can="can" />

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold text-rendivoo-900">{{ t('loyalty.setup.title') }}</h1>
                <p class="mt-1 max-w-2xl text-sm text-neutral-700">{{ t('loyalty.setup.intro') }}</p>
            </div>
            <Badge v-if="status" :variant="programVariant(status)">{{ t(`loyalty.status.${status}`) }}</Badge>
        </div>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="programError" variant="error" class="mt-4">{{ programError }}</AlertBox>
        <AlertBox v-if="status === null && program === null" variant="info" class="mt-4">
            {{ t('loyalty.setup.examples_title') }} : {{ t('loyalty.setup.examples') }}
        </AlertBox>
        <AlertBox v-if="!live && program !== null" variant="info" class="mt-4">{{ t('loyalty.setup.archived_state') }}</AlertBox>

        <!-- Effet d'une modification matérielle sur les clients qui ont déjà progressé : présenté avant d'être appliqué. -->
        <section v-if="impact" class="mt-4 rounded-card border-2 border-warning-fg/40 bg-warning-bg p-5" aria-labelledby="impact-title" data-testid="impact">
            <h2 id="impact-title" class="font-semibold text-warning-fg">{{ t('loyalty.setup.impact.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-900">{{ t('loyalty.setup.impact.intro') }}</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-neutral-900">
                <li>
                    {{ t('loyalty.setup.impact.members', { count: impact.members }) }},
                    {{ t('loyalty.setup.impact.in_progress', { count: impact.in_progress }) }}
                </li>
                <li v-if="impact.required_to < impact.required_from">
                    {{
                        impact.rewards_now > 0
                            ? t('loyalty.setup.impact.lowered', { from: impact.required_from, to: impact.required_to, count: impact.rewards_now })
                            : t('loyalty.setup.impact.lowered_none', { from: impact.required_from, to: impact.required_to })
                    }}
                </li>
                <li v-if="impact.required_to > impact.required_from">
                    {{ t('loyalty.setup.impact.raised', { from: impact.required_from, to: impact.required_to, count: impact.further_away }) }}
                </li>
                <li v-if="impact.available_rewards > 0">{{ t('loyalty.setup.impact.rewards_kept', { count: impact.available_rewards }) }}</li>
                <li v-if="impact.reward_changed">{{ t('loyalty.setup.impact.reward_changed') }}</li>
                <li v-if="impact.earn_rule_changed">{{ t('loyalty.setup.impact.earn_changed') }}</li>
            </ul>
            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                <BaseButton :loading="form.processing" @click="save(true)">{{ t('loyalty.setup.impact.confirm') }}</BaseButton>
                <BaseButton variant="secondary" class="!border-line-strong" @click="cancelImpact">{{ t('loyalty.setup.impact.cancel') }}</BaseButton>
            </div>
        </section>

        <div class="mt-6 grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div>
                <ol class="mb-5 flex flex-wrap gap-2" :aria-label="t('loyalty.setup.step_of', { step: step + 1, total: STEPS.length })">
                    <li v-for="(key, index) in STEPS" :key="key">
                        <button
                            type="button"
                            class="inline-flex min-h-9 items-center gap-2 rounded-full px-3 text-sm font-semibold transition focus-visible:outline focus-visible:outline-2"
                            :class="index === step ? 'bg-primary-600 text-white' : 'bg-surface-2 text-neutral-900 hover:bg-primary-50'"
                            :aria-current="index === step ? 'step' : undefined"
                            @click="step = index"
                        >
                            <span class="grid size-5 place-items-center rounded-full bg-white/25 text-xs">{{ index + 1 }}</span>
                            {{ t(`loyalty.setup.steps.${key}`) }}
                        </button>
                    </li>
                </ol>

                <form class="space-y-5 rounded-card border border-line bg-surface p-5 shadow-xs" novalidate @submit.prevent="save(false)">
                    <!-- Étape 1 : nom, langues -->
                    <section v-show="step === 0" class="space-y-4" aria-labelledby="step-program">
                        <h2 id="step-program" class="font-semibold text-rendivoo-900">{{ t('loyalty.setup.steps.program') }}</h2>
                        <div role="group" :aria-label="t('loyalty.setup.language')" class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.language') }}</span>
                            <button
                                v-for="code in locales"
                                :key="code"
                                type="button"
                                class="min-h-9 rounded-full border-[1.5px] px-3 text-sm font-semibold"
                                :class="language === code ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-line-strong text-neutral-900'"
                                :aria-pressed="language === code"
                                @click="language = code"
                            >
                                {{ t(`common.languages.${code}`) }}
                            </button>
                        </div>
                        <p class="text-sm text-neutral-700">{{ t('loyalty.setup.language_hint') }}</p>
                        <template v-for="code in locales" :key="code">
                            <div v-show="language === code">
                                <TextField
                                    v-model="form.name[code]"
                                    :label="`${t('loyalty.setup.name')} (${code.toUpperCase()})`"
                                    :hint="t('loyalty.setup.name_hint')"
                                    :maxlength="80"
                                    :error="errors[`name.${code}`]"
                                />
                            </div>
                        </template>
                        <p v-if="missingLanguages.length > 0 && missingLanguages.length < locales.length" class="text-sm text-warning-fg">
                            {{ t('loyalty.setup.language_missing', { languages: missingLanguages.join(', ') }) }}
                        </p>
                    </section>

                    <!-- Étape 2 : gagner des tampons -->
                    <section v-show="step === 1" class="space-y-4" aria-labelledby="step-earn">
                        <h2 id="step-earn" class="font-semibold text-rendivoo-900">{{ t('loyalty.setup.steps.earn') }}</h2>
                        <TextField
                            v-model="form.required_visits"
                            inputmode="numeric"
                            :label="t('loyalty.setup.required_visits')"
                            :hint="t('loyalty.setup.required_visits_hint')"
                            :error="errors.required_visits"
                        />
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.services_mode') }}</legend>
                            <div class="space-y-1.5 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.services_mode" type="radio" value="all" class="rendivoo-check" />{{
                                        t('loyalty.setup.services_all')
                                    }}</label
                                >
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.services_mode" type="radio" value="selected" class="rendivoo-check" />{{
                                        t('loyalty.setup.services_selected')
                                    }}</label
                                >
                            </div>
                        </fieldset>
                        <fieldset v-if="form.services_mode === 'selected'" class="space-y-1.5">
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.services_choose') }}</legend>
                            <label v-for="service in services" :key="service.id" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="rendivoo-check"
                                    :checked="form.earn_services.includes(service.id)"
                                    @change="toggle(form.earn_services, service.id)"
                                />{{ service.name }}
                            </label>
                            <p v-if="errors.earn_services" class="text-sm font-medium text-danger-fg" role="alert">{{ errors.earn_services }}</p>
                        </fieldset>
                        <TextField
                            v-model="form.min_amount"
                            inputmode="decimal"
                            :label="t('loyalty.setup.min_amount')"
                            :hint="t('loyalty.setup.min_amount_hint')"
                            :error="errors.min_amount"
                        />
                        <CheckboxField v-model="form.require_payment" :label="t('loyalty.setup.require_payment')" />
                        <p class="-mt-2 pl-8 text-sm text-neutral-700">{{ t('loyalty.setup.require_payment_hint') }}</p>
                        <p class="rounded-lg bg-surface-2 px-3 py-2 text-sm text-neutral-800">{{ t('loyalty.setup.always') }}</p>
                        <CheckboxField v-model="form.auto_enroll" :label="t('loyalty.setup.auto_enroll')" />
                        <p class="-mt-2 pl-8 text-sm text-neutral-700">{{ t('loyalty.setup.auto_enroll_hint') }}</p>
                    </section>

                    <!-- Étape 3 : récompense -->
                    <section v-show="step === 2" class="space-y-4" aria-labelledby="step-reward">
                        <h2 id="step-reward" class="font-semibold text-rendivoo-900">{{ t('loyalty.setup.steps.reward') }}</h2>
                        <SelectField
                            v-model="form.reward_type"
                            :label="t('loyalty.setup.reward_type')"
                            :options="
                                (['free_service', 'percent_discount', 'fixed_amount', 'custom'] as const).map((value) => ({
                                    value,
                                    label: t(`loyalty.setup.reward_types.${value}`),
                                }))
                            "
                        />
                        <p class="-mt-2 text-sm text-neutral-700">{{ t(`loyalty.setup.reward_types_hint.${form.reward_type}`) }}</p>
                        <TextField
                            v-if="form.reward_type === 'percent_discount'"
                            v-model="form.reward_percent"
                            inputmode="numeric"
                            :label="t('loyalty.setup.reward_percent')"
                            :error="errors.reward_percent"
                        />
                        <TextField
                            v-if="form.reward_type === 'fixed_amount'"
                            v-model="form.reward_amount"
                            inputmode="decimal"
                            :label="t('loyalty.setup.reward_amount')"
                            :error="errors.reward_amount"
                        />
                        <TextField
                            v-if="form.reward_type !== 'custom'"
                            v-model="form.reward_max"
                            inputmode="decimal"
                            :label="t('loyalty.setup.reward_max')"
                            :hint="t('loyalty.setup.reward_max_hint')"
                            :error="errors.reward_max"
                        />
                        <div role="group" :aria-label="t('loyalty.setup.language')" class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.language') }}</span>
                            <button
                                v-for="code in locales"
                                :key="code"
                                type="button"
                                class="min-h-9 rounded-full border-[1.5px] px-3 text-sm font-semibold"
                                :class="language === code ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-line-strong text-neutral-900'"
                                :aria-pressed="language === code"
                                @click="language = code"
                            >
                                {{ t(`common.languages.${code}`) }}
                            </button>
                        </div>
                        <template v-for="code in locales" :key="code">
                            <div v-show="language === code">
                                <TextField
                                    v-model="form.reward_description[code]"
                                    :label="`${t('loyalty.setup.reward_description')} (${code.toUpperCase()})`"
                                    :hint="t('loyalty.setup.reward_description_hint')"
                                    :maxlength="200"
                                    :error="errors[`reward_description.${code}`]"
                                />
                            </div>
                        </template>
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.reward_services_mode') }}</legend>
                            <div class="space-y-1.5 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.reward_services_mode" type="radio" value="all" class="rendivoo-check" />{{
                                        t('loyalty.setup.reward_services_all')
                                    }}</label
                                >
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.reward_services_mode" type="radio" value="selected" class="rendivoo-check" />{{
                                        t('loyalty.setup.reward_services_selected')
                                    }}</label
                                >
                            </div>
                        </fieldset>
                        <fieldset v-if="form.reward_services_mode === 'selected'" class="space-y-1.5">
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.reward_services_choose') }}</legend>
                            <label v-for="service in services" :key="service.id" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="rendivoo-check"
                                    :checked="form.reward_services.includes(service.id)"
                                    @change="toggle(form.reward_services, service.id)"
                                />{{ service.name }}
                            </label>
                        </fieldset>
                    </section>

                    <!-- Étape 4 : utilisation, expiration, conditions -->
                    <section v-show="step === 3" class="space-y-4" aria-labelledby="step-redeem">
                        <h2 id="step-redeem" class="font-semibold text-rendivoo-900">{{ t('loyalty.setup.steps.redeem') }}</h2>
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.redeem_timing') }}</legend>
                            <div class="space-y-1.5 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.redeem_timing" type="radio" value="next_visit" class="rendivoo-check" />{{
                                        t('loyalty.setup.timing_next')
                                    }}</label
                                >
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.redeem_timing" type="radio" value="any_time" class="rendivoo-check" />{{
                                        t('loyalty.setup.timing_any')
                                    }}</label
                                >
                            </div>
                        </fieldset>
                        <CheckboxField v-model="form.combinable" :label="t('loyalty.setup.combinable')" />
                        <CheckboxField v-model="form.requires_appointment" :label="t('loyalty.setup.requires_appointment')" />
                        <p class="-mt-2 pl-8 text-sm text-neutral-700">{{ t('loyalty.setup.requires_appointment_hint') }}</p>
                        <fieldset>
                            <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.expiry') }}</legend>
                            <div class="space-y-1.5 text-sm">
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.expiry_mode" type="radio" value="none" class="rendivoo-check" />{{
                                        t('loyalty.setup.expiry_none')
                                    }}</label
                                >
                                <label class="flex items-center gap-2"
                                    ><input v-model="form.expiry_mode" type="radio" value="days" class="rendivoo-check" />{{
                                        t('loyalty.setup.expiry_days')
                                    }}</label
                                >
                            </div>
                        </fieldset>
                        <template v-if="form.expiry_mode === 'days'">
                            <TextField
                                v-model="form.expiry_days"
                                inputmode="numeric"
                                :label="t('loyalty.setup.expiry_days')"
                                :hint="t('loyalty.setup.expiry_days_hint')"
                                :error="errors.expiry_days"
                            />
                            <TextField
                                v-model="form.expiry_reminder_days"
                                inputmode="numeric"
                                :label="t('loyalty.setup.expiry_reminder')"
                                :hint="t('loyalty.setup.expiry_reminder_hint')"
                                :error="errors.expiry_reminder_days"
                            />
                        </template>
                        <div role="group" :aria-label="t('loyalty.setup.language')" class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.language') }}</span>
                            <button
                                v-for="code in locales"
                                :key="code"
                                type="button"
                                class="min-h-9 rounded-full border-[1.5px] px-3 text-sm font-semibold"
                                :class="language === code ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-line-strong text-neutral-900'"
                                :aria-pressed="language === code"
                                @click="language = code"
                            >
                                {{ t(`common.languages.${code}`) }}
                            </button>
                        </div>
                        <template v-for="code in locales" :key="code">
                            <div v-show="language === code">
                                <TextareaField
                                    v-model="form.terms[code]"
                                    :label="`${t('loyalty.setup.terms')} (${code.toUpperCase()})`"
                                    :hint="t('loyalty.setup.terms_hint')"
                                    :rows="4"
                                    :maxlength="2000"
                                    :error="errors[`terms.${code}`]"
                                />
                            </div>
                        </template>
                    </section>

                    <!-- Étape 5 : aperçu, vérification, activation -->
                    <section v-show="step === 4" class="space-y-5" aria-labelledby="step-review">
                        <h2 id="step-review" class="font-semibold text-rendivoo-900">{{ t('loyalty.setup.steps.review') }}</h2>

                        <div>
                            <h3 class="text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.checklist_title') }}</h3>
                            <ul
                                v-if="localProblems.length > 0 || problems.length > 0"
                                class="mt-2 list-disc space-y-1 pl-5 text-sm text-danger-fg"
                                data-testid="problems"
                            >
                                <li v-for="problem in [...new Set([...localProblems, ...problems])]" :key="problem">
                                    {{ t(`loyalty.setup.problems.${problem}`) }}
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-success-fg">{{ t('loyalty.setup.checklist_ok') }}</p>
                            <p v-if="missingLanguages.length > 0 && missingLanguages.length < locales.length" class="mt-2 text-sm text-warning-fg">
                                {{ t('loyalty.setup.language_missing', { languages: missingLanguages.join(', ') }) }}
                            </p>
                        </div>

                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold text-neutral-900">{{ t('loyalty.setup.preview_title') }}</h3>
                                <div class="w-44">
                                    <SelectField
                                        v-model="previewLanguage"
                                        :label="t('loyalty.setup.preview_language')"
                                        :options="locales.map((code) => ({ value: code, label: t(`common.languages.${code}`) }))"
                                    />
                                </div>
                            </div>
                            <p class="mb-3 text-sm text-neutral-700">{{ t('loyalty.setup.preview_hint') }}</p>
                            <LoyaltyCard :language="previewLanguage" :texts="cardTexts" :program="previewProgram" :stamps="previewStamps" />
                        </div>
                    </section>

                    <div class="flex flex-col-reverse gap-2 border-t border-line pt-4 sm:flex-row sm:justify-between">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <BaseButton v-if="step > 0" variant="secondary" class="!border-line-strong" @click="step -= 1">
                                {{ t('loyalty.setup.previous') }}
                            </BaseButton>
                            <BaseButton v-if="step < STEPS.length - 1" variant="secondary" class="!border-line-strong" @click="step += 1">
                                {{ t('loyalty.setup.next') }}
                            </BaseButton>
                        </div>
                        <BaseButton type="submit" :loading="form.processing">{{ live ? t('loyalty.setup.save_changes') : t('loyalty.setup.save') }}</BaseButton>
                    </div>
                </form>

                <!-- Cycle de vie -->
                <section v-if="live" class="mt-5 rounded-card border border-line bg-surface p-5 shadow-xs" aria-labelledby="lifecycle">
                    <h2 id="lifecycle" class="sr-only">{{ t('loyalty.status.' + (status ?? 'draft')) }}</h2>
                    <p class="text-sm text-neutral-800">{{ t(`loyalty.status_help.${status ?? 'draft'}`) }}</p>
                    <p v-if="form.isDirty && ['draft', 'paused'].includes(status ?? '')" class="mt-2 text-sm text-warning-fg">{{ t('loyalty.setup.save') }}</p>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <BaseButton v-if="status === 'draft' || status === 'paused'" :disabled="!canActivate" @click="dialog = 'activate'">
                            {{ status === 'paused' ? t('loyalty.setup.resume') : t('loyalty.setup.activate') }}
                        </BaseButton>
                        <BaseButton v-if="status === 'active'" variant="secondary" class="!border-line-strong" @click="dialog = 'pause'">
                            {{ t('loyalty.setup.pause') }}
                        </BaseButton>
                        <BaseButton variant="danger" @click="dialog = 'archive'">{{ t('loyalty.setup.archive') }}</BaseButton>
                    </div>
                </section>
            </div>

            <aside class="lg:sticky lg:top-4 lg:self-start" :aria-label="t('loyalty.setup.summary_title')">
                <div class="rounded-card border border-line bg-surface p-5 shadow-xs">
                    <h2 class="mb-3 font-semibold text-rendivoo-900">{{ t('loyalty.setup.summary_title') }}</h2>
                    <RuleSummary :rule="rule" />
                </div>
            </aside>
        </div>

        <ConfirmDialog
            :open="dialog === 'activate'"
            :title="t('loyalty.setup.activate_title')"
            :body="t('loyalty.setup.activate_body')"
            :confirm-label="t('loyalty.setup.activate_confirm')"
            :loading="busy"
            @confirm="act('activer')"
            @cancel="dialog = null"
        />
        <ConfirmDialog
            :open="dialog === 'pause'"
            :title="t('loyalty.setup.pause_title')"
            :body="t('loyalty.setup.pause_body')"
            :confirm-label="t('loyalty.setup.pause_confirm')"
            :loading="busy"
            @confirm="act('pause')"
            @cancel="dialog = null"
        />
        <ConfirmDialog
            :open="dialog === 'archive'"
            :title="t('loyalty.setup.archive_title')"
            :body="t('loyalty.setup.archive_body')"
            :confirm-label="t('loyalty.setup.archive_confirm')"
            :loading="busy"
            @confirm="act('archiver')"
            @cancel="dialog = null"
        />
    </AppLayout>
</template>
