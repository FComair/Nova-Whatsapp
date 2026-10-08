<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import ServiceForm from '../../Components/ServiceForm.vue';
import { centsToInput, formatPrice } from '../../composables/money';
import { useT } from '../../composables/useT';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { ServiceFormData, ServiceItem, SharedProps, Wizard } from '../../types';

const props = defineProps<{
    wizard: Wizard;
    services: ServiceItem[];
    categories: string[];
    template: { sector: string; count: number } | null;
    vatRates: number[];
}>();

const { t, locale } = useT();
const base = '/configuration/services';

const editing = ref<string | 'new' | null>(null);
const toDelete = ref<ServiceItem | null>(null);
const deleting = ref(false);
const errors = computed(() => usePage().props.errors as Record<string, string>);
// L'import depuis une liste de prix est réservé au propriétaire, comme sur le serveur.
const canImport = computed(() => (usePage<SharedProps>().props.auth.organization?.role ?? null) === 'owner');

const emptyForm = (): ServiceFormData => ({
    name: '',
    category: '',
    description: '',
    duration_minutes: '30',
    buffer_before_minutes: '0',
    buffer_after_minutes: '0',
    price: '',
    vat_rate: '21',
    capacity: '1',
    bookable_online: true,
    notes_required: false,
});

const form = useForm<ServiceFormData>(emptyForm());
const templateForm = useForm({});
const continueForm = useForm({});

// Sans service, on propose de choisir une méthode ; dès qu'il y en a (ou qu'on saisit à la main), on affiche la liste.
const showMethods = computed(() => props.services.length === 0 && editing.value === null);

function startNew(): void {
    form.defaults(emptyForm()).reset();
    form.clearErrors();
    editing.value = 'new';
}

function startEdit(service: ServiceItem): void {
    form.clearErrors();
    form.defaults({
        name: service.name,
        category: service.category ?? '',
        description: service.description ?? '',
        duration_minutes: String(service.duration_minutes),
        buffer_before_minutes: String(service.buffer_before_minutes),
        buffer_after_minutes: String(service.buffer_after_minutes),
        price: centsToInput(service.price_cents, locale.value),
        vat_rate: String(service.vat_rate),
        capacity: String(service.capacity),
        bookable_online: service.bookable_online,
        notes_required: service.notes_required,
    }).reset();
    editing.value = service.id;
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
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('services.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('services.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('services.body') }}</p>

        <AlertBox variant="warning" class="mt-4">{{ t('services.indicative_notice') }}</AlertBox>
        <AlertBox v-if="errors.template || errors.service" variant="error" class="mt-4">{{ errors.template ?? errors.service }}</AlertBox>

        <Link
            v-if="canImport"
            :href="routes.servicesImport"
            class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-lg border border-primary-600 bg-white px-4 text-base font-semibold text-primary-700 hover:bg-primary-50"
        >
            <svg
                class="size-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.9"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M7 12h10" />
            </svg>
            {{ t('price_import.entry') }}
        </Link>

        <!-- Méthodes de création (flowchart 1B, étape 4) -->
        <div v-if="showMethods" class="mt-6">
            <h2 class="text-base font-semibold text-nova-900">{{ t('services.methods_title') }}</h2>
            <ul class="mt-3 grid gap-4 md:grid-cols-3">
                <li v-if="template" class="flex flex-col rounded-lg border border-primary-600/40 bg-primary-50/40 p-4">
                    <h3 class="font-medium text-nova-900">{{ t('services.template_title') }}</h3>
                    <p class="mt-1 flex-1 text-sm text-neutral-700">{{ t('services.template_body', { count: template.count }) }}</p>
                    <BaseButton class="mt-4" :loading="templateForm.processing" @click="templateForm.post(`${base}/modele`, { preserveScroll: true })">
                        {{ t('services.template_action') }}
                    </BaseButton>
                </li>
                <li class="flex flex-col rounded-lg border border-neutral-200 p-4">
                    <h3 class="font-medium text-nova-900">{{ t('services.manual_title') }}</h3>
                    <p class="mt-1 flex-1 text-sm text-neutral-700">{{ t('services.manual_body') }}</p>
                    <BaseButton class="mt-4" variant="secondary" @click="startNew">{{ t('services.manual_action') }}</BaseButton>
                </li>
                <li class="flex flex-col rounded-lg border border-dashed border-neutral-300 p-4 text-neutral-500">
                    <h3 class="font-medium">{{ t('services.scan_title') }}</h3>
                    <p class="mt-1 flex-1 text-sm">{{ t('services.scan_body') }}</p>
                    <span class="mt-4 inline-block text-sm font-medium">{{ t('services.soon') }}</span>
                </li>
            </ul>
        </div>

        <!-- Catalogue -->
        <div v-else class="mt-6">
            <p v-if="services.length === 0" class="text-sm text-neutral-600">{{ t('services.empty') }}</p>

            <ul class="space-y-3">
                <li v-for="service in services" :key="service.id" class="rounded-lg border border-neutral-200 p-4">
                    <div v-if="editing !== service.id" class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="break-words font-medium text-nova-900">{{ service.name }}</p>
                            <p class="mt-1 text-sm text-neutral-600">
                                <span v-if="service.category">{{ service.category }} · </span>
                                {{ service.duration_minutes }} {{ t('services.minutes') }} ·
                                {{ service.price_cents === 0 ? t('services.free') : formatPrice(service.price_cents, locale) }}
                                <span v-if="!service.bookable_online"> · {{ t('services.offline') }}</span>
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <BaseButton variant="secondary" @click="startEdit(service)">{{ t('services.edit') }}</BaseButton>
                            <BaseButton variant="secondary" @click="toDelete = service">{{ t('services.delete') }}</BaseButton>
                        </div>
                    </div>

                    <ServiceForm
                        v-else
                        v-model="form"
                        :errors="form.errors"
                        :processing="form.processing"
                        :categories="categories"
                        :vat-rates="vatRates"
                        @submit="save"
                        @cancel="editing = null"
                    />
                </li>

                <li v-if="editing === 'new'" class="rounded-lg border border-primary-600/40 bg-primary-50/40 p-4">
                    <ServiceForm
                        v-model="form"
                        :errors="form.errors"
                        :processing="form.processing"
                        :categories="categories"
                        :vat-rates="vatRates"
                        @submit="save"
                        @cancel="editing = null"
                    />
                </li>
            </ul>

            <div v-if="editing === null" class="mt-4">
                <BaseButton variant="secondary" @click="startNew">{{ t('services.add') }}</BaseButton>
            </div>

            <form class="mt-8" @submit.prevent="continueForm.post(`${routes.onboarding}/services/continuer`)">
                <BaseButton type="submit" :loading="continueForm.processing">{{ t('onboarding.continue') }}</BaseButton>
            </form>
        </div>

        <ConfirmDialog
            :open="toDelete !== null"
            :title="t('services.delete_title')"
            :body="t('services.delete_body', { name: toDelete?.name ?? '' })"
            :confirm-label="t('services.delete_confirm')"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="toDelete = null"
        />
    </OnboardingLayout>
</template>
