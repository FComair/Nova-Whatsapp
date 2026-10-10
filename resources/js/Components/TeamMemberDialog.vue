<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue';
import { accessWarnings, baseRoleOf, diffCapabilities, sameCapabilities, type TeamCatalog, type TeamMember, type TeamPreset } from '../composables/team';
import { useT } from '../composables/useT';
import AlertBox from './AlertBox.vue';
import BaseButton from './BaseButton.vue';
import CheckboxField from './CheckboxField.vue';
import PermissionPicker from './PermissionPicker.vue';
import TextareaField from './TextareaField.vue';
import TextField from './TextField.vue';

export interface TeamPayload {
    name: string;
    email: string;
    role: 'practitioner' | 'receptionist';
    phone: string;
    notes: string;
    capabilities: string[];
    acknowledged: boolean;
}

const props = defineProps<{
    open: boolean;
    mode: 'add' | 'edit';
    member: TeamMember | null;
    catalog: TeamCatalog;
    presets: Record<'practitioner' | 'assistant', TeamPreset>;
    grantable: string[];
    isOwner: boolean;
    loading: boolean;
    /** Message du serveur (adresse déjà utilisée, droit refusé…) et son code. */
    error: string | null;
    errorCode: string | null;
    fieldErrors: Record<string, string | undefined>;
}>();

const emit = defineEmits<{ close: []; submit: [payload: TeamPayload]; 'open-member': [email: string] }>();

const { t } = useT();
const dialog = ref<HTMLDialogElement | null>(null);
const titleId = useId();

type RoleChoice = 'practitioner' | 'assistant';
const step = ref(0);
const name = ref('');
const email = ref('');
const phone = ref('');
const notes = ref('');
const role = ref<RoleChoice>('practitioner');
const caps = ref<string[]>([]);
const acknowledged = ref(false);
const sensitiveConfirmed = ref(false);

const steps = computed(() => (props.mode === 'add' ? ['details', 'access', 'review'] : ['access', 'review']));
const current = computed(() => steps.value[step.value]);
const preset = computed(() => props.presets[role.value]);
const before = computed(() => (props.member === null ? [] : props.member.capabilities));
const diff = computed(() => diffCapabilities(props.mode === 'edit' ? before.value : preset.value.capabilities, caps.value));
const warnings = computed(() => accessWarnings(baseRoleOf(role.value), caps.value));
const sensitiveGranted = computed(() => caps.value.filter((key) => props.catalog.protected.includes(key) && !before.value.includes(key)));
const allKeys = computed(() => Object.values(props.catalog.groups).flat());
const excluded = computed(() => allKeys.value.filter((key) => !caps.value.includes(key)));
const canSubmit = computed(() => (warnings.value.length === 0 || acknowledged.value) && (sensitiveGranted.value.length === 0 || sensitiveConfirmed.value));
const duplicate = computed(() => props.errorCode === 'already_member' || props.errorCode === 'already_member_inactive');

// <dialog> natif : focus piégé, fermeture par Échap, arrière-plan inerte (accessibilité).
watch(
    () => props.open,
    async (open) => {
        if (open) {
            init();
        }

        await nextTick();

        if (open && !dialog.value?.open) {
            dialog.value?.showModal();
        } else if (!open && dialog.value?.open) {
            dialog.value.close();
        }
    },
    { immediate: true },
);

function init(): void {
    step.value = 0;
    acknowledged.value = false;
    sensitiveConfirmed.value = false;

    if (props.mode === 'edit' && props.member !== null) {
        name.value = props.member.name;
        email.value = props.member.email;
        phone.value = props.member.phone ?? '';
        notes.value = props.member.notes ?? '';
        role.value = props.member.role === 'practitioner' ? 'practitioner' : 'assistant';
        caps.value = [...props.member.capabilities];
    } else {
        name.value = '';
        email.value = '';
        phone.value = '';
        notes.value = '';
        role.value = 'practitioner';
        caps.value = [...props.presets.practitioner.capabilities];
    }
}

/** Changer de rôle repart des accès par défaut de ce rôle, sauf si la personne avait déjà personnalisé ceux de l'ancien. */
function chooseRole(next: RoleChoice): void {
    const untouched = sameCapabilities(caps.value, props.presets[role.value].capabilities);
    role.value = next;

    if (untouched) {
        caps.value = [...props.presets[next].capabilities];
    }
}

const detailsValid = computed(() => name.value.trim() !== '' && /^\S+@\S+\.\S+$/.test(email.value.trim()));

function next(): void {
    if (current.value === 'details' && !detailsValid.value) {
        return;
    }

    step.value = Math.min(step.value + 1, steps.value.length - 1);
}

function submit(): void {
    emit('submit', {
        name: name.value,
        email: email.value,
        role: baseRoleOf(role.value),
        phone: phone.value,
        notes: notes.value,
        capabilities: caps.value,
        acknowledged: acknowledged.value,
    });
}

watch(
    () => props.error,
    (error) => {
        // Une adresse refusée se corrige à l'étape 1 ; les autres refus restent lisibles sur le résumé.
        if (
            error !== null &&
            props.mode === 'add' &&
            ['already_member', 'already_member_inactive', 'already_invited', 'email_unavailable'].includes(props.errorCode ?? '')
        ) {
            step.value = 0;
        }
    },
);
</script>

<template>
    <dialog
        ref="dialog"
        :aria-labelledby="titleId"
        class="m-auto max-h-[92vh] w-[calc(100%-1.5rem)] max-w-2xl overflow-y-auto rounded-modal border border-line bg-surface p-5 shadow-xl backdrop:bg-nova-900/55 sm:p-6"
        @cancel.prevent="emit('close')"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 :id="titleId" class="text-lg font-semibold text-nova-900">
                    {{ mode === 'add' ? t('team.dialog.add_title') : t('team.dialog.edit_title', { name: member?.name ?? '' }) }}
                </h2>
                <p class="mt-0.5 text-sm text-neutral-600" aria-live="polite">
                    {{ t('team.dialog.step', { current: step + 1, total: steps.length }) }} · {{ t(`team.dialog.steps.${current}`) }}
                </p>
            </div>
            <button type="button" class="-mr-2 -mt-1 rounded-control px-2 py-1 text-sm font-medium text-neutral-600 hover:bg-surface-2" @click="emit('close')">
                {{ t('common.cancel') }}
            </button>
        </div>

        <AlertBox v-if="error" variant="error" class="mt-4">
            {{ error }}
            <button v-if="duplicate" type="button" class="mt-2 block font-semibold underline" @click="emit('open-member', email)">
                {{ t('team.dialog.open_existing') }}
            </button>
        </AlertBox>

        <form class="mt-5 space-y-5" novalidate @submit.prevent="current === 'review' ? submit() : next()">
            <!-- 1. Coordonnées (ajout seulement) -->
            <div v-if="current === 'details'" class="space-y-4">
                <TextField v-model="name" :label="t('team.fields.name')" required autocomplete="off" :error="fieldErrors.name" />
                <TextField
                    v-model="email"
                    type="email"
                    :label="t('team.fields.email')"
                    required
                    autocomplete="off"
                    :hint="t('team.fields.email_hint')"
                    :error="fieldErrors.email"
                />
                <TextField v-model="phone" type="tel" :label="t('team.fields.phone')" autocomplete="off" :error="fieldErrors.phone" />
                <fieldset>
                    <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('team.fields.role') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label
                            v-for="choice in ['practitioner', 'assistant'] as const"
                            :key="choice"
                            class="flex cursor-pointer items-start gap-3 rounded-card border p-3"
                            :class="role === choice ? 'border-primary-600 bg-primary-50' : 'border-line'"
                        >
                            <input type="radio" name="team-role" class="mt-1" :checked="role === choice" @change="chooseRole(choice)" />
                            <span>
                                <span class="block text-sm font-semibold text-neutral-900">{{ t(`team.roles.${choice}`) }}</span>
                                <span class="block text-xs text-neutral-600">{{ t(`team.roles_hint.${choice}`) }}</span>
                            </span>
                        </label>
                    </div>
                </fieldset>
                <TextareaField v-model="notes" :label="t('team.fields.notes')" :hint="t('team.fields.notes_hint')" :rows="2" :maxlength="1000" />
            </div>

            <!-- 2. Accès -->
            <div v-else-if="current === 'access'" class="space-y-4">
                <fieldset v-if="mode === 'edit' && member?.role !== 'site_manager'">
                    <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('team.fields.role') }}</legend>
                    <div class="flex flex-wrap gap-3">
                        <label v-for="choice in ['practitioner', 'assistant'] as const" :key="choice" class="flex cursor-pointer items-center gap-2 text-sm">
                            <input type="radio" name="team-role-edit" :checked="role === choice" @change="chooseRole(choice)" />
                            {{ t(`team.roles.${choice}`) }}
                        </label>
                    </div>
                </fieldset>
                <PermissionPicker
                    v-model="caps"
                    :catalog="catalog"
                    :preset="preset.capabilities"
                    :preset-label="t(`team.roles.${role}`)"
                    :grantable="grantable"
                    :is-owner="isOwner"
                />
            </div>

            <!-- 3. Résumé avant d'enregistrer -->
            <div v-else class="space-y-4 text-sm">
                <dl class="grid gap-x-6 gap-y-2 rounded-card border border-line bg-neutral-50 p-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.name') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.email') }}</dt>
                        <dd class="font-medium text-neutral-900 break-all">{{ email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.role') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ t(`team.roles.${role}`) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.summary.access') }}</dt>
                        <dd class="font-medium text-neutral-900">
                            {{
                                sameCapabilities(caps, preset.capabilities)
                                    ? t('team.summary.preset', { preset: t(`team.roles.${role}`) })
                                    : t('team.picker.custom')
                            }}
                            · {{ t('team.summary.count', { on: caps.length, total: allKeys.length }) }}
                        </dd>
                    </div>
                </dl>

                <template v-if="mode === 'edit'">
                    <div v-if="diff.added.length === 0 && diff.removed.length === 0" class="rounded-card border border-line p-3 text-neutral-700">
                        {{ t('team.summary.no_change') }}
                    </div>
                    <div v-if="diff.added.length" class="rounded-card border border-success-fg/25 bg-success-bg p-3">
                        <p class="font-semibold text-success-fg">{{ t('team.summary.added') }}</p>
                        <ul class="mt-1 list-disc ps-5 text-neutral-800">
                            <li v-for="key in diff.added" :key="key">{{ t(`team.caps.${key}.label`) }}</li>
                        </ul>
                    </div>
                    <div v-if="diff.removed.length" class="rounded-card border border-warning-fg/25 bg-warning-bg p-3">
                        <p class="font-semibold text-warning-fg">{{ t('team.summary.removed') }}</p>
                        <ul class="mt-1 list-disc ps-5 text-neutral-800">
                            <li v-for="key in diff.removed" :key="key">{{ t(`team.caps.${key}.label`) }}</li>
                        </ul>
                    </div>
                </template>

                <div>
                    <p class="font-semibold text-neutral-900">{{ t('team.summary.granted') }}</p>
                    <ul v-if="caps.length" class="mt-1 grid gap-x-6 gap-y-0.5 text-neutral-800 sm:grid-cols-2">
                        <li v-for="key in allKeys.filter((k) => caps.includes(k))" :key="key" class="flex gap-2">
                            <span aria-hidden="true">✓</span>{{ t(`team.caps.${key}.label`) }}
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-neutral-600">{{ t('team.summary.nothing') }}</p>
                </div>
                <details v-if="excluded.length">
                    <summary class="cursor-pointer font-semibold text-neutral-900">{{ t('team.summary.excluded', { count: excluded.length }) }}</summary>
                    <ul class="mt-1 grid gap-x-6 gap-y-0.5 text-neutral-600 sm:grid-cols-2">
                        <li v-for="key in excluded" :key="key" class="flex gap-2"><span aria-hidden="true">–</span>{{ t(`team.caps.${key}.label`) }}</li>
                    </ul>
                </details>

                <AlertBox v-if="warnings.length" variant="warning">
                    <p class="font-semibold">{{ t('team.warnings.title') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        <li v-for="warning in warnings" :key="warning">{{ t(`team.warnings.${warning}`) }}</li>
                    </ul>
                    <div class="mt-3"><CheckboxField v-model="acknowledged" :label="t('team.warnings.acknowledge')" /></div>
                </AlertBox>
                <AlertBox v-if="sensitiveGranted.length" variant="warning">
                    <p class="font-semibold">{{ t('team.sensitive.title') }}</p>
                    <ul class="mt-1 list-disc ps-5">
                        <li v-for="key in sensitiveGranted" :key="key">{{ t(`team.caps.${key}.label`) }}</li>
                    </ul>
                    <div class="mt-3"><CheckboxField v-model="sensitiveConfirmed" :label="t('team.sensitive.confirm')" /></div>
                </AlertBox>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-line pt-4 sm:flex-row sm:justify-between">
                <BaseButton variant="secondary" @click="step === 0 ? emit('close') : (step -= 1)">{{
                    step === 0 ? t('common.cancel') : t('team.dialog.back')
                }}</BaseButton>
                <BaseButton v-if="current !== 'review'" type="submit" :disabled="current === 'details' && !detailsValid">{{
                    t('team.dialog.next')
                }}</BaseButton>
                <BaseButton v-else type="submit" :loading="loading" :disabled="!canSubmit">{{
                    mode === 'add' ? t('team.dialog.send') : t('team.dialog.save')
                }}</BaseButton>
            </div>
        </form>
    </dialog>
</template>
