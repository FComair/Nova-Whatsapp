<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onMounted, provide } from 'vue';
import ReportBody from '../../Components/Reports/ReportBody.vue';
import ShopAvatar from '../../Components/ShopAvatar.vue';
import { formatDate, formatDateTime } from '../../composables/dates';
import { filterQuery, queryString } from '../../composables/reports';
import { useT } from '../../composables/useT';
import { routes } from '../../routes';
import type { ReportAccessInfo, ReportCategory, ReportFiltersValue, ReportOptions, ReportPeriod } from '../../types/reports';

/**
 * Version imprimable : sans navigation ni boutons, avec l'établissement (logo), le titre, la période, les filtres et la date de
 * génération. Les graphiques y sont accompagnés de leur tableau (en-têtes répétés d'une page à l'autre) et restent lisibles en
 * niveaux de gris grâce aux trames. Le titre du document sert de nom de fichier proposé pour l'enregistrement en PDF.
 */
const props = defineProps<{
    categories: ReportCategory[];
    filters: ReportFiltersValue;
    period: ReportPeriod;
    options: ReportOptions;
    access: ReportAccessInfo;
    locale: string;
    business: { id: string | null; name: string | null; logo_version: number | null };
    generatedAt: string;
    auto: boolean;
    reports: Record<string, unknown>;
}>();

provide('reportPrint', true);

const { t } = useT();
const NAMES: Record<ReportCategory, string> = { appointments: 'Appointments', customers: 'Customers', financial: 'Financial', practitioners: 'Practitioners' };
const documentTitle = computed(
    () => `${props.categories.length === 1 ? `${NAMES[props.categories[0]]}_Report` : 'Reports'}_${props.period.from}_to_${props.period.to}`,
);
const back = computed(
    () => `${routes.reports}${queryString(filterQuery(props.filters, { category: props.categories.length === 1 ? props.categories[0] : undefined }))}`,
);
const links = computed(() => Object.fromEntries(props.categories.map((category) => [category, back.value])) as Record<ReportCategory, string>);

const currency = computed(() => {
    const found = (props.reports.financial as { currency?: string } | null | undefined)?.currency;

    return typeof found === 'string' ? found : 'EUR';
});

const activeText = computed(() => {
    const parts: string[] = [];
    const name = (list: { id: string; name: string }[], id: string | null) => list.find((row) => row.id === id)?.name;

    if (props.filters.location) parts.push(`${t('reports.filter.location')} : ${name(props.options.locations, props.filters.location) ?? ''}`);
    if (props.filters.practitioner) parts.push(`${t('reports.filter.practitioner')} : ${name(props.options.practitioners, props.filters.practitioner) ?? ''}`);
    if (props.filters.service) parts.push(`${t('reports.filter.service')} : ${name(props.options.services, props.filters.service) ?? ''}`);
    if (props.filters.status.length > 0)
        parts.push(`${t('reports.filter.status')} : ${props.filters.status.map((status) => t(`reports.status.${status}`)).join(', ')}`);
    if (props.filters.source) parts.push(`${t('reports.filter.source')} : ${t(`reports.source.${props.filters.source}`)}`);

    return parts.length === 0 ? t('reports.filter.none') : parts.join(' · ');
});

function printNow(): void {
    window.print();
}

onMounted(() => {
    // Laisse le temps aux graphiques de se dessiner avant d'ouvrir la fenêtre d'impression.
    if (props.auto) {
        setTimeout(() => window.print(), 700);
    }
});
</script>

<template>
    <Head :title="documentTitle" />

    <div class="report-print mx-auto max-w-5xl bg-surface p-4 text-neutral-900 sm:p-8 print:max-w-none print:p-0">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-control bg-surface-2 p-3 print:hidden" data-testid="print-bar">
            <div>
                <button type="button" class="rounded-full bg-primary-600 px-5 py-2 text-sm font-semibold text-white hover:bg-primary-700" @click="printNow">
                    {{ t('reports.print.now') }}
                </button>
                <p class="mt-1 text-xs text-neutral-700">{{ t('reports.print.hint') }}</p>
            </div>
            <Link :href="back" class="text-sm font-semibold text-primary-700 underline">{{ t('reports.print.back') }}</Link>
        </div>

        <header class="mb-6 border-b-2 border-nova-900 pb-4" data-testid="print-header">
            <div class="flex items-center gap-4">
                <ShopAvatar v-if="business.id" :id="business.id" :name="business.name ?? ''" :version="business.logo_version" size="xl" />
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-neutral-700">{{ business.name }}</p>
                    <h1 class="font-display text-2xl font-bold text-nova-900">{{ t('reports.title') }}</h1>
                    <p class="text-sm font-semibold text-neutral-900">{{ categories.map((category) => t(`reports.category.${category}`)).join(' · ') }}</p>
                </div>
            </div>
            <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div>
                    <dt class="inline font-semibold">{{ t('reports.period.label') }} :</dt>
                    <dd class="inline">{{ t('reports.period.range', { from: formatDate(period.from), to: formatDate(period.to) }) }}</dd>
                </div>
                <div>
                    <dt class="inline font-semibold">{{ t('reports.col.previous') }} :</dt>
                    <dd class="inline">{{ t('reports.period.range', { from: formatDate(period.previous.from), to: formatDate(period.previous.to) }) }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="inline font-semibold">{{ t('reports.filter.title') }} :</dt>
                    <dd class="inline">{{ activeText }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="inline font-semibold">{{ t('reports.period.timezone', { timezone: period.timezone }) }}</dt>
                </div>
            </dl>
            <p v-if="period.in_progress" class="mt-1 text-xs">{{ t('reports.period.in_progress') }}</p>
        </header>

        <main class="space-y-10">
            <section v-for="category in categories" :key="category" class="print:break-before-auto" :aria-labelledby="`print-${category}`">
                <h2 :id="`print-${category}`" class="mb-3 border-b border-neutral-400 pb-1 text-xl font-semibold text-nova-900">
                    {{ t(`reports.category.${category}`) }}
                </h2>
                <ReportBody
                    v-if="reports[category]"
                    :category="category"
                    :report="reports[category]"
                    :period="period"
                    :access="access"
                    :links="links"
                    :currency="currency"
                />
            </section>
        </main>

        <footer class="mt-10 border-t border-neutral-400 pt-2 text-xs text-neutral-700 print-footer">
            <span>{{ business.name }} · {{ t('reports.print.generated', { date: formatDateTime(generatedAt) }) }}</span>
            <span class="block">{{ t('reports.print.confidential') }}</span>
        </footer>
    </div>
</template>

<style>
@page {
    size: A4;
    margin: 16mm 13mm 18mm;

    @bottom-center {
        content: counter(page) ' / ' counter(pages);
        font-size: 9pt;
        color: #3f5150;
    }
}

@media print {
    html,
    body {
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .report-print h2,
    .report-print h3 {
        break-after: avoid;
    }

    .report-print table {
        break-inside: auto;
    }

    .report-print thead {
        display: table-header-group;
    }

    .report-print tr {
        break-inside: avoid;
    }

    .print-footer {
        position: fixed;
        right: 0;
        bottom: -12mm;
        left: 0;
    }
}
</style>
