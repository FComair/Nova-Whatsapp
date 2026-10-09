<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import PractitionerAvatar from '../../Components/PractitionerAvatar.vue';
import PractitionerForm from '../../Components/PractitionerForm.vue';
import TextField from '../../Components/TextField.vue';
import WeekEditor from '../../Components/WeekEditor.vue';
import { toDays, toPayload, type DayState } from '../../composables/hours';
import { useT } from '../../composables/useT';
import { formatDate } from '../../composables/dates';
import OnboardingLayout from '../../Layouts/OnboardingLayout.vue';
import { routes } from '../../routes';
import type { PractitionerFormData, PractitionerItem, Wizard } from '../../types';

const props = defineProps<{
    wizard: Wizard;
    practitioners: PractitionerItem[];
    services: { id: string; name: string }[];
    locations: { id: string; name: string }[];
    colors: string[];
    plan: { name: string; limit: number | null; used: number };
    invitationDays: number;
    meLinked: boolean;
}>();

const { t } = useT();
const base = '/configuration/praticiens';

const editing = ref<string | 'new' | null>(null);
const toDelete = ref<PractitionerItem | null>(null);
const deleting = ref(false);
const pageErrors = computed(() => usePage().props.errors as Record<string, string>);

const emptyForm = (isMe = false): PractitionerFormData => ({
    name: '',
    color: props.colors[0] ?? '#2A5BD7',
    bio: '',
    // Un praticien seul réalise en général tous les services : ils sont cochés par défaut.
    service_ids: props.services.map((service) => service.id),
    is_me: isMe,
    copy_hours: true,
    photo: null,
});

const form = useForm<PractitionerFormData>(emptyForm());
const continueForm = useForm({});
const activateForm = useForm({});
const reinviteForm = useForm({});

// --- Invitation par e-mail (le praticien renseigne lui-même ses services et disponibilités) ---
const inviting = ref(false);
const inviteForm = useForm({ name: '', email: '' });

function startInvite(): void {
    inviteForm.reset();
    inviteForm.clearErrors();
    editing.value = null;
    scheduleFor.value = null;
    inviting.value = true;
}

function sendInvite(): void {
    inviteForm.post(`${base}/invitation`, {
        preserveScroll: true,
        onSuccess: () => {
            inviting.value = false;
            inviteForm.reset();
        },
    });
}

function startNew(isMe = false): void {
    form.defaults(emptyForm(isMe)).reset();
    form.clearErrors();
    scheduleFor.value = null;
    inviting.value = false;
    editing.value = 'new';
}

function startEdit(practitioner: PractitionerItem): void {
    form.clearErrors();
    form.defaults({
        name: practitioner.name,
        color: practitioner.color,
        bio: practitioner.bio ?? '',
        service_ids: [...practitioner.service_ids],
        is_me: practitioner.is_me,
        copy_hours: false,
    }).reset();
    scheduleFor.value = null;
    inviting.value = false;
    editing.value = practitioner.id;
}

function save(): void {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };

    if (editing.value === 'new') {
        form.post(base, { ...options, forceFormData: true });
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

// --- Planning d'un praticien ---
const scheduleFor = ref<{ practitionerId: string; locationId: string } | null>(null);
const days = ref<DayState[]>(toDays({}));
const scheduleForm = useForm({ hours: toPayload(days.value) });

function openSchedule(practitioner: PractitionerItem, locationId = props.locations[0]?.id ?? ''): void {
    editing.value = null;
    scheduleForm.clearErrors();
    days.value = toDays(practitioner.schedules[locationId] ?? {});
    scheduleFor.value = { practitionerId: practitioner.id, locationId };
}

function saveSchedule(): void {
    if (!scheduleFor.value) {
        return;
    }

    scheduleForm.hours = toPayload(days.value);
    scheduleForm.put(`${base}/${scheduleFor.value.practitionerId}/horaires/${scheduleFor.value.locationId}`, { preserveScroll: true });
}

const scheduleErrors = computed(() =>
    Array.from({ length: 7 }, (_unused, index) =>
        Object.entries(scheduleForm.errors)
            .filter(([key]) => key.startsWith(`hours.${index}.`))
            .map(([, message]) => message),
    ),
);

function statusLabel(practitioner: PractitionerItem): string {
    if (practitioner.status === 'draft') {
        return t('practitioners.status.draft');
    }

    return practitioner.bookable ? t('practitioners.status.ready') : t('practitioners.status.incomplete');
}
</script>

<template>
    <OnboardingLayout :wizard="wizard" :title="t('practitioners.title')">
        <h1 class="text-xl font-semibold text-nova-900">{{ t('practitioners.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-600">{{ t('practitioners.body') }}</p>

        <p class="mt-3 rounded-control bg-primary-50 px-3 py-2 text-sm text-nova-700">
            {{
                plan.limit === null
                    ? t('practitioners.plan_usage_unlimited', { plan: plan.name, used: plan.used })
                    : t('practitioners.plan_usage', { plan: plan.name, used: plan.used, limit: plan.limit })
            }}
        </p>

        <AlertBox v-if="pageErrors.limit || pageErrors.practitioner || pageErrors.hours" variant="error" class="mt-4">
            {{ pageErrors.limit ?? pageErrors.practitioner ?? pageErrors.hours }}
        </AlertBox>

        <p v-if="practitioners.length === 0 && editing === null" class="mt-6 text-sm text-neutral-600">{{ t('practitioners.empty') }}</p>

        <ul class="mt-6 space-y-3">
            <li v-for="practitioner in practitioners" :key="practitioner.id" class="rounded-control border border-line p-4">
                <div v-if="editing !== practitioner.id" class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-medium text-nova-900">
                            <PractitionerAvatar :id="practitioner.id" :name="practitioner.name" :version="practitioner.photo_version" size="md" />
                            <span class="size-3 shrink-0 rounded-full" :style="{ backgroundColor: practitioner.color }" aria-hidden="true" />
                            <span class="break-words">{{ practitioner.name }}</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    practitioner.status === 'draft'
                                        ? 'bg-warning-50 text-warning-600'
                                        : practitioner.bookable
                                          ? 'bg-success-50 text-success-600'
                                          : 'bg-neutral-100 text-neutral-700'
                                "
                            >
                                {{ statusLabel(practitioner) }}
                            </span>
                        </p>
                        <p class="mt-1 text-sm text-neutral-600">{{ t('practitioners.services_count', { count: practitioner.service_ids.length }) }}</p>
                        <p v-if="practitioner.status === 'draft'" class="mt-1 text-sm text-warning-600">{{ t('practitioners.draft_notice') }}</p>
                        <p
                            v-if="practitioner.invitation"
                            class="mt-1 text-sm"
                            :class="practitioner.invitation.status === 'expired' ? 'text-danger-700' : 'text-neutral-600'"
                        >
                            {{
                                practitioner.invitation.status === 'expired'
                                    ? t('practitioners.invitation_expired', { email: practitioner.invitation.email })
                                    : t('practitioners.invitation_pending', {
                                          email: practitioner.invitation.email,
                                          date: formatDate(practitioner.invitation.expires_at),
                                      })
                            }}
                        </p>
                        <p v-else-if="practitioner.has_account && !practitioner.is_me" class="mt-1 text-sm text-success-600">
                            {{ t('practitioners.has_account') }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <BaseButton variant="secondary" @click="startEdit(practitioner)">{{ t('practitioners.edit') }}</BaseButton>
                        <BaseButton variant="secondary" @click="openSchedule(practitioner)">{{ t('practitioners.schedule') }}</BaseButton>
                        <BaseButton
                            v-if="practitioner.invitation"
                            variant="secondary"
                            :loading="reinviteForm.processing"
                            @click="reinviteForm.post(`${base}/${practitioner.id}/invitation`, { preserveScroll: true })"
                        >
                            {{ t('practitioners.resend') }}
                        </BaseButton>
                        <BaseButton
                            v-if="practitioner.status === 'draft'"
                            variant="secondary"
                            :loading="activateForm.processing"
                            @click="activateForm.post(`${base}/${practitioner.id}/activer`, { preserveScroll: true })"
                        >
                            {{ t('practitioners.activate') }}
                        </BaseButton>
                        <BaseButton variant="secondary" @click="toDelete = practitioner">{{ t('practitioners.delete') }}</BaseButton>
                    </div>
                </div>

                <PractitionerForm
                    v-else
                    v-model="form"
                    :practitioner="practitioner"
                    :errors="form.errors"
                    :processing="form.processing"
                    :services="services"
                    :colors="colors"
                    :can-be-me="false"
                    @submit="save"
                    @cancel="editing = null"
                />

                <!-- Planning hebdomadaire du praticien -->
                <div v-if="scheduleFor?.practitionerId === practitioner.id" class="mt-4 border-t border-line pt-4">
                    <h2 class="font-medium text-nova-900">{{ t('practitioners.hours_title', { name: practitioner.name }) }}</h2>
                    <p class="mt-1 text-sm text-neutral-600">{{ t('practitioners.hours_body') }}</p>

                    <ul v-if="locations.length > 1" class="mt-3 flex flex-wrap gap-2">
                        <li v-for="site in locations" :key="site.id">
                            <button
                                type="button"
                                class="rounded-control border px-3 py-1.5 text-sm font-medium"
                                :class="
                                    site.id === scheduleFor.locationId
                                        ? 'border-primary-600 bg-primary-50 text-nova-900'
                                        : 'border-line-strong text-neutral-700 hover:bg-surface-2'
                                "
                                :aria-pressed="site.id === scheduleFor.locationId"
                                @click="openSchedule(practitioner, site.id)"
                            >
                                {{ site.name }}
                            </button>
                        </li>
                    </ul>

                    <form class="mt-4" novalidate @submit.prevent="saveSchedule">
                        <WeekEditor v-model="days" :errors-by-day="scheduleErrors" />
                        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
                            <BaseButton type="submit" :loading="scheduleForm.processing">{{ t('practitioners.hours_save') }}</BaseButton>
                            <BaseButton variant="secondary" @click="scheduleFor = null">{{ t('common.cancel') }}</BaseButton>
                        </div>
                    </form>
                </div>
            </li>

            <li v-if="editing === 'new'" class="rounded-control border border-primary-600/40 bg-primary-50/40 p-4">
                <PractitionerForm
                    v-model="form"
                    :errors="form.errors"
                    :processing="form.processing"
                    :services="services"
                    :colors="colors"
                    :can-be-me="!meLinked"
                    @submit="save"
                    @cancel="editing = null"
                />
            </li>
        </ul>

        <form v-if="inviting" class="mt-4 space-y-4 rounded-control border border-primary-600/40 bg-primary-50/40 p-4" novalidate @submit.prevent="sendInvite">
            <h2 class="font-medium text-nova-900">{{ t('practitioners.invite') }}</h2>
            <p class="text-sm text-neutral-700">{{ t('practitioners.invite_hint', { days: invitationDays }) }}</p>
            <TextField v-model="inviteForm.name" :label="t('practitioners.invite_name')" required :error="inviteForm.errors.name" />
            <TextField
                v-model="inviteForm.email"
                type="email"
                inputmode="email"
                :label="t('practitioners.invite_email')"
                required
                :error="inviteForm.errors.email"
            />
            <div class="flex flex-col gap-3 sm:flex-row">
                <BaseButton type="submit" :loading="inviteForm.processing">{{ t('practitioners.invite_send') }}</BaseButton>
                <BaseButton variant="secondary" @click="inviting = false">{{ t('common.cancel') }}</BaseButton>
            </div>
        </form>

        <div v-if="editing === null && !inviting" class="mt-4 flex flex-wrap gap-3">
            <BaseButton variant="secondary" @click="startNew(false)">{{ t('practitioners.add') }}</BaseButton>
            <BaseButton variant="secondary" @click="startInvite">{{ t('practitioners.invite') }}</BaseButton>
            <BaseButton v-if="!meLinked" variant="secondary" @click="startNew(true)">{{ t('practitioners.add_me') }}</BaseButton>
        </div>

        <form class="mt-8" @submit.prevent="continueForm.post(`${routes.onboarding}/praticiens/continuer`)">
            <BaseButton type="submit" :loading="continueForm.processing">{{ t('onboarding.continue') }}</BaseButton>
        </form>

        <ConfirmDialog
            :open="toDelete !== null"
            :title="t('practitioners.delete_title')"
            :body="t('practitioners.delete_body', { name: toDelete?.name ?? '' })"
            :confirm-label="t('practitioners.delete_confirm')"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="toDelete = null"
        />
    </OnboardingLayout>
</template>
