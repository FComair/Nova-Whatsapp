<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { fold } from '../composables/helpSearch';
import { useT } from '../composables/useT';

/**
 * Liste déroulante des catégories de services : s'ouvre au clic ou au clavier avec toute la liste de la langue de l'interface
 * (jamais un mélange de langues), se filtre à la frappe et propose de créer une catégorie nouvelle. La valeur reste un texte :
 * la catégorie est retrouvée ou créée par son nom à l'enregistrement du service.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        hint?: string;
        /** Catégories de la langue de l'interface. */
        options: string[];
        error?: string;
        maxlength?: number;
        disabled?: boolean;
    }>(),
    { hint: undefined, error: undefined, maxlength: 60, disabled: false },
);

const model = defineModel<string>({ required: true });
const { t, locale } = useT();

const id = useId();
const listId = `${id}-list`;
const hintId = `${id}-hint`;
const errorId = `${id}-error`;
const open = ref(false);
const active = ref(-1);
const filtering = ref(false);
const input = ref<HTMLInputElement | null>(null);

const language = computed(() => new Intl.DisplayNames([locale.value], { type: 'language' }).of(locale.value) ?? locale.value);
const typed = computed(() => model.value.trim());

/** Toute la liste à l'ouverture ; filtrée dès que la personne tape. */
const visible = computed(() => {
    const needle = fold(typed.value);

    return filtering.value && needle !== '' ? props.options.filter((option) => fold(option).includes(needle)) : props.options;
});
const canCreate = computed(() => typed.value !== '' && !props.options.some((option) => fold(option) === fold(typed.value)));
const rows = computed(() => visible.value.length + (canCreate.value ? 1 : 0));
const describedBy = computed(() => [props.hint ? hintId : null, props.error ? errorId : null].filter(Boolean).join(' ') || undefined);
const optionId = (position: number): string => `${id}-option-${position}`;

function show(): void {
    if (!props.disabled) {
        open.value = true;
    }
}

function close(): void {
    open.value = false;
    active.value = -1;
    filtering.value = false;
}

function choose(value: string): void {
    model.value = value;
    close();
}

function toggle(): void {
    if (open.value) {
        close();
    } else {
        input.value?.focus();
        show();
    }
}

function onInput(): void {
    filtering.value = true;
    active.value = -1;
    show();
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        show();

        if (rows.value > 0) {
            active.value = event.key === 'ArrowDown' ? (active.value + 1) % rows.value : active.value <= 0 ? rows.value - 1 : active.value - 1;
        }
    } else if (event.key === 'Enter' && open.value && active.value >= 0) {
        event.preventDefault();
        choose(active.value < visible.value.length ? visible.value[active.value]! : typed.value);
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close();
    }
}

function onBlur(): void {
    // Laisse un clic sur une option aboutir avant de refermer la liste.
    window.setTimeout(close, 120);
}
</script>

<template>
    <div class="relative">
        <label :for="id" class="mb-1.5 block text-sm font-semibold text-neutral-900">{{ label }}</label>

        <div class="relative">
            <input
                :id="id"
                ref="input"
                v-model="model"
                type="text"
                role="combobox"
                autocomplete="off"
                :maxlength="maxlength"
                :disabled="disabled"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                aria-autocomplete="list"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
                class="block w-full rounded-control border-[1.5px] bg-surface py-2.5 pl-3.5 pr-12 text-base text-neutral-900 transition duration-150 ease-nova focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:bg-neutral-100"
                :class="
                    error
                        ? 'border-danger-fg focus:border-danger-fg focus:ring-danger-fg/20'
                        : 'border-line-strong hover:border-neutral-500 focus:border-primary-600 focus:ring-primary-600/20'
                "
                @input="onInput"
                @focus="show"
                @click="show"
                @keydown="onKey"
                @blur="onBlur"
            />
            <button
                type="button"
                tabindex="-1"
                class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-control text-neutral-700 hover:text-primary-700 disabled:cursor-not-allowed"
                :disabled="disabled"
                :aria-label="t('services.fields.category_open')"
                @mousedown.prevent
                @click="toggle"
            >
                <svg
                    class="size-4 transition-transform"
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
        </div>

        <ul
            v-show="open"
            :id="listId"
            role="listbox"
            :aria-label="t('services.fields.category_list', { language })"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-auto rounded-card border border-line bg-surface py-1 shadow-md"
        >
            <li role="presentation" class="px-3.5 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-neutral-600">
                {{ t('services.fields.category_list', { language }) }}
            </li>

            <li
                v-for="(option, position) in visible"
                :id="optionId(position)"
                :key="option"
                role="option"
                :aria-selected="option === model"
                class="flex min-h-11 cursor-pointer items-center px-3.5 py-2 text-base text-neutral-900"
                :class="active === position ? 'bg-primary-50' : 'hover:bg-surface-2'"
                @mousedown.prevent="choose(option)"
            >
                <span class="min-w-0 flex-1 truncate" :class="{ 'font-semibold': option === model }">{{ option }}</span>
                <svg
                    v-if="option === model"
                    class="ml-2 size-4 shrink-0 text-primary-600"
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

            <li
                v-if="canCreate"
                :id="optionId(visible.length)"
                role="option"
                :aria-selected="false"
                class="flex min-h-11 cursor-pointer items-center border-t border-line px-3.5 py-2 text-base font-semibold text-primary-700"
                :class="active === visible.length ? 'bg-primary-50' : 'hover:bg-surface-2'"
                @mousedown.prevent="choose(typed)"
            >
                {{ t('services.fields.category_create', { name: typed }) }}
            </li>

            <li v-if="!options.length && !canCreate" role="presentation" class="px-3.5 py-3 text-sm text-neutral-700">
                <span class="block font-semibold">{{ t('services.fields.category_empty') }}</span>
                <span class="block">{{ t('services.fields.category_empty_hint') }}</span>
            </li>
            <li v-else-if="options.length && !visible.length && !canCreate" role="presentation" class="px-3.5 py-3 text-sm text-neutral-700">
                {{ t('services.fields.category_none') }}
            </li>
        </ul>

        <p v-if="hint" :id="hintId" class="mt-1.5 text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="errorId" class="mt-1.5 text-sm font-medium text-danger-fg" role="alert">{{ error }}</p>
    </div>
</template>
