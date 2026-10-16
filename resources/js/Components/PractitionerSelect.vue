<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import PractitionerAvatar from './PractitionerAvatar.vue';

/**
 * Liste de choix d'un praticien avec sa photo (ou ses initiales) devant chaque nom. Même apparence et mêmes états qu'un champ de
 * sélection du produit ; une liste native ne pouvant pas afficher d'image, c'est une liste déroulante accessible : flèches,
 * Début, Fin, Entrée, Espace, Échap, saisie de la première lettre. Une option sans praticien (« Tous ») s'affiche sans avatar.
 */
export interface PractitionerOption {
    value: string;
    label: string;
    /** Version de la photo (null : initiales) ; absent pour une option qui n'est pas un praticien (« Tous »). */
    photo_version?: number | null;
    /** Pas d'avatar : option du type « Tous les praticiens ». */
    plain?: boolean;
}

const props = defineProps<{
    label: string;
    options: PractitionerOption[];
    placeholder?: string;
    /** Texte quand la liste est vide : elle ne s'ouvre jamais « à blanc ». */
    emptyLabel?: string;
    error?: string;
    required?: boolean;
}>();

const model = defineModel<string>({ required: true });

const id = useId();
const listId = `${id}-list`;
const errorId = `${id}-error`;
const root = ref<HTMLElement | null>(null);
const open = ref(false);
const active = ref(-1);

const selected = computed(() => props.options.find((option) => option.value === model.value) ?? null);
const empty = computed(() => props.options.length === 0);
const optionId = (position: number): string => `${id}-option-${position}`;

function show(): void {
    if (empty.value) {
        return;
    }

    open.value = true;
    active.value = Math.max(
        0,
        props.options.findIndex((option) => option.value === model.value),
    );
}

function close(): void {
    open.value = false;
    active.value = -1;
}

function choose(position: number): void {
    model.value = props.options[position]!.value;
    close();
}

function move(step: number): void {
    if (!open.value) {
        show();

        return;
    }

    active.value = (active.value + step + props.options.length) % props.options.length;
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        show();
        active.value = event.key === 'Home' ? 0 : props.options.length - 1;
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();

        if (open.value && active.value >= 0) {
            choose(active.value);
        } else {
            show();
        }
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close();
    } else if (event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
        // Saisie de la première lettre : saute au premier nom qui commence ainsi.
        const found = props.options.findIndex((option) => option.label.toLowerCase().startsWith(event.key.toLowerCase()));

        if (found >= 0) {
            show();
            active.value = found;
        }
    }
}

function onOutside(event: MouseEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
}

onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <div ref="root" class="relative">
        <label :id="`${id}-label`" :for="id" class="mb-1.5 block text-sm font-semibold text-neutral-900">
            {{ label }}<span v-if="required" class="text-danger-600" aria-hidden="true"> *</span>
        </label>

        <button
            :id="id"
            type="button"
            role="combobox"
            aria-haspopup="listbox"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-labelledby="`${id}-label ${id}`"
            :aria-activedescendant="open && active >= 0 ? optionId(active) : undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? errorId : undefined"
            class="flex min-h-[46px] w-full items-center gap-2.5 rounded-control border-[1.5px] bg-surface px-3.5 py-2 text-left text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-2"
            :class="
                error
                    ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                    : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
            "
            @click="open ? close() : show()"
            @keydown="onKey"
        >
            <template v-if="selected">
                <PractitionerAvatar v-if="!selected.plain" :id="selected.value" :name="selected.label" :version="selected.photo_version ?? null" size="sm" />
                <span class="min-w-0 flex-1 truncate">{{ selected.label }}</span>
            </template>
            <span v-else class="min-w-0 flex-1 truncate text-neutral-600">{{ empty ? (emptyLabel ?? placeholder) : placeholder }}</span>
            <svg
                class="size-4 shrink-0 text-neutral-700 transition-transform"
                :class="{ 'rotate-180': open }"
                viewBox="0 0 16 16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="m4 6 4 4 4-4" />
            </svg>
        </button>

        <ul
            v-show="open"
            :id="listId"
            role="listbox"
            :aria-labelledby="`${id}-label`"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-auto rounded-card border border-line bg-surface py-1 shadow-md"
        >
            <li
                v-for="(option, position) in options"
                :id="optionId(position)"
                :key="option.value"
                role="option"
                :aria-selected="option.value === model"
                class="flex min-h-11 cursor-pointer items-center gap-3 px-3.5 py-2 text-base text-neutral-900"
                :class="active === position ? 'bg-primary-50' : 'hover:bg-surface-2'"
                @mousedown.prevent="choose(position)"
                @mousemove="active = position"
            >
                <PractitionerAvatar v-if="!option.plain" :id="option.value" :name="option.label" :version="option.photo_version ?? null" size="sm" />
                <span class="min-w-0 flex-1 truncate" :class="{ 'font-semibold': option.value === model }">{{ option.label }}</span>
                <svg
                    v-if="option.value === model"
                    class="size-4 shrink-0 text-primary-600"
                    viewBox="0 0 16 16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.9"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="m3.5 8.5 3 3 6-6.5" />
                </svg>
            </li>
        </ul>

        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
