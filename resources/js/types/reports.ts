/** Types des rapports et analyses (voir app/Modules/Reports). Les montants sont en centimes, les durées en minutes. */

export type ReportCategory = 'appointments' | 'customers' | 'financial' | 'practitioners';
export type ReportView = ReportCategory | 'overview';
export type MetricKind = 'count' | 'money' | 'minutes' | 'rate';
export type Granularity = 'day' | 'week' | 'month';

/** Un chiffre et sa comparaison avec la période précédente. `delta` d'un taux est en points de pourcentage. */
export interface ReportMetric {
    value: number | null;
    previous: number | null;
    delta: number | null;
    pct: number | null;
    kind: MetricKind;
    note: 'zero_baseline' | 'small_baseline' | 'no_baseline' | null;
}

export interface Insight {
    key: string;
    params: Record<string, string | number>;
}

export interface ReportFiltersValue {
    preset: string;
    from: string;
    to: string;
    location: string | null;
    practitioner: string | null;
    service: string | null;
    status: string[];
    source: string | null;
    /** Statut de paiement (rapport financier et pourboires). */
    payment: string | null;
}

export interface ReportPeriod {
    from: string;
    to: string;
    days: number;
    previous: { from: string; to: string };
    in_progress: boolean;
    timezone: string;
}

export interface ReportOptions {
    locations: { id: string; name: string }[];
    practitioners: { id: string; name: string }[];
    services: { id: string; name: string }[];
    statuses: string[];
    sources: string[];
    payment_statuses: string[];
    presets: string[];
}

export interface ReportAccessInfo {
    categories: ReportCategory[];
    financial: boolean;
    export: boolean;
    staff: boolean;
    own_only: boolean;
    can_configure: boolean;
    managers_financial: boolean;
    role: string;
}

export interface AppointmentBucket {
    key: string;
    total: number;
    completed: number;
    upcoming: number;
    awaiting: number;
    no_show: number;
    cancelled: number;
}

export interface Heatmap {
    cells: Record<string, number[]>;
    max: number;
    total: number;
    first_hour: number;
    last_hour: number;
}

export interface AppointmentsReportData {
    metrics: Record<string, ReportMetric>;
    series: { granularity: Granularity; buckets: AppointmentBucket[] };
    statuses: { key: string; count: number }[];
    heatmap: Heatmap;
    sources: { key: string; count: number; share: number | null }[];
    services: { id: string | null; name: string | null; count: number; cancelled: number; value_cents: number | null }[];
    service_sources: { id: string; name: string; sources: Record<string, number> }[];
    insights: Insight[];
}

export interface CustomersReportData {
    inactive_days: number;
    metrics: Record<string, ReportMetric>;
    retention_base: number;
    series: { granularity: Granularity; buckets: { key: string; new: number; active: number }[] };
    by_service: { id: string; name: string; count: number }[];
    by_source: { key: string; count: number }[];
    by_location: { id: string; name: string; count: number }[] | null;
    insights: Insight[];
}

export interface MoneyRow {
    id: string;
    name: string;
    count: number;
    value: number;
    collected: number;
    /** Pourboires des rendez-vous concernés, à part de `value` et `collected` (services et sites). */
    tips?: number;
    photo_version?: number | null;
}

export interface FinancialReportData {
    currency: string;
    excluded_other_currency: number;
    metrics: Record<string, ReportMetric>;
    series: { granularity: Granularity; buckets: { key: string; value: number; collected: number; tips: number }[] };
    services: MoneyRow[];
    practitioners: MoneyRow[] | null;
    locations: MoneyRow[] | null;
    methods: { key: string; collected: number }[];
    payment_statuses: { key: string; count: number; price: number; collected: number }[];
    /** Pourboires reçus par praticien (d'après leur répartition) ; null sans droit de comparer l'équipe. */
    tips_by_practitioner: { id: string; name: string; tips: number; count: number; share: number | null; photo_version: number | null }[] | null;
    /** Nombre de pourboires répartis entre plusieurs praticiens. */
    tips_split: number;
    unavailable: string[];
    insights: Insight[];
}

export interface PractitionerRow {
    id: string;
    name: string;
    photo_version: number | null;
    assigned: number;
    previous_assigned: number;
    completed: number;
    cancelled: number;
    no_show: number;
    cancellation_rate: number | null;
    no_show_rate: number | null;
    scheduled_minutes: number;
    booked_minutes: number;
    completed_minutes: number;
    available_minutes: number;
    utilization: number | null;
    working_days: number;
    days_off: number;
    non_working_days: number;
    value_cents: number | null;
    collected_cents: number | null;
    /** Pourboires reçus (à part de la valeur et de l'encaissé) ; null sans droit aux montants. */
    tips_cents: number | null;
    services: { id: string; name: string; count: number }[];
}

export interface PractitionersReportData {
    rows: PractitionerRow[];
    metrics: Record<string, ReportMetric>;
    heatmap: Heatmap;
    time_off: { practitioner_id: string; practitioner: string; from: string; to: string; days: number }[];
    insights: Insight[];
}

export interface OverviewData {
    appointments: { metrics: Record<string, ReportMetric>; series: AppointmentsReportData['series']; insights: Insight[] } | null;
    customers: { metrics: Record<string, ReportMetric> } | null;
    financial: { metrics: Record<string, ReportMetric>; currency: string; series: FinancialReportData['series'] } | null;
    practitioners: { metrics: Record<string, ReportMetric> } | null;
}
