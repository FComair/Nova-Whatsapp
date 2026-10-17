<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import LogoUploader from '../../Components/LogoUploader.vue';
import SelectField from '../../Components/SelectField.vue';
import ShopAvatar from '../../Components/ShopAvatar.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

const props = defineProps<{
    personal: { email: string; pendingEmail: string | null; phone: string | null; pendingPhone: string | null; locale: string };
    canEditCompany: boolean;
    company: { name: string; sector: string; country: string; timezone: string; locale: string };
    billing: { billing_name: string; vat_number: string; line1: string; postal_code: string; city: string } | null;
    sectors: { value: string; label: string }[];
    countries: string[];
    /** Fuseaux IANA proposés pour chaque pays (le fuseau principal en premier). */
    timezones: Record<string, string[]>;
}>();

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);

const emailForm = useForm({ current_password: '', email: '' });
const phoneForm = useForm({ current_password: '', phone: '' });
const phoneCode = useForm({ code: '' });
const company = useForm({ ...props.company });
const identity = useForm({ ...(props.billing ?? { billing_name: '', vat_number: '', line1: '', postal_code: '', city: '' }) });

const languageOptions = computed(() => (['fr', 'nl', 'en'] as const).map((value) => ({ value, label: t(`common.languages.${value}`) })));
const sectorOptions = computed(() => props.sectors);
const countryOptions = computed(() => props.countries.map((value) => ({ value, label: t(`onboarding.company.countries.${value}`) })));
const timezoneOptions = computed(() => (props.timezones[company.country] ?? []).map((value) => ({ value, label: value.replaceAll('_', ' ') })));
// Changer de pays propose les fuseaux de ce pays ; l'ancien choix est remplacé s'il n'en fait pas partie.
watch(
    () => company.country,
    (country) => {
        const zones = props.timezones[country] ?? [];

        if (!zones.includes(company.timezone)) {
            company.timezone = zones[0] ?? '';
        }
    },
);

function label(options: { value: string; label: string }[], value: string): string {
    return options.find((option) => option.value === value)?.label ?? value;
}

function changeLanguage(locale: string): void {
    router.post(routes.locale, { locale }, { preserveScroll: true });
}

function requestEmail(): void {
    emailForm.post(routes.security.email, { preserveScroll: true, onSuccess: () => emailForm.reset() });
}

function requestPhone(): void {
    phoneForm.post(routes.security.phone, { preserveScroll: true, onSuccess: () => phoneForm.reset() });
}

function confirmPhone(): void {
    phoneCode.post(routes.security.phoneConfirm, { preserveScroll: true, onSuccess: () => phoneCode.reset() });
}
</script>

<template>
    <SettingsLayout :title="t('account_profile.title')">
        <h1 class="text-2xl font-semibold text-rendivoo-900">{{ t('account_profile.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('account_profile.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-2xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.email && !emailForm.errors.email" variant="error" class="mt-4 max-w-2xl">{{ errors.email }}</AlertBox>

        <!-- Identité visuelle : le propriétaire envoie le logo, les autres rôles le voient -->
        <section
            v-if="page.props.auth.organization"
            class="mt-6 flex max-w-2xl flex-wrap items-start gap-5 rounded-card border border-line bg-surface shadow-xs p-5"
            aria-labelledby="logo"
        >
            <ShopAvatar :id="page.props.auth.organization.id" :name="company.name" :version="page.props.auth.organization.logo_version" size="xl" />
            <div class="min-w-0 flex-1 basis-64">
                <h2 id="logo" class="text-lg font-semibold text-rendivoo-900">{{ company.name }}</h2>
                <p class="mt-1 text-sm text-neutral-700">{{ t('logo.title') }}</p>
                <LogoUploader v-if="canEditCompany" class="mt-3" :has-logo="page.props.auth.organization.logo_version !== null" />
                <p v-else class="mt-3 text-sm text-neutral-600">{{ t('logo.owner_only') }}</p>
            </div>
        </section>

        <!-- Coordonnées de la personne connectée -->
        <section class="mt-8 max-w-2xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="personal">
            <h2 id="personal" class="text-lg font-semibold text-rendivoo-900">{{ t('account_profile.personal_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-700">{{ t('account_profile.personal_intro') }}</p>

            <div id="language" class="mt-4">
                <SelectField
                    :model-value="personal.locale"
                    :label="t('account_profile.language')"
                    :options="languageOptions"
                    @update:model-value="changeLanguage"
                />
                <p class="mt-1.5 text-sm text-neutral-600">{{ t('account_profile.language_hint') }}</p>
            </div>

            <div id="email" class="mt-6 border-t border-line pt-5">
                <h3 class="text-base font-semibold text-rendivoo-900">{{ t('security.email_section') }}</h3>
                <p class="mt-1 text-sm text-neutral-700">{{ t('security.email_current', { email: personal.email }) }}</p>
                <p v-if="personal.pendingEmail" class="mt-1 text-sm font-medium text-neutral-800">
                    {{ t('security.email_pending', { email: personal.pendingEmail }) }}
                </p>
                <form class="mt-3 space-y-4" novalidate @submit.prevent="requestEmail">
                    <TextField
                        v-model="emailForm.email"
                        type="email"
                        :label="t('security.email_new')"
                        autocomplete="email"
                        inputmode="email"
                        required
                        :error="emailForm.errors.email"
                    />
                    <TextField
                        v-model="emailForm.current_password"
                        type="password"
                        :label="t('security.current_password')"
                        autocomplete="current-password"
                        required
                        :error="emailForm.errors.current_password"
                    />
                    <BaseButton type="submit" :loading="emailForm.processing">{{ t('security.email_send') }}</BaseButton>
                </form>
            </div>

            <div id="phone" class="mt-6 border-t border-line pt-5">
                <h3 class="text-base font-semibold text-rendivoo-900">{{ t('security.phone_section') }}</h3>
                <p v-if="personal.phone" class="mt-1 text-sm text-neutral-700">{{ t('security.phone_current', { phone: personal.phone }) }}</p>
                <form class="mt-3 space-y-4" novalidate @submit.prevent="requestPhone">
                    <TextField
                        v-model="phoneForm.phone"
                        type="tel"
                        :label="t('security.phone_new')"
                        autocomplete="tel"
                        inputmode="tel"
                        required
                        :error="phoneForm.errors.phone"
                    />
                    <TextField
                        v-model="phoneForm.current_password"
                        type="password"
                        :label="t('security.current_password')"
                        autocomplete="current-password"
                        required
                        :error="phoneForm.errors.current_password"
                    />
                    <BaseButton type="submit" :loading="phoneForm.processing">{{ t('security.phone_send') }}</BaseButton>
                </form>
                <form v-if="personal.pendingPhone" class="mt-4 space-y-3 border-t border-line pt-4" novalidate @submit.prevent="confirmPhone">
                    <p class="text-sm text-neutral-700">{{ t('security.phone_pending', { phone: personal.pendingPhone }) }}</p>
                    <TextField
                        v-model="phoneCode.code"
                        :label="t('security.phone_code')"
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        required
                        :error="phoneCode.errors.code"
                    />
                    <BaseButton type="submit" :loading="phoneCode.processing">{{ t('security.phone_confirm') }}</BaseButton>
                </form>
            </div>
        </section>

        <!-- Entreprise : le propriétaire modifie, les autres rôles consultent -->
        <section class="mt-6 max-w-2xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="company">
            <h2 id="company" class="text-lg font-semibold text-rendivoo-900">{{ t('account_profile.company_title') }}</h2>

            <form v-if="canEditCompany" class="mt-3 space-y-5" novalidate @submit.prevent="company.put(routes.profileCompany, { preserveScroll: true })">
                <p class="text-sm text-neutral-700">{{ t('account_profile.company_intro') }}</p>
                <TextField
                    v-model="company.name"
                    :label="t('onboarding.company.name')"
                    autocomplete="organization"
                    required
                    :maxlength="120"
                    :error="company.errors.name"
                />
                <SelectField
                    v-model="company.sector"
                    :label="t('onboarding.company.sector')"
                    :options="sectorOptions"
                    required
                    :error="company.errors.sector"
                />
                <SelectField
                    v-model="company.country"
                    :label="t('onboarding.company.country')"
                    :options="countryOptions"
                    required
                    :error="company.errors.country"
                />
                <div>
                    <SelectField
                        v-model="company.timezone"
                        :label="t('onboarding.company.timezone')"
                        :options="timezoneOptions"
                        required
                        :error="company.errors.timezone"
                    />
                    <p class="mt-1.5 text-sm text-neutral-600">{{ t('onboarding.company.timezone_hint') }}</p>
                </div>
                <SelectField
                    v-model="company.locale"
                    :label="t('onboarding.company.locale')"
                    :options="languageOptions"
                    required
                    :error="company.errors.locale"
                />
                <BaseButton type="submit" :loading="company.processing">{{ t('account_profile.save') }}</BaseButton>
            </form>

            <template v-else>
                <p class="mt-1 text-sm text-neutral-700">{{ t('account_profile.company_readonly') }}</p>
                <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-neutral-600">{{ t('onboarding.company.name') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ company.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-600">{{ t('onboarding.company.sector') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ label(sectorOptions, company.sector) }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-600">{{ t('onboarding.company.country') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ label(countryOptions, company.country) }}</dd>
                    </div>
                    <div>
                        <dt class="text-neutral-600">{{ t('onboarding.company.timezone') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ label(timezoneOptions, company.timezone) }}</dd>
                    </div>
                </dl>
            </template>
        </section>

        <!-- Identité de facturation (propriétaire) : même enregistrement que sur la page Facturation -->
        <section v-if="billing" class="mt-6 max-w-2xl rounded-card border border-line bg-surface shadow-xs p-5" aria-labelledby="identity">
            <h2 id="identity" class="text-lg font-semibold text-rendivoo-900">{{ t('billing.identity_title') }}</h2>
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
    </SettingsLayout>
</template>
