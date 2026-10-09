<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import { extraError } from '../composables/planning';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { PlanningBooking, PlanningPractitioner } from '../types';
import BaseButton from './BaseButton.vue';
import PractitionerAvatar from './PractitionerAvatar.vue';
import PractitionerSelect from './PractitionerSelect.vue';
import TextField from './TextField.vue';

/**
 * Praticien d'un rendez-vous, dans la fenêtre de détail : affichage simple, et « Modifier » ouvre sur place la liste des
 * praticiens qui réalisent ce service. Le serveur revérifie tout (rôle, service, disponibilité du créneau).
 */
const props = defineProps<{
    booking: PlanningBooking;
    /** Praticiens actifs du site qui réalisent le service du rendez-vous. */
    eligible: PlanningPractitioner[];
    canChange: boolean;
    /** Version de la photo du praticien actuel (null : initiales). */
    photoVersion?: number | null;
}>();
const emit = defineEmits<{ saved: [] }>();

const { t } = useT();
const editing = ref(false);
const query = ref('');
const saved = ref(false);
const form = useForm({ practitioner_id: props.booking.practitioner_id });
const changeButton = ref<InstanceType<typeof BaseButton> | null>(null);
const select = ref<HTMLElement | null>(null);
let hideTimer: ReturnType<typeof setTimeout> | undefined;

const name = computed(() => props.booking.practitioner_name);
const SEARCH_FROM = 8;
const searchable = computed(() => props.eligible.length > SEARCH_FROM);
const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();
    const list = needle === '' ? props.eligible : props.eligible.filter((practitioner) => practitioner.name.toLowerCase().includes(needle));

    // Le praticien choisi reste dans la liste même si la recherche ne le trouve plus.
    const chosen = props.eligible.find((practitioner) => practitioner.id === form.practitioner_id);

    return chosen && !list.includes(chosen) ? [chosen, ...list] : list;
});
const options = computed(() =>
    filtered.value.map((practitioner) => ({ value: practitioner.id, label: practitioner.name, photo_version: practitioner.photo_version })),
);
// Créneau déjà pris ou règle de réservation refusée : le serveur répond par `slot` ou `booking`.
const error = computed(() => extraError(form.errors, 'slot') ?? extraError(form.errors, 'booking'));
const unchanged = computed(() => form.practitioner_id === props.booking.practitioner_id);

async function edit(): Promise<void> {
    form.practitioner_id = props.booking.practitioner_id;
    form.clearErrors();
    query.value = '';
    saved.value = false;
    editing.value = true;
    await nextTick();
    select.value?.querySelector('button')?.focus();
}

async function close(): Promise<void> {
    editing.value = false;
    form.practitioner_id = props.booking.practitioner_id;
    form.clearErrors();
    await nextTick();
    (changeButton.value?.$el as HTMLElement | undefined)?.focus();
}

function save(): void {
    form.put(`${routes.planning}/rendez-vous/${props.booking.id}/praticien`, {
        preserveScroll: true,
        onSuccess: async () => {
            saved.value = true;
            emit('saved');
            await close();
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => (saved.value = false), 4000);
        },
    });
}

onBeforeUnmount(() => clearTimeout(hideTimer));
</script>

<template>
    <div>
        <div v-if="!editing" class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="inline-flex items-center gap-2" :class="{ 'text-neutral-700': !name }">
                <PractitionerAvatar v-if="name" :id="booking.practitioner_id" :name="name" :version="photoVersion ?? null" size="sm" />
                <span>{{ name ?? t('planning.reassign.none') }}</span>
            </span>
            <BaseButton
                v-if="canChange"
                ref="changeButton"
                variant="ghost"
                size="sm"
                :aria-label="name ? t('planning.reassign.current', { name }) : t('planning.reassign.change_label')"
                @click="edit"
            >
                <svg
                    class="size-4"
                    viewBox="0 0 16 16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.75"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m10.5 2.8 2.7 2.7M2.5 13.5l.6-2.9 7.4-7.4 2.3 2.3-7.4 7.4-2.9.6Z" />
                </svg>
                {{ t('planning.reassign.change') }}
            </BaseButton>
        </div>

        <form v-else class="mt-1 space-y-3" novalidate @submit.prevent="save">
            <p v-if="!eligible.length" class="rounded-control bg-warning-bg px-3 py-2 text-sm text-warning-fg" role="alert">
                {{ t('planning.reassign.none_available') }}
            </p>
            <template v-else>
                <TextField v-if="searchable" v-model="query" :label="t('planning.reassign.search')" autocomplete="off" />
                <div ref="select">
                    <PractitionerSelect
                        v-model="form.practitioner_id"
                        :label="t('planning.form.practitioner')"
                        :placeholder="t('planning.reassign.select')"
                        :options="options"
                        :error="form.errors.practitioner_id"
                    />
                </div>
                <p v-if="searchable && query.trim() !== '' && filtered.length <= 1" class="text-sm text-neutral-700" role="status">
                    {{ t('planning.reassign.no_match') }}
                </p>
            </template>
            <div class="flex flex-col gap-2 sm:flex-row">
                <BaseButton v-if="eligible.length" type="submit" size="sm" :loading="form.processing" :disabled="unchanged || !form.practitioner_id">
                    {{ t('planning.reassign.save') }}
                </BaseButton>
                <BaseButton variant="secondary" size="sm" @click="close">{{ t('common.cancel') }}</BaseButton>
            </div>
        </form>

        <p v-if="error" class="mt-2 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
        <p v-if="saved" class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-success-bg px-3 py-1 text-sm font-semibold text-success-fg" role="status">
            <svg
                class="size-4"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.75"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <circle cx="8" cy="8" r="6.25" />
                <path d="m5.3 8.2 1.8 1.8 3.6-3.8" />
            </svg>
            {{ t('planning.reassign.updated') }}
        </p>
    </div>
</template>
