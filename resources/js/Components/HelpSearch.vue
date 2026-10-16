<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, useId } from 'vue';
import { type HelpEntry, MIN_QUERY, searchHelp } from '../composables/helpSearch';
import { useHelpCategories } from '../composables/helpCategories';
import { useT } from '../composables/useT';
import { routes } from '../routes';

/**
 * Zone de recherche du centre d'aide avec suggestions pendant la frappe (liste déroulante accessible : flèches, Entrée, Échap).
 * Entrée sans suggestion choisie ouvre la page de résultats.
 */
const props = defineProps<{ index: HelpEntry[]; initial?: string; autofocus?: boolean }>();

const { t } = useT();
const id = useId();
const query = ref(props.initial ?? '');
const open = ref(false);
const active = ref(-1);

const suggestions = computed(() => (open.value ? searchHelp(props.index, query.value, 6) : []));
const showList = computed(() => open.value && query.value.trim().length >= MIN_QUERY);
const rows = computed(() => suggestions.value.length + 1);

const { categoryName } = useHelpCategories();
const optionId = (position: number): string => `${id}-option-${position}`;

function go(entry: HelpEntry): void {
    open.value = false;
    router.visit(`${routes.help}/${entry.category}/${entry.slug}`);
}

function results(): void {
    open.value = false;
    router.get(`${routes.help}/recherche`, { q: query.value.trim() });
}

function submit(): void {
    const chosen = suggestions.value[active.value];

    if (chosen) {
        go(chosen);
    } else if (query.value.trim().length >= MIN_QUERY) {
        results();
    }
}

function onKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        active.value = (active.value + 1) % rows.value;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = active.value <= 0 ? rows.value - 1 : active.value - 1;
    } else if (event.key === 'Escape') {
        open.value = false;
        active.value = -1;
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (active.value === suggestions.value.length && suggestions.value.length > 0) {
            results();
        } else {
            submit();
        }
    }
}

function onInput(): void {
    open.value = true;
    active.value = -1;
}

function onBlur(): void {
    // Laisse le temps à un clic sur une suggestion d'aboutir avant de refermer la liste.
    window.setTimeout(() => (open.value = false), 150);
}
</script>

<template>
    <div class="relative" role="search">
        <label :for="`${id}-input`" class="sr-only">{{ t('help.search_label') }}</label>
        <div
            class="flex items-center gap-3 rounded-lg border-[1.5px] border-line-strong bg-surface px-5 focus-within:border-primary-600 focus-within:ring-2 focus-within:ring-primary-600/20"
        >
            <svg
                class="size-5 shrink-0 text-neutral-600"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.9"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
            <input
                :id="`${id}-input`"
                v-model="query"
                type="search"
                role="combobox"
                autocomplete="off"
                class="min-h-12 w-full bg-transparent text-base text-neutral-900 placeholder:text-neutral-600 focus:outline-none"
                :placeholder="t('help.search_placeholder')"
                :autofocus="autofocus"
                aria-autocomplete="list"
                :aria-expanded="showList"
                :aria-controls="`${id}-list`"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                @input="onInput"
                @keydown="onKey"
                @focus="open = true"
                @blur="onBlur"
            />
        </div>

        <ul
            v-show="showList"
            :id="`${id}-list`"
            role="listbox"
            :aria-label="t('help.suggestions')"
            class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-card border border-line bg-surface shadow-md"
        >
            <li
                v-for="(entry, position) in suggestions"
                :id="optionId(position)"
                :key="`${entry.category}/${entry.slug}`"
                role="option"
                :aria-selected="active === position"
                class="cursor-pointer px-5 py-3"
                :class="active === position ? 'bg-primary-50' : 'hover:bg-surface-2'"
                @mousedown.prevent="go(entry)"
            >
                <span class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-neutral-900">{{ entry.title }}</span>
                    <span class="rounded-full bg-mint px-2.5 py-0.5 text-xs font-semibold text-primary-700">{{ categoryName(entry.category) }}</span>
                </span>
                <span class="mt-0.5 block truncate text-sm text-neutral-700">{{ entry.summary }}</span>
            </li>
            <li
                v-if="suggestions.length"
                :id="optionId(suggestions.length)"
                role="option"
                :aria-selected="active === suggestions.length"
                class="cursor-pointer border-t border-line px-5 py-3 text-sm font-semibold text-primary-700"
                :class="active === suggestions.length ? 'bg-primary-50' : 'hover:bg-surface-2'"
                @mousedown.prevent="results"
            >
                {{ t('help.see_all', { q: query.trim() }) }}
            </li>
            <li v-else class="px-5 py-3 text-sm text-neutral-700" role="option" aria-selected="false">{{ t('help.no_results_title', { q: query.trim() }) }}</li>
        </ul>
    </div>
</template>
