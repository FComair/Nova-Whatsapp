<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import SelectField from '../../Components/SelectField.vue';
import TextField from '../../Components/TextField.vue';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { Wizard } from '../../types';

interface Site {
    id: string;
    name: string;
    is_default: boolean;
    address: { street: string | null; postal_code: string | null; city: string | null; country: string };
}

const props = defineProps<{ wizard: Wizard; locations: Site[]; countries: string[]; defaultCountry: string }>();

const { t } = useT();
const base = `${routes.onboarding}/sites`;

const editing = ref<string | 'new' | null>(null);
const toDelete = ref<Site | null>(null);
const deleting = ref(false);

const form = useForm({ name: '', street: '', postal_code: '', city: '', country: props.defaultCountry });
const continueForm = useForm({});
const pageErrors = computed(() => usePage().props.errors as Record<string, string>);
const countryOptions = computed(() => props.countries.map((value) => ({ value, label: t(`onboarding.company.countries.${value}`) })));

function startNew(): void {
    form.reset();
    form.clearErrors();
    form.country = props.defaultCountry;
    editing.value = 'new';
}

function startEdit(site: Site): void {
    form.clearErrors();
    form.name = site.name;
    form.street = site.address.street ?? '';
    form.postal_code = site.address.postal_code ?? '';
    form.city = site.address.city ?? '';
    form.country = site.address.country;
    editing.value = site.id;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };

    if (editing.value === 'new') {
        form.post(base, options);
    } else {
        form.put(`${base}/${editing.value}`, options);
    }
}

function confirmDelete(): void {
    if (!toDelete.value) {
        return;
    }

    deleting.value = true;
    router.delete(`${base}/${toDelete.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            toDelete.value = null;
        },
    });
}

function formatAddress(site: Site): string {
    return [site.address.street, [site.address.postal_code, site.address.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
}
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('onboarding.sites.title')">
        <h1 class="text-xl font-semibold text-rendivoo-900">{{ t('onboarding.sites.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('onboarding.sites.body') }}</p>

        <AlertBox v-if="pageErrors.site" variant="error" class="mt-4">{{ pageErrors.site }}</AlertBox>

        <ul class="mt-6 space-y-3">
            <li v-for="site in locations" :key="site.id" class="rounded-control border border-line p-4">
                <div v-if="editing !== site.id" class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium text-rendivoo-900">
                            <span class="break-words">{{ site.name }}</span>
                            <span v-if="site.is_default" class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-rendivoo-700">
                                {{ t('onboarding.sites.default_badge') }}
                            </span>
                        </p>
                        <p v-if="formatAddress(site)" class="mt-1 text-sm text-neutral-600">{{ formatAddress(site) }}</p>
                    </div>
                    <div class="flex gap-2">
                        <BaseButton variant="secondary" @click="startEdit(site)">{{ t('onboarding.sites.edit') }}</BaseButton>
                        <BaseButton v-if="locations.length > 1" variant="secondary" @click="toDelete = site">{{ t('onboarding.sites.delete') }}</BaseButton>
                    </div>
                </div>

                <form v-else class="space-y-4" novalidate @submit.prevent="save">
                    <TextField v-model="form.name" :label="t('onboarding.sites.name')" required :error="form.errors.name" />
                    <TextField v-model="form.street" :label="t('onboarding.sites.street')" autocomplete="street-address" :error="form.errors.street" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextField
                            v-model="form.postal_code"
                            :label="t('onboarding.sites.postal_code')"
                            autocomplete="postal-code"
                            :error="form.errors.postal_code"
                        />
                        <TextField v-model="form.city" :label="t('onboarding.sites.city')" autocomplete="address-level2" :error="form.errors.city" />
                    </div>
                    <SelectField
                        v-model="form.country"
                        :label="t('onboarding.sites.country')"
                        :options="countryOptions"
                        required
                        :error="form.errors.country"
                    />
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <BaseButton type="submit" :loading="form.processing">{{ t('onboarding.sites.save') }}</BaseButton>
                        <BaseButton variant="secondary" @click="editing = null">{{ t('common.cancel') }}</BaseButton>
                    </div>
                </form>
            </li>

            <li v-if="editing === 'new'" class="rounded-control border border-primary-600/40 bg-primary-50/40 p-4">
                <form class="space-y-4" novalidate @submit.prevent="save">
                    <TextField v-model="form.name" :label="t('onboarding.sites.name')" required :error="form.errors.name" />
                    <TextField v-model="form.street" :label="t('onboarding.sites.street')" autocomplete="street-address" :error="form.errors.street" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextField
                            v-model="form.postal_code"
                            :label="t('onboarding.sites.postal_code')"
                            autocomplete="postal-code"
                            :error="form.errors.postal_code"
                        />
                        <TextField v-model="form.city" :label="t('onboarding.sites.city')" autocomplete="address-level2" :error="form.errors.city" />
                    </div>
                    <SelectField
                        v-model="form.country"
                        :label="t('onboarding.sites.country')"
                        :options="countryOptions"
                        required
                        :error="form.errors.country"
                    />
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <BaseButton type="submit" :loading="form.processing">{{ t('onboarding.sites.save') }}</BaseButton>
                        <BaseButton variant="secondary" @click="editing = null">{{ t('common.cancel') }}</BaseButton>
                    </div>
                </form>
            </li>
        </ul>

        <div class="mt-4">
            <BaseButton v-if="editing === null" variant="secondary" @click="startNew">{{ t('onboarding.sites.add') }}</BaseButton>
        </div>

        <form class="mt-8" @submit.prevent="continueForm.post(`${base}/continuer`)">
            <BaseButton type="submit" :loading="continueForm.processing">{{ t('onboarding.continue') }}</BaseButton>
        </form>

        <ConfirmDialog
            :open="toDelete !== null"
            :title="t('onboarding.sites.delete_title')"
            :body="t('onboarding.sites.delete_body', { name: toDelete?.name ?? '' })"
            :confirm-label="t('onboarding.sites.delete_confirm')"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="toDelete = null"
        />
    </OnboardingLayout>
</template>
