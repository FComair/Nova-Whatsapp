<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { focusAnchor } from '../composables/settingsFocus';
import { settingsGroups } from '../composables/settingsNav';
import {
    highlight,
    MIN_QUERY_LENGTH,
    normalize,
    searchEntries,
    type SearchEntry,
    type SearchLocale,
    type SearchResult,
    type Segment,
} from '../composables/settingsSearch';
import { useT } from '../composables/useT';
import { routes } from '../routes';
import type { SharedProps } from '../types';

const POPULAR = ['calendars', 'security-two-factor', 'profile-company', 'whatsapp-connection'];
const RECENT_KEY = 'nova.settingsSearch.recent';
const DEBOUNCE_MS = 150;

type Status = 'idle' | 'loading' | 'ready' | 'error';

interface Item {
    entry: SearchEntry;
    result: SearchResult | null;
}

// L'index est gardé en mémoire pour toute la visite : un seul chargement, puis la recherche se fait sans réseau.
let cache: { key: string; entries: SearchEntry[] } | null = null;

const page = usePage<SharedProps>();
const { t, locale } = useT();
const uid = useId();
const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);

const query = ref('');
const settled = ref('');
const open = ref(false);
const status = ref<Status>('idle');
const entries = ref<SearchEntry[]>([]);
const active = ref(-1);
const recent = ref<string[]>(readRecent());

const uiLocale = computed<SearchLocale>(() => (['fr', 'nl', 'en'].includes(locale.value) ? (locale.value as SearchLocale) : 'en'));
const typed = computed(() => normalize(settled.value).length >= MIN_QUERY_LENGTH);
const cacheKey = computed(() => `${page.props.auth.organization?.id ?? ''}:${page.props.auth.organization?.role ?? ''}`);

/** Libellé de chaque page du menu, pour le chemin « Groupe → Page ». */
const pageLabels = computed(() => {
    const map = new Map<string, string>();

    for (const group of settingsGroups('owner')) {
        for (const item of group.items) {
            map.set(item.key, item.label);
        }
    }

    return map;
});

const results = computed<SearchResult[]>(() => (status.value === 'ready' && typed.value ? searchEntries(entries.value, settled.value, uiLocale.value) : []));

const suggestions = computed<SearchEntry[]>(() =>
    POPULAR.map((id) => entries.value.find((entry) => entry.id === id)).filter((entry): entry is SearchEntry => entry !== undefined),
);

const items = computed<Item[]>(() =>
    typed.value ? results.value.map((result) => ({ entry: result.entry, result })) : suggestions.value.map((entry) => ({ entry, result: null })),
);

const mode = computed<'loading' | 'error' | 'results' | 'none' | 'suggestions'>(() => {
    if (status.value === 'loading') {
        return 'loading';
    }

    if (status.value === 'error') {
        return 'error';
    }

    if (typed.value) {
        return results.value.length > 0 ? 'results' : 'none';
    }

    return 'suggestions';
});

const announcement = computed(() => {
    if (!open.value) {
        return '';
    }

    if (mode.value === 'loading') {
        return t('settings_search.loading');
    }

    if (mode.value === 'error') {
        return t('settings_search.error_title');
    }

    if (mode.value === 'none') {
        return t('settings_search.none_title', { query: settled.value });
    }

    return mode.value === 'results' ? count(results.value.length) : '';
});

function count(total: number): string {
    return total === 0 ? t('settings_search.count_none') : total === 1 ? t('settings_search.count_one') : t('settings_search.count_many', { count: total });
}

function readRecent(): string[] {
    try {
        const parsed: unknown = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string').slice(0, 5) : [];
    } catch {
        return [];
    }
}

function remember(value: string): void {
    const clean = value.trim();

    if (normalize(clean).length < MIN_QUERY_LENGTH) {
        return;
    }

    recent.value = [clean, ...recent.value.filter((entry) => entry.toLowerCase() !== clean.toLowerCase())].slice(0, 5);

    try {
        localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value));
    } catch {
        // Stockage indisponible (navigation privée, par exemple) : l'historique est simplement perdu.
    }
}

async function loadIndex(): Promise<void> {
    if (cache !== null && cache.key === cacheKey.value) {
        entries.value = cache.entries;
        status.value = 'ready';

        return;
    }

    status.value = 'loading';

    try {
        const response = await fetch(routes.settingsSearch, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const body = (await response.json()) as { entries: SearchEntry[] };
        cache = { key: cacheKey.value, entries: body.entries };
        entries.value = body.entries;
        status.value = 'ready';
    } catch {
        status.value = 'error';
    }
}

function show(): void {
    open.value = true;

    if (status.value === 'idle' || status.value === 'error') {
        void loadIndex();
    }
}

function close(): void {
    open.value = false;
    active.value = -1;
}

function clear(): void {
    query.value = '';
    settled.value = '';
    active.value = -1;
    input.value?.focus();
}

let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    clearTimeout(timer);
    active.value = -1;

    if (!open.value && value !== '') {
        show();
    }

    timer = setTimeout(() => (settled.value = value), DEBOUNCE_MS);
});

watch(items, () => (active.value = -1));

function optionId(index: number): string {
    return `${uid}-option-${index}`;
}

function choose(item: Item): void {
    remember(typed.value ? settled.value : query.value);
    close();

    const target = item.entry.anchor === null ? item.entry.href : `${item.entry.href}#${item.entry.anchor}`;
    router.visit(target, { onFinish: () => void focusAnchor(item.entry.anchor) });
}

function useRecent(value: string): void {
    query.value = value;
    settled.value = value;
    void nextTick(() => input.value?.focus());
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (open.value) {
            event.preventDefault();
            event.stopPropagation();
            close();
        }

        return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();

        if (!open.value) {
            show();

            return;
        }

        const total = items.value.length;

        if (total > 0) {
            const step = event.key === 'ArrowDown' ? 1 : -1;
            active.value = (active.value + step + total) % total;
        }

        return;
    }

    if (event.key === 'Enter' && open.value) {
        const item = items.value[active.value] ?? (typed.value ? items.value[0] : undefined);

        if (item !== undefined) {
            event.preventDefault();
            choose(item);
        }
    }
}

function onGlobalKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const editing = target !== null && (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable);

    if (((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') || (event.key === '/' && !editing && !event.ctrlKey && !event.metaKey)) {
        event.preventDefault();
        input.value?.focus();
        input.value?.select();
        show();
    }
}

function onPointerDown(event: PointerEvent): void {
    if (open.value && root.value !== null && !root.value.contains(event.target as Node)) {
        close();
    }
}

function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (next !== null && root.value !== null && !root.value.contains(next)) {
        close();
    }
}

onMounted(() => {
    window.addEventListener('keydown', onGlobalKey);
    document.addEventListener('pointerdown', onPointerDown);
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('keydown', onGlobalKey);
    document.removeEventListener('pointerdown', onPointerDown);
});

function path(entry: SearchEntry): string {
    const label = pageLabels.value.get(entry.page);

    return [t(`settings.groups.${entry.group}`), label === undefined ? null : t(label)].filter((part) => part !== null).join(' → ');
}

function title(entry: SearchEntry): string {
    return entry.title[uiLocale.value];
}

function segments(text: string): Segment[] {
    return highlight(text, settled.value);
}

function tagLabel(result: SearchResult): string {
    return result.locale.toUpperCase();
}

/** Titres de groupe : affiché quand le groupe change par rapport au résultat précédent. */
function groupStart(index: number): string | null {
    const current = items.value[index]?.entry.group ?? null;
    const previous = index > 0 ? (items.value[index - 1]?.entry.group ?? null) : null;

    return current !== null && current !== previous && typed.value ? current : null;
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onFocusOut">
        <label :for="`${uid}-input`" class="sr-only">{{ t('settings_search.label') }}</label>
        <div
            class="flex min-h-11 items-center gap-2 rounded-control border bg-neutral-50 px-3 text-sm focus-within:border-primary-600 focus-within:bg-surface focus-within:ring-2 focus-within:ring-primary-600/30"
            :class="open ? 'border-primary-600 bg-surface' : 'border-line-strong'"
        >
            <svg
                class="size-[18px] shrink-0 text-neutral-500"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
            <input
                :id="`${uid}-input`"
                ref="input"
                v-model="query"
                type="search"
                role="combobox"
                autocomplete="off"
                spellcheck="false"
                class="min-w-0 flex-1 appearance-none border-0 bg-transparent p-0 text-sm text-neutral-900 placeholder:text-neutral-500 focus:outline-none focus:ring-0 [&::-webkit-search-cancel-button]:hidden"
                :placeholder="t('settings_search.placeholder')"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="`${uid}-list`"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                @focus="show"
                @click="show"
                @keydown="onKeydown"
            />
            <button
                v-if="query !== ''"
                type="button"
                class="flex size-6 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-neutral-600 hover:bg-neutral-300"
                :aria-label="t('settings_search.clear')"
                @click="clear"
            >
                <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
            <kbd
                v-else-if="!open"
                class="hidden shrink-0 rounded-md border border-line-strong bg-surface px-1.5 py-0.5 text-[11px] font-semibold text-neutral-500 lg:inline"
                >{{ t('settings_search.shortcut') }}</kbd
            >
            <svg v-if="mode === 'loading' && open" class="size-4 shrink-0 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3" />
                <path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
        </div>

        <p class="sr-only" role="status" aria-live="polite">{{ announcement }}</p>

        <div
            v-if="open"
            class="absolute left-0 top-[calc(100%+0.5rem)] z-40 w-[min(30rem,calc(100vw-2rem))] overflow-hidden rounded-card border border-line bg-surface shadow-xs shadow-xl"
        >
            <!-- Chargement de l'index -->
            <div v-if="mode === 'loading'" class="px-4 py-3" aria-hidden="true">
                <div v-for="row in 3" :key="row" class="flex animate-pulse gap-3 py-2">
                    <span class="size-8 shrink-0 rounded-control bg-neutral-200" />
                    <span class="flex-1 space-y-2"
                        ><span class="block h-3 w-1/2 rounded bg-neutral-200" /><span class="block h-2.5 w-2/3 rounded bg-neutral-200"
                    /></span>
                </div>
                <p class="mt-1 text-xs text-neutral-600">{{ t('settings_search.loading') }}</p>
            </div>

            <!-- Erreur -->
            <div v-else-if="mode === 'error'" class="bg-warning-50 px-6 py-6 text-center text-warning-600" role="alert">
                <p class="font-semibold">{{ t('settings_search.error_title') }}</p>
                <p class="mt-1 text-sm">{{ t('settings_search.error_body') }}</p>
                <button
                    type="button"
                    class="mt-3 min-h-10 rounded-control border border-line-strong bg-surface px-4 text-sm font-semibold text-neutral-900 hover:bg-surface-2"
                    @click="loadIndex"
                >
                    {{ t('settings_search.retry') }}
                </button>
            </div>

            <!-- Aucun résultat -->
            <div v-else-if="mode === 'none'" class="px-6 py-6 text-center">
                <p class="font-semibold text-neutral-900">{{ t('settings_search.none_title', { query: settled }) }}</p>
                <p class="mt-1 text-sm text-neutral-600">{{ t('settings_search.none_body') }}</p>
                <p class="mt-3 text-xs text-neutral-500">{{ t('settings_search.languages') }}</p>
            </div>

            <!-- Résultats ou suggestions -->
            <template v-else>
                <ul
                    :id="`${uid}-list`"
                    role="listbox"
                    :aria-label="typed ? t('settings_search.results') : t('settings_search.suggestions')"
                    class="max-h-[22rem] overflow-y-auto py-1"
                >
                    <template v-for="(item, index) in items" :key="item.entry.id">
                        <li
                            v-if="index === 0 && !typed"
                            role="presentation"
                            class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-neutral-600"
                        >
                            {{ t('settings_search.popular') }}
                        </li>
                        <li
                            v-if="groupStart(index)"
                            role="presentation"
                            class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-neutral-600"
                        >
                            {{ t(`settings.groups.${groupStart(index)}`) }}
                        </li>
                        <li
                            :id="optionId(index)"
                            role="option"
                            :aria-selected="index === active"
                            class="flex min-h-11 cursor-pointer items-start gap-3 px-4 py-2"
                            :class="index === active ? 'bg-primary-50' : 'hover:bg-surface-2'"
                            @mousedown.prevent
                            @mousemove="active = index"
                            @click="choose(item)"
                        >
                            <span
                                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-control"
                                :class="index === active ? 'bg-surface text-primary-700' : 'bg-neutral-100 text-neutral-600'"
                            >
                                <svg
                                    class="size-[18px]"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    aria-hidden="true"
                                >
                                    <template v-if="item.entry.group === 'setup'">
                                        <path d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2" />
                                    </template>
                                    <template v-else-if="item.entry.group === 'account'">
                                        <circle cx="12" cy="8" r="4" />
                                        <path d="M4 21c.8-4 4-6 8-6s7.2 2 8 6" />
                                    </template>
                                    <template v-else-if="item.entry.group === 'integrations'">
                                        <rect x="3" y="5" width="18" height="16" rx="3" />
                                        <path d="M8 3v4M16 3v4M3 10h18" />
                                    </template>
                                    <template v-else>
                                        <rect x="5" y="11" width="14" height="10" rx="2.5" />
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                                    </template>
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-neutral-900">
                                    <template
                                        v-for="(part, partIndex) in typed ? segments(title(item.entry)) : [{ text: title(item.entry), hit: false }]"
                                        :key="partIndex"
                                    >
                                        <mark v-if="part.hit" class="rounded-sm bg-amber-200 px-px text-neutral-900">{{ part.text }}</mark>
                                        <template v-else>{{ part.text }}</template>
                                    </template>
                                    <span
                                        v-if="item.result?.matchedWord"
                                        class="ml-1.5 inline-block rounded bg-neutral-100 px-1.5 py-px align-middle text-[10.5px] font-bold tracking-wide text-neutral-600"
                                    >
                                        {{ tagLabel(item.result) }} ·
                                        <template v-for="(part, partIndex) in segments(item.result.matchedWord)" :key="partIndex">
                                            <mark v-if="part.hit" class="rounded-sm bg-amber-200 px-px text-neutral-900">{{ part.text }}</mark>
                                            <template v-else>{{ part.text }}</template>
                                        </template>
                                    </span>
                                </span>
                                <span class="mt-0.5 block text-xs text-neutral-600">{{ path(item.entry) }}</span>
                            </span>
                            <svg
                                v-if="index === active"
                                class="mt-2 size-[18px] shrink-0 self-center text-primary-700"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M20 6v6a2 2 0 0 1-2 2H5M9 10l-4 4 4 4" />
                            </svg>
                        </li>
                    </template>
                </ul>

                <div v-if="!typed && recent.length > 0" class="border-t border-line py-1">
                    <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-neutral-600">{{ t('settings_search.recent') }}</p>
                    <button
                        v-for="value in recent"
                        :key="value"
                        type="button"
                        class="flex min-h-10 w-full items-center gap-3 px-4 text-left text-sm text-neutral-800 hover:bg-surface-2"
                        @mousedown.prevent
                        @click="useRecent(value)"
                    >
                        <svg
                            class="size-4 shrink-0 text-neutral-500"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            stroke-linecap="round"
                            aria-hidden="true"
                        >
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7v5l3 2" />
                        </svg>
                        {{ value }}
                    </button>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-line bg-neutral-50 px-4 py-2 text-xs text-neutral-600">
                    <span>{{ typed ? count(results.length) : t('settings_search.languages') }}</span>
                    <span class="hidden items-center gap-3 sm:flex">
                        <span
                            ><kbd class="rounded border border-line-strong bg-surface px-1">↑</kbd>
                            <kbd class="rounded border border-line-strong bg-surface px-1">↓</kbd> {{ t('settings_search.navigate') }}</span
                        >
                        <span><kbd class="rounded border border-line-strong bg-surface px-1">↵</kbd> {{ t('settings_search.open') }}</span>
                    </span>
                </div>
            </template>
        </div>
    </div>
</template>
