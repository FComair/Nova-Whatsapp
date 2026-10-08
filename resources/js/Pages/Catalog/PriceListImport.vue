<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { formatDateTime } from '../../composables/dates';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { onboardingStep, routes } from '../../routes';
import type { SharedProps } from '../../types';

interface HistoryItem {
    id: string;
    status: 'imported' | 'undone';
    file_names: string[];
    added: number;
    updated: number;
    ignored: number;
    imported_at: string | null;
    can_undo: boolean;
}

const props = defineProps<{
    available: boolean;
    limits: { maxFiles: number; maxMb: number; minSide: number; perHour: number };
    open: { id: string; file_names: string[]; lines: number } | null;
    history: HistoryItem[];
}>();

interface Page {
    id: number;
    file: File;
    url: string;
}

const { t } = useT();
const page = usePage<SharedProps>();
const errors = computed(() => page.props.errors as Record<string, string | undefined>);
const base = routes.servicesImport;

const pages = ref<Page[]>([]);
const clientError = ref<string | null>(null);
const picker = ref<HTMLInputElement | null>(null);
const camera = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
const toUndo = ref<HistoryItem | null>(null);
const undoing = ref(false);
let counter = 0;

const form = useForm<{ files: File[] }>({ files: [] });
const reason = computed(() => (form.processing ? null : (errors.value.reason ?? null)));
// Une erreur peut porter sur l'ensemble (files) ou sur un fichier précis (files.0, files.1…).
const fileError = computed(() => errors.value.files ?? Object.entries(errors.value).find(([key]) => key.startsWith('files.'))?.[1] ?? null);
const failure = computed(() => reason.value ?? (fileError.value ? 'files' : null));

onBeforeUnmount(() => pages.value.forEach((entry) => URL.revokeObjectURL(entry.url)));

function add(files: FileList | File[] | null): void {
    clientError.value = null;

    for (const file of Array.from(files ?? [])) {
        if (!['image/png', 'image/jpeg'].includes(file.type)) {
            clientError.value = t('price_import.errors.type');
            continue;
        }

        if (file.size > props.limits.maxMb * 1024 * 1024) {
            clientError.value = t('price_import.errors.too_large', { mb: props.limits.maxMb });
            continue;
        }

        if (pages.value.length >= props.limits.maxFiles) {
            clientError.value = t('price_import.errors.too_many', { max: props.limits.maxFiles });
            break;
        }

        pages.value.push({ id: ++counter, file, url: URL.createObjectURL(file) });
    }
}

function choose(event: Event): void {
    const input = event.target as HTMLInputElement;
    add(input.files);
    input.value = '';
}

function drop(event: DragEvent): void {
    dragging.value = false;
    add(event.dataTransfer?.files ?? null);
}

function remove(id: number): void {
    const entry = pages.value.find((candidate) => candidate.id === id);

    if (entry) {
        URL.revokeObjectURL(entry.url);
    }

    pages.value = pages.value.filter((candidate) => candidate.id !== id);
}

function move(index: number, step: -1 | 1): void {
    const target = index + step;

    if (target < 0 || target >= pages.value.length) {
        return;
    }

    const copy = [...pages.value];
    [copy[index], copy[target]] = [copy[target]!, copy[index]!];
    pages.value = copy;
}

function analyse(): void {
    if (pages.value.length === 0) {
        clientError.value = t('price_import.errors.required');

        return;
    }

    form.files = pages.value.map((entry) => entry.file);
    form.post(base, { forceFormData: true });
}

function undo(): void {
    if (toUndo.value === null) {
        return;
    }

    undoing.value = true;
    router.post(
        `${base}/${toUndo.value.id}/undo`,
        {},
        {
            onFinish: () => {
                undoing.value = false;
                toUndo.value = null;
            },
        },
    );
}

function summary(item: HistoryItem): string {
    return t('price_import.history_counts', { added: item.added, updated: item.updated });
}
</script>

<template>
    <AppLayout :title="t('price_import.title')">
        <nav class="mb-3 text-sm text-neutral-600" :aria-label="t('setup.breadcrumb')">
            <Link :href="onboardingStep('services')" class="hover:text-primary-700">{{ t('services.title') }}</Link>
            <span aria-hidden="true"> › </span>
            <span class="font-semibold text-neutral-900" aria-current="page">{{ t('price_import.title') }}</span>
        </nav>

        <h1 class="text-2xl font-semibold text-nova-900">{{ t('price_import.title') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-neutral-700">{{ t('price_import.intro') }}</p>

        <AlertBox v-if="page.props.flash.status" variant="success" class="mt-4 max-w-3xl">{{ page.props.flash.status }}</AlertBox>
        <AlertBox v-if="errors.import" variant="error" class="mt-4 max-w-3xl">{{ errors.import }}</AlertBox>

        <section v-if="!available" class="mt-6 max-w-3xl rounded-card border border-dashed border-neutral-300 bg-white p-8">
            <h2 class="text-lg font-semibold text-nova-900">{{ t('price_import.unavailable_title') }}</h2>
            <p class="mt-2 text-sm text-neutral-700">{{ t('price_import.unavailable_body') }}</p>
        </section>

        <template v-else>
            <AlertBox v-if="open" variant="info" class="mt-4 max-w-3xl">
                <span>{{ t('price_import.resume', { count: open.lines }) }}</span>
                <Link :href="`${base}/${open.id}`" class="ml-2 font-semibold underline">{{ t('price_import.resume_action') }}</Link>
            </AlertBox>

            <!-- Analyse en cours : la lecture se fait pendant l'envoi, la page reste ouverte. -->
            <section
                v-if="form.processing"
                class="mt-6 max-w-3xl rounded-card border border-neutral-200 bg-white p-8 text-center"
                role="status"
                aria-live="polite"
            >
                <svg class="mx-auto size-10 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".2" stroke-width="3" />
                    <path d="M12 3a9 9 0 0 1 9 9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                </svg>
                <h2 class="mt-4 text-lg font-semibold text-nova-900">{{ t('price_import.processing_title') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-neutral-700">{{ t('price_import.processing_body') }}</p>
                <div
                    v-if="form.progress"
                    class="mx-auto mt-4 h-2 max-w-xs overflow-hidden rounded-full bg-primary-50"
                    role="progressbar"
                    :aria-valuenow="form.progress.percentage ?? 0"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div class="h-full rounded-full bg-primary-600" :style="{ width: `${form.progress.percentage ?? 0}%` }" />
                </div>
            </section>

            <div v-else class="mt-6 grid max-w-5xl gap-6 lg:grid-cols-[1fr_20rem]">
                <section class="rounded-card border border-neutral-200 bg-white p-5" aria-labelledby="upload">
                    <h2 id="upload" class="text-lg font-semibold text-nova-900">{{ t('price_import.your_list') }}</h2>

                    <!-- Erreurs de lecture, avec des conseils -->
                    <div v-if="failure" class="mt-4 rounded-lg border border-danger-600/30 bg-danger-50 p-4 text-sm text-danger-700" role="alert">
                        <p class="font-semibold">{{ t(`price_import.fail.${reason ?? 'files'}.title`) }}</p>
                        <p class="mt-1 text-neutral-800">{{ fileError }}</p>
                        <ul v-if="reason === 'unreadable' || reason === 'bad_image'" class="mt-2 list-disc space-y-0.5 pl-5 text-neutral-800">
                            <li>{{ t('price_import.tips.light') }}</li>
                            <li>{{ t('price_import.tips.flat') }}</li>
                            <li>{{ t('price_import.tips.resolution', { px: limits.minSide }) }}</li>
                        </ul>
                    </div>

                    <div
                        class="mt-4 rounded-xl border-2 border-dashed p-8 text-center"
                        :class="dragging ? 'border-primary-600 bg-primary-50' : 'border-neutral-300 bg-neutral-50'"
                        @dragover.prevent="dragging = true"
                        @dragleave="dragging = false"
                        @drop.prevent="drop"
                    >
                        <p class="font-semibold text-neutral-900">{{ t('price_import.drop') }}</p>
                        <p class="mt-1 text-sm text-neutral-600">{{ t('price_import.formats') }}</p>
                        <div class="mt-4 flex flex-wrap justify-center gap-3">
                            <BaseButton @click="picker?.click()">{{ t('price_import.upload') }}</BaseButton>
                            <BaseButton variant="secondary" @click="camera?.click()">{{ t('price_import.take_photo') }}</BaseButton>
                        </div>
                        <input
                            ref="picker"
                            type="file"
                            accept="image/png,image/jpeg"
                            multiple
                            class="sr-only"
                            tabindex="-1"
                            aria-hidden="true"
                            @change="choose"
                        />
                        <input
                            ref="camera"
                            type="file"
                            accept="image/*"
                            capture="environment"
                            class="sr-only"
                            tabindex="-1"
                            aria-hidden="true"
                            @change="choose"
                        />
                    </div>
                    <p class="mt-3 text-sm text-neutral-600">{{ t('price_import.limits', { max: limits.maxFiles, mb: limits.maxMb, px: limits.minSide }) }}</p>
                    <p v-if="clientError" class="mt-2 text-sm font-medium text-danger-700" role="alert">{{ clientError }}</p>

                    <ol v-if="pages.length > 0" class="mt-5 flex flex-wrap gap-4" :aria-label="t('price_import.pages')">
                        <li v-for="(entry, index) in pages" :key="entry.id" class="w-28">
                            <div class="relative">
                                <img
                                    :src="entry.url"
                                    :alt="t('price_import.page_n', { n: index + 1 })"
                                    class="h-36 w-28 rounded-lg border border-neutral-200 bg-neutral-50 object-cover"
                                />
                                <button
                                    type="button"
                                    class="absolute -right-2 -top-2 flex size-6 items-center justify-center rounded-full border border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-100"
                                    :aria-label="t('price_import.remove_page', { n: index + 1 })"
                                    @click="remove(entry.id)"
                                >
                                    ×
                                </button>
                            </div>
                            <div class="mt-1 flex items-center justify-between text-xs text-neutral-600">
                                <span>{{ t('price_import.page_n', { n: index + 1 }) }}</span>
                                <span class="flex gap-1">
                                    <button
                                        type="button"
                                        class="rounded px-1.5 py-0.5 hover:bg-neutral-100 disabled:opacity-30"
                                        :disabled="index === 0"
                                        :aria-label="t('price_import.move_up', { n: index + 1 })"
                                        @click="move(index, -1)"
                                    >
                                        ←
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded px-1.5 py-0.5 hover:bg-neutral-100 disabled:opacity-30"
                                        :disabled="index === pages.length - 1"
                                        :aria-label="t('price_import.move_down', { n: index + 1 })"
                                        @click="move(index, 1)"
                                    >
                                        →
                                    </button>
                                </span>
                            </div>
                        </li>
                    </ol>

                    <p class="mt-5 rounded-lg bg-primary-50 p-3 text-sm text-neutral-800">{{ t('price_import.privacy') }}</p>
                    <div class="mt-5 flex flex-wrap justify-end gap-3">
                        <Link
                            :href="onboardingStep('services')"
                            class="inline-flex min-h-11 items-center rounded-lg border border-neutral-300 bg-white px-4 text-base font-semibold text-neutral-800 hover:bg-neutral-100"
                            >
{{ t('price_import.cancel') }}
</Link
                        >
                        <BaseButton :disabled="pages.length === 0" @click="analyse">{{ t('price_import.analyse') }}</BaseButton>
                    </div>
                </section>

                <aside class="rounded-card border border-neutral-200 bg-white p-5 text-sm">
                    <h2 class="text-base font-semibold text-nova-900">{{ t('price_import.tips_title') }}</h2>
                    <ul class="mt-2 list-disc space-y-2 pl-5 text-neutral-800">
                        <li>{{ t('price_import.tips.frame') }}</li>
                        <li>{{ t('price_import.tips.light') }}</li>
                        <li>{{ t('price_import.tips.flat') }}</li>
                        <li>{{ t('price_import.tips.one_per_page') }}</li>
                    </ul>
                </aside>
            </div>

            <!-- Historique -->
            <section v-if="history.length > 0 && !form.processing" class="mt-8 max-w-5xl" aria-labelledby="history">
                <h2 id="history" class="text-lg font-semibold text-nova-900">{{ t('price_import.history') }}</h2>
                <ul class="mt-3 divide-y divide-neutral-200 overflow-hidden rounded-card border border-neutral-200 bg-white">
                    <li v-for="item in history" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm">
                        <div class="min-w-0 flex-1 basis-64">
                            <p class="font-semibold text-neutral-900">
                                {{ item.imported_at ? formatDateTime(item.imported_at) : '' }} · {{ item.file_names.join(', ') }}
                                <span
                                    v-if="item.status === 'undone'"
                                    class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-neutral-600"
                                    >{{ t('price_import.undone_badge') }}</span
                                >
                            </p>
                            <p class="text-neutral-600">{{ summary(item) }}</p>
                        </div>
                        <a
                            :href="`${base}/${item.id}/csv`"
                            class="rounded-lg border border-neutral-300 bg-white px-3 py-1.5 font-semibold text-neutral-800 hover:bg-neutral-100"
                            >{{ t('price_import.csv_download') }}</a
                        >
                        <BaseButton v-if="item.can_undo" variant="secondary" @click="toUndo = item">{{ t('price_import.undo') }}</BaseButton>
                    </li>
                </ul>
            </section>
        </template>

        <ConfirmDialog
            :open="toUndo !== null"
            :title="t('price_import.undo_title')"
            :body="t('price_import.undo_body')"
            :confirm-label="t('price_import.undo_confirm')"
            :loading="undoing"
            @confirm="undo"
            @cancel="toUndo = null"
        />
    </AppLayout>
</template>
