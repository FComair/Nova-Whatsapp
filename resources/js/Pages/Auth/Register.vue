<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import PlanPicker from '../../Components/PlanPicker.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { type Offers, summaryLines } from '../../composables/signupPlans';
import { useT } from '../../composables/useT';
import AuthLayout from '../../Layouts/AuthLayout.vue';
import { routes } from '../../routes';

/**
 * Inscription en deux étapes : 1. coordonnées, 2. formule (essai gratuit ou formule payante, issues de la configuration), récapitulatif
 * de facturation, acceptations. Rien n'est créé avant la validation finale ; revenir à l'étape 1 conserve tout ce qui a été saisi
 * (sauf le mot de passe après un envoi). L'inscription n'enregistre aucun moyen de paiement et ne crée jamais d'abonnement.
 */
interface LegalDoc {
    code: 'terms' | 'privacy' | 'dpa';
    slug: string;
    title: string;
    version: string | null;
    href: string;
}

const props = defineProps<{
    sectors: { value: string; label: string }[];
    plan: string;
    offers: Offers;
    legal: { documents: LegalDoc[]; dpa_required: boolean };
}>();
const { t, locale } = useT();

const form = useForm({
    email: '',
    password: '',
    phone: '',
    sector: '',
    plan: props.plan,
    accept_terms: false,
    accept_privacy: false,
    accept_dpa: false,
    // Versions vues par la personne : le serveur refuse l'envoi si un document a changé entre-temps (docs/28).
    legal_versions: Object.fromEntries(props.legal.documents.map((doc) => [doc.code, doc.version ?? ''])) as Record<string, string>,
});

const step = ref<1 | 2>(1);
const heading = ref<HTMLElement | null>(null);
const errors = computed(() => form.errors as Record<string, string | undefined>);
const sectorOptions = computed(() => props.sectors);
const docs = computed(() => Object.fromEntries(props.legal.documents.map((doc) => [doc.code, doc])) as Record<'terms' | 'privacy' | 'dpa', LegalDoc>);
const legalReady = computed(() => props.legal.documents.every((doc) => doc.version !== null));

/** « J'accepte les [Conditions d'utilisation]. » : le texte du modèle est coupé autour de :link, qui devient le lien du document. */
function parts(key: 'terms' | 'privacy' | 'dpa'): [string, string] {
    const [before = '', after = ''] = t(`legal.signup.${key}`).split(':link');

    return [before, after];
}
const summary = computed(() => summaryLines(props.offers, form.plan, t, locale.value));
const DETAIL_FIELDS = ['email', 'password', 'phone', 'sector'] as const;

async function go(next: 1 | 2): Promise<void> {
    step.value = next;
    await nextTick();
    heading.value?.focus();
}

/** Étape 1 → 2 : champs obligatoires remplis ; la validation complète (e-mail, mot de passe, téléphone) se fait à l'envoi. */
function next(): void {
    form.clearErrors();
    let missing = false;

    for (const field of DETAIL_FIELDS) {
        if (form[field].trim() === '') {
            form.setError(field, t('register.field_required'));
            missing = true;
        }
    }

    if (!missing) {
        void go(2);
    }
}

function submit(): void {
    form.post(routes.register, { onFinish: () => form.reset('password') });
}

// Un document juridique publié pendant l'inscription : on affiche la nouvelle version et on redemande les acceptations, jamais l'ancienne en silence.
watch(
    () => props.legal.documents.map((doc) => doc.version).join('|'),
    () => {
        form.legal_versions = Object.fromEntries(props.legal.documents.map((doc) => [doc.code, doc.version ?? '']));
        form.accept_terms = false;
        form.accept_privacy = false;
        form.accept_dpa = false;
    },
);

// Une erreur sur une coordonnée ramène à l'étape 1 (le mot de passe est à ressaisir) ; une erreur de formule reste à l'étape 2.
watch(
    () => form.errors,
    (current) => {
        if (DETAIL_FIELDS.some((field) => (current as Record<string, string | undefined>)[field] !== undefined)) {
            step.value = 1;
        }
    },
);
</script>

<template>
    <AuthLayout :title="t('register.title')" :subtitle="t('register.subtitle')" :wide="step === 2">
        <ol class="mb-5 flex gap-2 text-sm" :aria-label="t('register.step_of', { step, total: 2 })">
            <li
                class="rounded-full px-3 py-1 font-semibold"
                :class="step === 1 ? 'bg-primary-600 text-white' : 'bg-primary-50 text-primary-700'"
                :aria-current="step === 1 ? 'step' : undefined"
            >
                1. {{ t('register.steps_details') }}
            </li>
            <li
                class="rounded-full px-3 py-1 font-semibold"
                :class="step === 2 ? 'bg-primary-600 text-white' : 'bg-surface-2 text-neutral-800'"
                :aria-current="step === 2 ? 'step' : undefined"
            >
                2. {{ t('register.steps_plan') }}
            </li>
        </ol>

        <form class="space-y-5" novalidate @submit.prevent="submit">
            <!-- Étape 1 : coordonnées (conservées si l'on revient ici depuis l'étape 2) -->
            <div v-show="step === 1" class="mx-auto max-w-md space-y-5">
                <TextField
                    v-model="form.email"
                    type="email"
                    :label="t('register.email')"
                    autocomplete="email"
                    inputmode="email"
                    required
                    :error="form.errors.email"
                />
                <TextField
                    v-model="form.password"
                    type="password"
                    :label="t('register.password')"
                    :hint="t('register.password_hint')"
                    autocomplete="new-password"
                    required
                    :error="form.errors.password"
                />
                <TextField
                    v-model="form.phone"
                    type="tel"
                    :label="t('register.phone')"
                    :hint="t('register.phone_hint')"
                    autocomplete="tel"
                    inputmode="tel"
                    required
                    :error="form.errors.phone"
                />
                <SelectField
                    v-model="form.sector"
                    :label="t('register.sector')"
                    :placeholder="t('register.sector_placeholder')"
                    :options="sectorOptions"
                    required
                    :error="form.errors.sector"
                />
                <BaseButton class="w-full" @click="next">{{ t('register.continue') }}</BaseButton>
            </div>

            <!-- Étape 2 : formule, récapitulatif de facturation, acceptations -->
            <div v-show="step === 2" class="space-y-5">
                <div>
                    <h2 ref="heading" tabindex="-1" class="text-lg font-semibold text-nova-900 focus:outline-none">{{ t('register.plan_title') }}</h2>
                    <p class="mt-1 text-sm text-neutral-700">{{ t('register.plan_intro') }}</p>
                </div>

                <AlertBox v-if="offers.plans.length === 0" variant="info">{{ t('register.plans_unavailable') }}</AlertBox>

                <PlanPicker v-model="form.plan" :offers="offers" :error="errors.plan" />

                <section class="rounded-card border border-line bg-surface p-4" aria-labelledby="signup-summary" data-testid="summary">
                    <h3 id="signup-summary" class="font-semibold text-nova-900">{{ t('register.summary_title') }}</h3>
                    <dl class="mt-3 grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[8rem_1fr]">
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_plan') }}</dt>
                        <dd class="font-semibold" data-testid="summary-plan">{{ summary.plan }}</dd>
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_price') }}</dt>
                        <dd>{{ summary.price }}</dd>
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_trial') }}</dt>
                        <dd>{{ summary.trial }}</dd>
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_payment') }}</dt>
                        <dd>{{ summary.payment }}</dd>
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_after') }}</dt>
                        <dd>{{ summary.after }}</dd>
                        <dt class="font-medium text-neutral-700">{{ t('register.summary_vat') }}</dt>
                        <dd>{{ summary.vat }}</dd>
                    </dl>
                </section>

                <fieldset class="space-y-3 rounded-card border border-line bg-surface p-4" data-testid="legal-acceptance">
                    <legend class="px-1 text-sm font-semibold text-nova-900">{{ t('legal.signup.title') }}</legend>
                    <AlertBox v-if="!legalReady" variant="error">{{ t('legal.signup.unavailable') }}</AlertBox>
                    <AlertBox v-if="errors.legal" variant="error" data-testid="legal-changed">{{ errors.legal }}</AlertBox>
                    <CheckboxField
                        v-for="code in ['terms', 'privacy', 'dpa'] as const"
                        :key="code"
                        v-model="form[`accept_${code}`]"
                        :label="docs[code].title"
                        :error="form.errors[`accept_${code}`]"
                    >
                        <template #label>
                            {{ parts(code)[0]
                            }}<a :href="docs[code].href" target="_blank" rel="noopener" class="font-semibold text-primary-700 underline underline-offset-2"
                                >{{ docs[code].title }}<span class="sr-only"> {{ t('legal.signup.new_tab') }}</span></a
                            >{{ parts(code)[1] }}
                            <span class="block text-xs text-neutral-600"
                                >{{ t('legal.signup.version', { version: docs[code].version ?? '' })
                                }}<template v-if="code !== 'dpa' || legal.dpa_required"> · {{ t('legal.signup.required') }}</template></span
                            >
                        </template>
                    </CheckboxField>
                </fieldset>

                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                    <BaseButton variant="secondary" class="!border-line-strong" :disabled="form.processing" @click="go(1)">{{ t('register.back') }}</BaseButton>
                    <BaseButton type="submit" :loading="form.processing" :disabled="!legalReady">
                        {{ form.processing ? t('register.creating') : t('register.submit_plan') }}
                    </BaseButton>
                </div>
            </div>
        </form>

        <template #footer>
            {{ t('register.have_account') }}
            <Link :href="routes.login" class="font-medium text-primary-600 hover:text-primary-700">{{ t('login.submit') }}</Link>
        </template>
    </AuthLayout>
</template>
