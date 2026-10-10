<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import Badge from '../../Components/Badge.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ModalDialog from '../../Components/ModalDialog.vue';
import PractitionerAvatar from '../../Components/PractitionerAvatar.vue';
import TeamMemberDialog, { type TeamPayload } from '../../Components/TeamMemberDialog.vue';
import TextareaField from '../../Components/TextareaField.vue';
import { formatDate, formatDateTime } from '../../composables/dates';
import {
    emptyFilters,
    filterMembers,
    initials,
    sortMembers,
    type MemberRole,
    type MemberStatus,
    type TeamCatalog,
    type TeamFilters,
    type TeamMember,
    type TeamPreset,
    type TeamSort,
} from '../../composables/team';
import { useT } from '../../composables/useT';
import SettingsLayout from '../../Layouts/SettingsLayout.vue';
import { routes } from '../../routes';
import type { SharedProps } from '../../types';

const props = defineProps<{
    members: TeamMember[];
    catalog: TeamCatalog;
    presets: Record<'practitioner' | 'assistant', TeamPreset>;
    grantable: string[];
    is_owner: boolean;
}>();

const { t } = useT();
const page = usePage<SharedProps>();

const filters = ref<TeamFilters>({ ...emptyFilters });
const sort = ref<TeamSort>('name');
const direction = ref<'asc' | 'desc'>('asc');

const roleLabel = (role: MemberRole): string => t(`team.roles.${role}`);
const visible = computed(() => sortMembers(filterMembers(props.members, filters.value, roleLabel), sort.value, direction.value));
const filtering = computed(() => Object.values(filters.value).some((value) => value !== ''));
const onlyOwner = computed(() => props.members.filter((member) => !member.is_owner).length === 0);

const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const serverError = computed(() => errors.value.team ?? null);
const errorCode = computed(() => errors.value.team_code ?? null);

// --- Ajout et modification -------------------------------------------------------------------------------------------------------------
const dialog = ref<{ mode: 'add' | 'edit'; member: TeamMember | null } | null>(null);
const saving = ref(false);

function openAdd(): void {
    dialog.value = { mode: 'add', member: null };
}

function openEdit(member: TeamMember): void {
    profile.value = null;
    dialog.value = { mode: 'edit', member };
}

function submit(payload: TeamPayload): void {
    if (dialog.value === null) {
        return;
    }

    const options = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onSuccess: () => (dialog.value = null),
    };

    if (dialog.value.mode === 'add') {
        router.post(routes.team, { ...payload }, options);
    } else {
        router.put(`${routes.team}/${dialog.value.member?.id}`, { ...payload }, options);
    }
}

function openExisting(email: string): void {
    const found = props.members.find((member) => member.email.toLowerCase() === email.trim().toLowerCase());
    dialog.value = null;

    if (found) {
        profile.value = found;
    }
}

// --- Profil ----------------------------------------------------------------------------------------------------------------------------
const profile = ref<TeamMember | null>(null);

// --- Statut : suspendre, réactiver, retirer, invitations, mot de passe ---------------------------------------------------------------
type ActionType = 'suspend' | 'reactivate' | 'remove' | 'resend' | 'cancel' | 'reset';
const action = ref<{ type: ActionType; member: TeamMember } | null>(null);
const reason = ref('');
const acting = ref(false);

function ask(type: ActionType, member: TeamMember): void {
    reason.value = '';
    profile.value = null;
    action.value = { type, member };
}

function confirmAction(): void {
    if (action.value === null) {
        return;
    }

    const { type, member } = action.value;
    const url = `${routes.team}/${member.id}`;
    const options = {
        preserveScroll: true,
        onStart: () => (acting.value = true),
        onFinish: () => (acting.value = false),
        onSuccess: () => (action.value = null),
    };
    const data = { reason: reason.value };

    switch (type) {
        case 'suspend':
            router.post(`${url}/suspendre`, data, options);
            break;
        case 'reactivate':
            router.post(`${url}/reactiver`, {}, options);
            break;
        case 'remove':
            router.delete(url, { ...options, data });
            break;
        case 'resend':
            router.post(`${url}/invitation`, {}, options);
            break;
        case 'cancel':
            router.delete(`${url}/invitation`, options);
            break;
        case 'reset':
            router.post(`${url}/mot-de-passe`, {}, options);
            break;
    }
}

const withReason = computed(() => action.value !== null && ['suspend', 'remove'].includes(action.value.type));

function actionsFor(member: TeamMember): ActionType[] {
    if (!member.editable) {
        return [];
    }

    if (member.kind === 'invitation') {
        return ['resend', 'cancel'];
    }

    return (
        { active: ['reset', 'suspend', 'remove'], suspended: ['reactivate', 'remove'], removed: ['reactivate'], invited: [], expired: [] } as Record<
            MemberStatus,
            ActionType[]
        >
    )[member.status];
}

function statusVariant(status: MemberStatus): 'success' | 'warning' | 'danger' | 'info' | 'neutral' {
    return ({ active: 'success', invited: 'info', expired: 'warning', suspended: 'warning', removed: 'neutral' } as const)[status];
}

function lastSeen(member: TeamMember): string {
    return member.last_login_at === null ? t('team.never') : formatDateTime(member.last_login_at);
}

const actionLabel = (type: ActionType): string => t(`team.actions.${type}`);
</script>

<template>
    <SettingsLayout :title="t('team.title')">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-nova-900">{{ t('team.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-neutral-700">{{ t('team.intro') }}</p>
            </div>
            <BaseButton @click="openAdd">{{ t('team.add') }}</BaseButton>
        </div>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-3xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="serverError && dialog === null" variant="error" class="mt-4 max-w-3xl">{{ serverError }}</AlertBox>

        <!-- Recherche, filtres, tri -->
        <form class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" role="search" :aria-label="t('team.search_label')" @submit.prevent>
            <div class="sm:col-span-2">
                <label for="team-search" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('team.search_label') }}</label>
                <input
                    id="team-search"
                    v-model="filters.q"
                    type="search"
                    :placeholder="t('team.search')"
                    class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3.5 py-2.5 text-base"
                />
            </div>
            <div>
                <label for="team-role" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('team.filters.role') }}</label>
                <select
                    id="team-role"
                    v-model="filters.role"
                    class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3 py-2.5 text-base"
                >
                    <option value="">{{ t('team.filters.all') }}</option>
                    <option v-for="role in ['owner', 'site_manager', 'practitioner', 'assistant'] as const" :key="role" :value="role">
                        {{ roleLabel(role) }}
                    </option>
                </select>
            </div>
            <div>
                <label for="team-status" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('team.filters.status') }}</label>
                <select
                    id="team-status"
                    v-model="filters.status"
                    class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3 py-2.5 text-base"
                >
                    <option value="">{{ t('team.filters.all') }}</option>
                    <option v-for="status in ['active', 'invited', 'suspended', 'removed'] as const" :key="status" :value="status">
                        {{ t(`team.status.${status}`) }}
                    </option>
                </select>
            </div>
            <div>
                <label for="team-level" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('team.filters.level') }}</label>
                <select
                    id="team-level"
                    v-model="filters.level"
                    class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3 py-2.5 text-base"
                >
                    <option value="">{{ t('team.filters.all') }}</option>
                    <option v-for="level in ['full', 'broad', 'limited', 'minimal'] as const" :key="level" :value="level">
                        {{ t(`team.level.${level}`) }}
                    </option>
                </select>
            </div>
            <div>
                <label for="team-sort" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ t('team.sort.label') }}</label>
                <select id="team-sort" v-model="sort" class="block w-full rounded-control border-[1.5px] border-line-strong bg-surface px-3 py-2.5 text-base">
                    <option v-for="key in ['name', 'role', 'status', 'activity'] as const" :key="key" :value="key">{{ t(`team.sort.${key}`) }}</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :aria-label="direction === 'asc' ? t('team.sort.asc') : t('team.sort.desc')"
                    @click="direction = direction === 'asc' ? 'desc' : 'asc'"
                >
                    {{ direction === 'asc' ? '↑' : '↓' }} {{ direction === 'asc' ? t('team.sort.asc') : t('team.sort.desc') }}
                </BaseButton>
                <BaseButton v-if="filtering" variant="ghost" size="sm" @click="filters = { ...emptyFilters }">{{ t('team.filters.clear') }}</BaseButton>
            </div>
        </form>
        <p class="mt-3 text-sm text-neutral-600" role="status" aria-live="polite">{{ t('team.count', { shown: visible.length, total: members.length }) }}</p>

        <!-- Liste -->
        <ul v-if="visible.length" class="mt-3 divide-y divide-line rounded-card border border-line bg-surface shadow-xs" :aria-label="t('team.title')">
            <li v-for="member in visible" :key="member.id" class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3">
                <div class="flex min-w-[15rem] flex-1 basis-60 items-center gap-3">
                    <PractitionerAvatar
                        v-if="member.practitioner_id"
                        :id="member.practitioner_id"
                        :name="member.name"
                        :version="member.photo_version"
                        size="md"
                    />
                    <span
                        v-else
                        class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-700"
                        aria-hidden="true"
                        >{{ initials(member.name) }}</span
                    >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 font-semibold text-neutral-900">
                            <span class="truncate">{{ member.name }}</span>
                            <Badge v-if="member.is_owner" variant="info">{{ t('team.owner_badge') }}</Badge>
                            <span v-if="member.is_self" class="text-xs font-normal text-neutral-600">({{ t('team.you') }})</span>
                        </p>
                        <p class="truncate text-sm text-neutral-600">{{ member.email }}</p>
                    </div>
                </div>
                <dl class="grid min-w-[16rem] flex-1 basis-80 grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.role') }}</dt>
                        <dd class="font-medium text-neutral-900">{{ roleLabel(member.role) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.access') }}</dt>
                        <dd class="font-medium text-neutral-900">
                            {{ member.is_owner ? t('team.level.full') : t(`team.level.${member.level}`) }}
                            <span class="font-normal text-neutral-600"
                                >· {{ member.capabilities.length }}/{{ Object.values(catalog.groups).flat().length }}</span
                            >
                        </dd>
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.last_activity') }}</dt>
                        <dd class="text-neutral-800">
                            {{
                                member.kind === 'invitation'
                                    ? t('team.invited_on', { date: member.invited_at ? formatDate(member.invited_at) : '—' })
                                    : lastSeen(member)
                            }}
                        </dd>
                    </div>
                </dl>
                <div class="flex items-center gap-2">
                    <Badge :variant="statusVariant(member.status)">{{ t(`team.status.${member.status}`) }}</Badge>
                </div>
                <div class="flex flex-wrap gap-2">
                    <BaseButton variant="secondary" size="sm" @click="profile = member">{{ t('team.actions.view') }}</BaseButton>
                    <BaseButton v-if="member.editable && member.status !== 'removed'" variant="secondary" size="sm" @click="openEdit(member)">{{
                        t('team.actions.edit')
                    }}</BaseButton>
                </div>
            </li>
        </ul>

        <!-- États vides -->
        <div v-else-if="filtering" class="mt-3 rounded-card border border-dashed border-line-strong p-8 text-center">
            <p class="font-semibold text-neutral-900">{{ t('team.no_results.title') }}</p>
            <p class="mt-1 text-sm text-neutral-600">{{ t('team.no_results.text') }}</p>
            <BaseButton class="mt-4" variant="secondary" @click="filters = { ...emptyFilters }">{{ t('team.filters.clear') }}</BaseButton>
        </div>

        <div v-if="onlyOwner && !filtering" class="mt-4 rounded-card border border-dashed border-line-strong p-8 text-center">
            <p class="font-semibold text-neutral-900">{{ t('team.empty.title') }}</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-neutral-600">{{ t('team.empty.text') }}</p>
            <BaseButton class="mt-4" @click="openAdd">{{ t('team.add') }}</BaseButton>
        </div>

        <!-- Ajout / modification -->
        <TeamMemberDialog
            v-if="dialog"
            :open="dialog !== null"
            :mode="dialog.mode"
            :member="dialog.member"
            :catalog="catalog"
            :presets="presets"
            :grantable="grantable"
            :is-owner="is_owner"
            :loading="saving"
            :error="serverError"
            :error-code="errorCode"
            :field-errors="errors"
            @close="dialog = null"
            @submit="submit"
            @open-member="openExisting"
        />

        <!-- Profil du membre -->
        <ModalDialog :open="profile !== null" :title="profile?.name ?? ''" @close="profile = null">
            <template v-if="profile">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :variant="statusVariant(profile.status)">{{ t(`team.status.${profile.status}`) }}</Badge>
                    <Badge v-if="profile.is_owner" variant="info">{{ t('team.owner_badge') }}</Badge>
                    <span class="text-sm text-neutral-700">{{ roleLabel(profile.role) }}</span>
                </div>
                <dl class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.email') }}</dt>
                        <dd class="break-all font-medium">{{ profile.email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.phone') }}</dt>
                        <dd class="font-medium">{{ profile.phone ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.last_activity') }}</dt>
                        <dd class="font-medium">{{ lastSeen(profile) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.created') }}</dt>
                        <dd class="font-medium">{{ profile.created_at ? formatDate(profile.created_at) : '—' }}</dd>
                    </div>
                    <div v-if="profile.invited_at">
                        <dt class="text-xs text-neutral-600">{{ t('team.columns.invited') }}</dt>
                        <dd class="font-medium">{{ formatDateTime(profile.invited_at) }}</dd>
                    </div>
                    <div v-if="profile.notes" class="sm:col-span-2">
                        <dt class="text-xs text-neutral-600">{{ t('team.fields.notes') }}</dt>
                        <dd class="whitespace-pre-wrap">{{ profile.notes }}</dd>
                    </div>
                </dl>

                <h3 class="mt-5 text-sm font-semibold text-neutral-900">{{ t('team.profile.access') }}</h3>
                <p class="text-sm text-neutral-600">
                    {{
                        profile.is_owner
                            ? t('team.profile.owner_full')
                            : t('team.summary.count', { on: profile.capabilities.length, total: Object.values(catalog.groups).flat().length })
                    }}
                </p>
                <ul v-if="!profile.is_owner" class="mt-2 grid gap-x-6 gap-y-0.5 text-sm text-neutral-800 sm:grid-cols-2">
                    <li
                        v-for="key in Object.values(catalog.groups)
                            .flat()
                            .filter((k) => profile?.capabilities.includes(k))"
                        :key="key"
                        class="flex gap-2"
                    >
                        <span aria-hidden="true">✓</span>{{ t(`team.caps.${key}.label`) }}
                    </li>
                </ul>

                <div v-if="profile.editable" class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                    <BaseButton v-if="profile.kind === 'invitation' || profile.status !== 'removed'" variant="secondary" @click="openEdit(profile)">{{
                        t('team.actions.edit')
                    }}</BaseButton>
                    <BaseButton
                        v-for="type in actionsFor(profile)"
                        :key="type"
                        :variant="type === 'remove' || type === 'cancel' ? 'danger' : 'secondary'"
                        @click="ask(type, profile)"
                        >{{ actionLabel(type) }}</BaseButton
                    >
                </div>
                <p v-else-if="!profile.is_owner && !profile.is_self" class="mt-5 border-t border-line pt-4 text-sm text-neutral-600">
                    {{ t('team.profile.not_editable') }}
                </p>
                <p v-else-if="profile.is_owner" class="mt-5 border-t border-line pt-4 text-sm text-neutral-600">{{ t('team.profile.owner_protected') }}</p>
            </template>
        </ModalDialog>

        <!-- Confirmation d'une action de statut -->
        <ModalDialog :open="action !== null" :title="action ? t(`team.confirm.${action.type}.title`, { name: action.member.name }) : ''" @close="action = null">
            <template v-if="action">
                <p class="text-sm text-neutral-700">{{ t(`team.confirm.${action.type}.body`, { name: action.member.name }) }}</p>
                <AlertBox v-if="action.type === 'remove'" variant="warning" class="mt-3">
                    <p>{{ t('team.confirm.remove.history') }}</p>
                    <p v-if="(action.member.upcoming_appointments ?? 0) > 0" class="mt-1 font-semibold">
                        {{ t('team.confirm.remove.upcoming', { count: action.member.upcoming_appointments ?? 0 }) }}
                    </p>
                </AlertBox>
                <AlertBox v-if="action.type === 'suspend'" variant="info" class="mt-3">{{ t('team.confirm.suspend.history') }}</AlertBox>
                <div v-if="withReason" class="mt-3">
                    <TextareaField v-model="reason" :label="t('team.confirm.reason')" :hint="t('team.confirm.reason_hint')" :rows="2" :maxlength="300" />
                </div>
                <AlertBox v-if="serverError && action" variant="error" class="mt-3">{{ serverError }}</AlertBox>
                <div class="mt-5 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <BaseButton variant="secondary" @click="action = null">{{ t('common.cancel') }}</BaseButton>
                    <BaseButton
                        :variant="['remove', 'cancel', 'suspend'].includes(action.type) ? 'danger' : 'primary'"
                        :loading="acting"
                        @click="confirmAction"
                        >{{ actionLabel(action.type) }}</BaseButton
                    >
                </div>
            </template>
        </ModalDialog>
    </SettingsLayout>
</template>
