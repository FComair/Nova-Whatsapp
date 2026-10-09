<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { formatDate } from '../../composables/dates';
import { filterQuery, queryString } from '../../composables/reports';
import { useT } from '../../composables/useT';
import { routes } from '../../routes';
import type { ReportAccessInfo, ReportCategory, ReportFiltersValue, ReportPeriod, ReportView } from '../../types/reports';
import AlertBox from '../AlertBox.vue';
import BaseButton from '../BaseButton.vue';
import CheckboxField from '../CheckboxField.vue';
import ModalDialog from '../ModalDialog.vue';

/**
 * Configuration d'un export : format, rapports, contenu, détail par praticien (si permis). Avant de produire le fichier, un résumé
 * dit ce qui sera inclus (tableaux, lignes, nom du fichier). L'export reprend la période et les filtres affichés, et le serveur
 * applique les mêmes droits : ce qui est refusé à l'écran n'est jamais écrit. Le PDF passe par la version imprimable.
 */
const props = defineProps<{ open: boolean; category: ReportView; filters: ReportFiltersValue; period: ReportPeriod; access: ReportAccessInfo }>();
const emit = defineEmits<{ close: [] }>();

const { t } = useT();
const format = ref<'csv' | 'xlsx' | 'pdf'>('xlsx');
const reports = ref<ReportCategory[]>([]);
const content = ref<string[]>(['summary', 'breakdown']);
const staff = ref(false);
const preview = ref<{ filename: string; tables: { title: string; rows: number }[] } | null>(null);
const previewFailed = ref(false);
const loadingPreview = ref(false);
const working = ref(false);
const failed = ref(false);
let timer: ReturnType<typeof setTimeout> | null = null;
let sequence = 0;

watch(
    () => props.open,
    (open) => {
        if (open) {
            reports.value = props.category === 'overview' ? [...props.access.categories] : [props.category];
            failed.value = false;
            staff.value = props.access.staff;
            refresh();
        }
    },
);

const query = computed(() =>
    filterQuery(props.filters, {
        reports: reports.value,
        content: content.value,
        staff: staff.value ? '1' : undefined,
        format: format.value === 'pdf' ? 'csv' : format.value,
    }),
);
const valid = computed(() => reports.value.length > 0 && content.value.length > 0);
const scope = computed(() => `${formatDate(props.period.from)} – ${formatDate(props.period.to)}`);
const pdf = computed(() => format.value === 'pdf');

function setReport(key: ReportCategory, on: boolean): void {
    reports.value = on ? [...reports.value, key] : reports.value.filter((item) => item !== key);
}

function setContent(key: string, on: boolean): void {
    content.value = on ? [...content.value, key] : content.value.filter((item) => item !== key);
}

function refresh(): void {
    if (timer !== null) clearTimeout(timer);

    timer = setTimeout(async () => {
        if (!props.open || !valid.value || pdf.value) {
            preview.value = null;

            return;
        }

        const mine = ++sequence;
        loadingPreview.value = true;
        previewFailed.value = false;

        try {
            const response = await fetch(`${routes.reportsExportPreview}${queryString(query.value)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();

            if (mine === sequence) preview.value = data;
        } catch {
            if (mine === sequence) {
                preview.value = null;
                previewFailed.value = true;
            }
        } finally {
            if (mine === sequence) loadingPreview.value = false;
        }
    }, 200);
}

watch([reports, content, staff, format], refresh, { deep: true });
onBeforeUnmount(() => timer !== null && clearTimeout(timer));

async function submit(): Promise<void> {
    failed.value = false;

    if (pdf.value) {
        const url = `${routes.reportsPrint}${queryString({ ...filterQuery(props.filters, { reports: reports.value, auto: '1' }) })}`;

        if (window.open(url, '_blank') === null) {
            failed.value = true;

            return;
        }

        emit('close');

        return;
    }

    working.value = true;

    try {
        const response = await fetch(`${routes.reportsExport}${queryString(query.value)}`, { credentials: 'same-origin' });

        if (!response.ok) throw new Error(String(response.status));

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') ?? '';
        const name = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? preview.value?.filename ?? `report.${format.value}`;
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        emit('close');
    } catch {
        failed.value = true;
    } finally {
        working.value = false;
    }
}
</script>

<template>
    <ModalDialog :open="open" :title="t('reports.export.title')" @close="emit('close')">
        <form class="space-y-5" data-testid="export-form" @submit.prevent="submit">
            <p class="text-sm text-neutral-800">{{ t('reports.export.scope', { period: scope }) }}</p>

            <fieldset>
                <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('reports.export.format') }}</legend>
                <div class="space-y-2">
                    <label v-for="item in ['xlsx', 'csv', 'pdf'] as const" :key="item" class="flex items-start gap-3 text-sm text-neutral-900">
                        <input v-model="format" type="radio" name="export-format" :value="item" class="nova-check mt-0.5" :data-testid="`format-${item}`" />
                        <span>{{ t(`reports.export.formats.${item}`) }}</span>
                    </label>
                </div>
                <p v-if="pdf" class="mt-2 text-xs text-neutral-700">{{ t('reports.export.pdf_hint') }}</p>
            </fieldset>

            <fieldset>
                <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('reports.export.reports') }}</legend>
                <div class="space-y-2">
                    <CheckboxField
                        v-for="item in access.categories"
                        :key="item"
                        :model-value="reports.includes(item)"
                        :label="t(`reports.category.${item}`)"
                        @update:model-value="setReport(item, $event)"
                    />
                </div>
                <p v-if="reports.length === 0" class="mt-1 text-sm font-medium text-danger-fg" role="alert">{{ t('reports.export.no_reports') }}</p>
            </fieldset>

            <fieldset v-if="!pdf">
                <legend class="mb-1.5 text-sm font-semibold text-neutral-900">{{ t('reports.export.content_label') }}</legend>
                <div class="space-y-2">
                    <CheckboxField
                        v-for="item in ['summary', 'breakdown', 'detail']"
                        :key="item"
                        :model-value="content.includes(item)"
                        :label="t(`reports.export.content.${item}`)"
                        @update:model-value="setContent(item, $event)"
                    />
                </div>
                <p v-if="content.length === 0" class="mt-1 text-sm font-medium text-danger-fg" role="alert">{{ t('reports.export.no_content') }}</p>
            </fieldset>

            <div v-if="!pdf && access.staff">
                <CheckboxField v-model="staff" :label="t('reports.export.staff')" />
                <p class="ml-8 mt-0.5 text-xs text-neutral-700">{{ t('reports.export.staff_help') }}</p>
            </div>

            <section
                v-if="!pdf"
                class="rounded-control bg-surface-2 p-3 text-sm"
                :aria-label="t('reports.export.includes')"
                aria-live="polite"
                data-testid="export-preview"
            >
                <p class="font-semibold text-neutral-900">{{ t('reports.export.includes') }}</p>
                <p v-if="loadingPreview" class="mt-1 text-neutral-700">{{ t('reports.loading') }}</p>
                <p v-else-if="previewFailed" class="mt-1 font-medium text-danger-fg">{{ t('reports.export.preview_failed') }}</p>
                <template v-else-if="preview">
                    <p class="mt-1 break-all text-neutral-800">{{ t('reports.export.file', { name: preview.filename }) }}</p>
                    <ul class="mt-1 list-disc pl-5 text-neutral-800">
                        <li v-for="table in preview.tables" :key="table.title">{{ t('reports.export.rows', { title: table.title, count: table.rows }) }}</li>
                    </ul>
                </template>
            </section>

            <AlertBox v-if="failed" variant="error">{{ pdf ? t('reports.print.failed') : t('reports.export.failed') }}</AlertBox>

            <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <BaseButton variant="secondary" @click="emit('close')">{{ t('reports.export.cancel') }}</BaseButton>
                <BaseButton type="submit" :loading="working" :disabled="!valid || (!pdf && loadingPreview)" data-testid="export-submit">
                    {{ working ? t('reports.export.working') : pdf ? t('reports.print.now') : t('reports.export.submit') }}
                </BaseButton>
            </div>
        </form>
    </ModalDialog>
</template>
