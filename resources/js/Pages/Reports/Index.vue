<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AlertBox from '../../Components/AlertBox.vue';
import BaseButton from '../../Components/BaseButton.vue';
import CheckboxField from '../../Components/CheckboxField.vue';
import ExportDialog from '../../Components/Reports/ExportDialog.vue';
import ReportBody from '../../Components/Reports/ReportBody.vue';
import ReportToolbar from '../../Components/Reports/ReportToolbar.vue';
import { filterQuery, isEmptyReport, queryString } from '../../composables/reports';
import { useT } from '../../composables/useT';
import AppLayout from '../../Layouts/AppLayout.vue';
import { routes } from '../../routes';
import type { ReportAccessInfo, ReportCategory, ReportFiltersValue, ReportOptions, ReportPeriod, ReportView } from '../../types/reports';

/**
 * Rapports et analyses : vue d'ensemble puis quatre catégories (rendez-vous, clients, finances, praticiens). Changer de catégorie garde
 * la période et les filtres. États : chargement (recalcul), vide (aucune donnée / aucun résultat avec ces filtres) et erreur.
 */
const props = defineProps<{
    category: ReportView;
    filters: ReportFiltersValue;
    period: ReportPeriod;
    options: ReportOptions;
    access: ReportAccessInfo;
    locale: string;
    report: unknown;
}>();

const { t } = useT();
const exporting = ref(false);
const loading = ref(false);
const failed = ref(false);
const printFailed = ref(false);
const managers = ref(props.access.managers_financial);
const removers: (() => void)[] = [];

onMounted(() => {
    // Un recalcul en cours (changement de période, de filtre ou de catégorie) affiche l'état « calcul en cours ».
    removers.push(
        router.on('start', (event) => {
            loading.value = event.detail.visit.url.pathname === routes.reports;
            failed.value = false;
        }),
        router.on('finish', () => {
            loading.value = false;
        }),
        // Une erreur serveur pendant le calcul : message clair avec « Réessayer », au lieu de la fenêtre d'erreur brute.
        router.on('httpException', () => {
            failed.value = true;

            return false;
        }),
        router.on('networkError', () => {
            failed.value = true;

            return false;
        }),
    );
});
onBeforeUnmount(() => removers.forEach((remove) => remove()));

const tabs = computed<ReportView[]>(() => ['overview', ...props.access.categories]);
const href = (view: ReportView): string => `${routes.reports}${queryString(filterQuery(props.filters, { category: view === 'overview' ? undefined : view }))}`;
const links = computed(() => Object.fromEntries(props.access.categories.map((category) => [category, href(category)])) as Record<ReportCategory, string>);

const report = computed(() => props.report as Record<string, unknown> | null);
const currency = computed(() => {
    const found = (report.value as { currency?: string } | null)?.currency;

    return typeof found === 'string' ? found : 'EUR';
});

/** Aucune donnée sur la période : état vide, avec une variante quand des filtres sont actifs. */
const empty = computed(() => isEmptyReport(props.category, props.report));
const filtered = computed(
    () =>
        props.filters.location !== null ||
        props.filters.practitioner !== null ||
        props.filters.service !== null ||
        props.filters.source !== null ||
        props.filters.status.length > 0,
);

function print(): void {
    const reports = props.category === 'overview' ? props.access.categories : [props.category];
    const url = `${routes.reportsPrint}${queryString(filterQuery(props.filters, { reports, auto: '1' }))}`;
    printFailed.value = window.open(url, '_blank') === null;
}

function reset(): void {
    router.get(
        routes.reports,
        filterQuery(
            { ...props.filters, location: null, practitioner: null, service: null, source: null, status: [] },
            { category: props.category === 'overview' ? undefined : props.category },
        ),
        { preserveScroll: true },
    );
}

function retry(): void {
    failed.value = false;
    router.reload();
}

function saveManagers(value: boolean): void {
    managers.value = value;
    router.put(routes.reportsSettings, { managers_financial: value }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="t('reports.title')">
        <div class="mx-auto max-w-6xl">
            <header class="mb-5">
                <h1 class="text-2xl font-semibold text-rendivoo-900" data-testid="reports-title">{{ t('reports.title') }}</h1>
                <p class="mt-1 text-sm text-neutral-700">{{ t('reports.subtitle') }}</p>
            </header>

            <nav class="mb-5 border-b border-line" :aria-label="t('reports.categories_label')">
                <ul class="-mb-px flex gap-1 overflow-x-auto">
                    <li v-for="view in tabs" :key="view">
                        <Link
                            :href="href(view)"
                            preserve-scroll
                            class="block whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/20"
                            :class="
                                category === view
                                    ? 'border-primary-600 text-primary-700'
                                    : 'border-transparent text-neutral-700 hover:border-neutral-300 hover:text-rendivoo-900'
                            "
                            :aria-current="category === view ? 'page' : undefined"
                            :data-testid="`tab-${view}`"
                        >
                            {{ t(`reports.category.${view}`) }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <ReportToolbar
                :category="category"
                :filters="filters"
                :period="period"
                :options="options"
                :access="access"
                :status-applies="category !== 'customers'"
                @export="exporting = true"
                @print="print"
            />

            <AlertBox v-if="printFailed" variant="error" class="mt-4">{{ t('reports.print.failed') }}</AlertBox>

            <div class="relative mt-6" :aria-busy="loading ? 'true' : 'false'">
                <div
                    v-if="loading"
                    class="absolute inset-0 z-20 flex items-start justify-center rounded-card bg-canvas/70 pt-16 backdrop-blur-[1px]"
                    role="status"
                    data-testid="reports-loading"
                >
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-4 py-2 text-sm font-semibold text-neutral-900 shadow-md"
                    >
                        <svg class="size-4 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity="0.25" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        {{ t('reports.loading') }}
                    </span>
                </div>

                <div v-if="failed" class="rounded-card border border-danger-fg/25 bg-danger-bg p-6 text-center" role="alert" data-testid="reports-error">
                    <h2 class="text-lg font-semibold text-danger-fg">{{ t('reports.error.title') }}</h2>
                    <p class="mt-1 text-sm text-neutral-900">{{ t('reports.error.body') }}</p>
                    <BaseButton class="mt-4" variant="secondary" @click="retry">{{ t('reports.error.retry') }}</BaseButton>
                </div>

                <div v-else-if="empty" class="rounded-card border border-line bg-surface p-8 text-center shadow-xs" data-testid="reports-empty">
                    <h2 class="text-lg font-semibold text-rendivoo-900">
                        {{ filtered ? t('reports.empty.filtered_title') : t('reports.empty.no_data_title') }}
                    </h2>
                    <p class="mt-1 text-sm text-neutral-700">{{ filtered ? t('reports.empty.filtered_body') : t('reports.empty.no_data_body') }}</p>
                    <BaseButton v-if="filtered" class="mt-4" variant="secondary" @click="reset">{{ t('reports.empty.reset') }}</BaseButton>
                </div>

                <ReportBody
                    v-else-if="report"
                    :category="category"
                    :report="report"
                    :period="period"
                    :access="access"
                    :links="links"
                    :currency="currency"
                    :payment-filtered="filters.payment !== null"
                />
            </div>

            <section
                v-if="access.can_configure"
                class="mt-8 rounded-card border border-line bg-surface p-4 shadow-xs print:hidden"
                :aria-label="t('reports.settings.title')"
                data-testid="reports-settings"
            >
                <h2 class="text-sm font-semibold text-rendivoo-900">{{ t('reports.settings.title') }}</h2>
                <div class="mt-2"><CheckboxField :model-value="managers" :label="t('reports.settings.label')" @update:model-value="saveManagers" /></div>
                <p class="mt-1 text-xs text-neutral-700">{{ t('reports.settings.help') }}</p>
            </section>
        </div>

        <ExportDialog :open="exporting" :category="category" :filters="filters" :period="period" :access="access" @close="exporting = false" />
    </AppLayout>
</template>
