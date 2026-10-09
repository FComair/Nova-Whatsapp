<script setup lang="ts">
import { computed } from 'vue';
import type {
    AppointmentsReportData,
    CustomersReportData,
    FinancialReportData,
    OverviewData,
    PractitionersReportData,
    ReportAccessInfo,
    ReportCategory,
    ReportPeriod,
    ReportView,
} from '../../types/reports';
import AppointmentsView from './AppointmentsView.vue';
import CustomersView from './CustomersView.vue';
import FinancialView from './FinancialView.vue';
import OverviewView from './OverviewView.vue';
import PractitionersView from './PractitionersView.vue';

/** Affiche la vue de la catégorie demandée (écran et impression utilisent le même corps de rapport). */
const props = defineProps<{
    category: ReportView;
    report: unknown;
    period: ReportPeriod;
    access: ReportAccessInfo;
    links: Record<ReportCategory, string>;
    /** Devise des montants affichés hors du rapport financier (valeur des services, des praticiens). */
    currency: string;
    /** Un filtre de statut de paiement est actif (rapport financier). */
    paymentFiltered?: boolean;
}>();

const appointments = computed(() => props.report as AppointmentsReportData);
const customers = computed(() => props.report as CustomersReportData);
const financial = computed(() => props.report as FinancialReportData);
const practitioners = computed(() => props.report as PractitionersReportData);
const overview = computed(() => props.report as OverviewData);
</script>

<template>
    <OverviewView v-if="category === 'overview'" :overview="overview" :period="period" :links="links" />
    <AppointmentsView v-else-if="category === 'appointments'" :report="appointments" :period="period" :currency="currency" :show-values="access.financial" />
    <CustomersView v-else-if="category === 'customers'" :report="customers" :period="period" :can-open-customers="access.role !== 'practitioner'" />
    <FinancialView v-else-if="category === 'financial'" :report="financial" :period="period" :payment-filtered="paymentFiltered" />
    <PractitionersView v-else :report="practitioners" :period="period" :currency="currency" :show-values="access.financial" :show-compare="access.staff" />
</template>
