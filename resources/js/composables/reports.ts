/**
 * Mise en forme et comparaisons des rapports : nombres, taux, montants, durées et dates selon la langue de l'établissement,
 * jamais de couleur « bon / mauvais » sur une hausse ou une baisse, jamais de pourcentage sur une base nulle ou trop petite.
 */
import type { Granularity, MetricKind, OverviewData, ReportFiltersValue, ReportMetric, ReportView } from '../types/reports';
import { formatDate } from './dates';
import { formatPrice } from './money';

export function formatCount(value: number, locale: string, digits = 0): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: digits, minimumFractionDigits: digits }).format(value);
}

/** Nombre avec au plus `digits` décimales, sans zéros inutiles (« 20 », « 8,3 »). */
export function formatPlain(value: number, locale: string, digits = 1): string {
    return new Intl.NumberFormat(locale, { maximumFractionDigits: digits }).format(value);
}

/** Taux 0 à 1 → « 12,5 % » ; vide quand il n'y a rien à diviser. */
export function formatRate(value: number | null, locale: string): string {
    return value === null ? '—' : `${formatPlain(value * 100, locale, value > 0 && value < 0.1 ? 1 : 0)} %`;
}

/** Minutes → « 12 h 30 » (« 45 min » sous une heure). */
export function formatMinutes(minutes: number, locale: string): string {
    const sign = minutes < 0 ? '-' : '';
    const total = Math.round(Math.abs(minutes));
    const hours = Math.floor(total / 60);
    const rest = total % 60;

    if (hours === 0) {
        return `${sign}${formatCount(rest, locale)} min`;
    }

    return rest === 0 ? `${sign}${formatCount(hours, locale)} h` : `${sign}${formatCount(hours, locale)} h ${String(rest).padStart(2, '0')}`;
}

/** Valeur d'un indicateur, dans son unité. */
export function formatMetric(value: number | null, kind: MetricKind, locale: string, currency = 'EUR', digits = 0): string {
    if (value === null) {
        return '—';
    }

    switch (kind) {
        case 'rate':
            return formatRate(value, locale);
        case 'money':
            return formatPrice(value, locale, currency);
        case 'minutes':
            return formatMinutes(value, locale);
        default:
            return formatCount(value, locale, digits);
    }
}

/** Début d'un intervalle de courbe : « 09/10 » (jour ou semaine) ou « 10/2026 » (mois). */
export function bucketLabel(key: string, granularity: Granularity): string {
    const [year, month] = key.split('-');

    return granularity === 'month' ? `${month}/${year}` : formatDate(key).slice(0, 5);
}

/** Libellé complet d'un intervalle, pour les tableaux et les info-bulles. */
export function bucketTitle(key: string, granularity: Granularity): string {
    const [year, month] = key.split('-');

    return granularity === 'month' ? `${month}/${year}` : formatDate(key);
}

export type Direction = 'up' | 'down' | 'same' | 'none';

export interface Change {
    direction: Direction;
    /** Écart absolu mis en forme, avec son signe (« +4 », « -2,5 pts »). */
    delta: string | null;
    /** Pourcentage d'évolution mis en forme (« +8,3 % »), absent quand la base ne le permet pas. */
    pct: string | null;
    /** Clé de traduction (sous `reports.compare.`) qui explique l'absence de pourcentage. */
    note: 'zero_baseline' | 'small_baseline' | 'no_baseline' | null;
}

const signed = (text: string, value: number): string => (value > 0 ? `+${text}` : text);

/** Comparaison d'un chiffre avec la période précédente, sans jugement : une hausse n'est ni bonne ni mauvaise. */
export function changeOf(metric: ReportMetric, locale: string, currency = 'EUR', digits = 0): Change {
    if (metric.previous === null || metric.delta === null) {
        return { direction: 'none', delta: null, pct: null, note: metric.previous === null ? null : metric.note };
    }

    const direction: Direction = metric.delta > 0 ? 'up' : metric.delta < 0 ? 'down' : 'same';
    const delta =
        metric.kind === 'rate'
            ? `${signed(formatPlain(metric.delta, locale), metric.delta)} pt`
            : signed(formatMetric(metric.delta, metric.kind, locale, currency, digits), metric.delta);
    const pct = metric.pct === null ? null : `${signed(formatPlain(metric.pct, locale), metric.pct)} %`;

    return { direction, delta, pct, note: metric.pct === null && metric.kind !== 'rate' ? metric.note : null };
}

/** Paramètres d'adresse qui reproduisent période et filtres (changement de catégorie, impression, export). */
export function filterQuery(filters: ReportFiltersValue, extra: Record<string, string | string[] | undefined> = {}): Record<string, string | string[]> {
    const query: Record<string, string | string[]> = { preset: filters.preset };

    if (filters.preset === 'custom') {
        query.from = filters.from;
        query.to = filters.to;
    }

    const optional: [string, string | null][] = [
        ['location', filters.location],
        ['practitioner', filters.practitioner],
        ['service', filters.service],
        ['source', filters.source],
        ['payment', filters.payment],
    ];

    for (const [key, value] of optional) {
        if (value) {
            query[key] = value;
        }
    }

    if (filters.status.length > 0) {
        query.status = filters.status;
    }

    for (const [key, value] of Object.entries(extra)) {
        if (value !== undefined) {
            query[key] = value;
        }
    }

    return query;
}

/** « ?a=1&b[]=x&b[]=y » : le format que Laravel relit pour les listes. */
export function queryString(query: Record<string, string | string[]>): string {
    const parts: string[] = [];

    for (const [key, value] of Object.entries(query)) {
        for (const item of Array.isArray(value) ? value : [value]) {
            parts.push(`${encodeURIComponent(Array.isArray(value) ? `${key}[]` : key)}=${encodeURIComponent(item)}`);
        }
    }

    return parts.length === 0 ? '' : `?${parts.join('&')}`;
}

/** Nombre de filtres actifs, hors période. */
export function activeFilterCount(filters: ReportFiltersValue): number {
    return (
        [filters.location, filters.practitioner, filters.service, filters.source, filters.payment].filter(Boolean).length + (filters.status.length > 0 ? 1 : 0)
    );
}

/** Couleurs des séries : lisibles aussi en niveaux de gris (trame différente par série en plus de la teinte). */
export const SERIES_STYLE: Record<string, { color: string; pattern: 'solid' | 'stripes' | 'dots' | 'cross' }> = {
    completed: { color: 'var(--color-primary-700)', pattern: 'solid' },
    upcoming: { color: 'var(--color-primary-300)', pattern: 'solid' },
    awaiting: { color: 'var(--color-amber)', pattern: 'dots' },
    no_show: { color: 'var(--color-danger-700)', pattern: 'stripes' },
    cancelled: { color: 'var(--color-neutral-400)', pattern: 'cross' },
    value: { color: 'var(--color-primary-300)', pattern: 'solid' },
    collected: { color: 'var(--color-primary-700)', pattern: 'solid' },
    new: { color: 'var(--color-primary-700)', pattern: 'solid' },
    active: { color: 'var(--color-primary-300)', pattern: 'stripes' },
    booked: { color: 'var(--color-primary-700)', pattern: 'solid' },
    available: { color: 'var(--color-primary-200)', pattern: 'dots' },
    tips: { color: 'var(--color-amber)', pattern: 'dots' },
    dashboard: { color: 'var(--color-primary-700)', pattern: 'solid' },
    whatsapp: { color: 'var(--color-primary-300)', pattern: 'stripes' },
    staff_whatsapp: { color: 'var(--color-amber)', pattern: 'dots' },
    api: { color: 'var(--color-neutral-500)', pattern: 'cross' },
};

/** Échelle « agréable » d'axe : un maximum arrondi et quelques graduations. */
export function niceScale(max: number, ticks = 4): { max: number; step: number } {
    if (max <= 0) {
        return { max: ticks, step: 1 };
    }

    const raw = max / ticks;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalized = raw / magnitude;
    const step = (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10) * magnitude;

    return { max: Math.ceil(max / step) * step, step };
}

/** Colonnes et lignes du tableau équivalent à une carte de chaleur : un jour par ligne, une heure par colonne. */
export function heatmapTable(
    heatmap: { cells: Record<string, number[]>; first_hour: number; last_hour: number },
    labels: { day: string; weekday: (day: number) => string },
): { columns: { key: string; label: string; align?: 'left' | 'right' }[]; rows: Record<string, string | number>[] } {
    const hours = Array.from(
        { length: Math.max(heatmap.first_hour, heatmap.last_hour) - Math.min(heatmap.first_hour, heatmap.last_hour) + 1 },
        (_unused, index) => Math.min(heatmap.first_hour, heatmap.last_hour) + index,
    );

    return {
        columns: [
            { key: 'day', label: labels.day },
            ...hours.map((hour) => ({ key: `h${hour}`, label: `${String(hour).padStart(2, '0')}:00`, align: 'right' as const })),
        ],
        rows: Array.from({ length: 7 }, (_unused, index) => index + 1).map((day) => ({
            day: labels.weekday(day),
            ...Object.fromEntries(hours.map((hour) => [`h${hour}`, heatmap.cells[String(day)][hour]])),
        })),
    };
}

/**
 * Le rapport n'a rien à montrer pour la période : aucun rendez-vous (ou, selon la catégorie, aucun client, montant ou temps prévu).
 * Sert à afficher l'état vide plutôt qu'une page remplie de zéros.
 */
export function isEmptyReport(category: ReportView, report: unknown): boolean {
    if (report === null || report === undefined) {
        return false;
    }

    const metricsOf = (group: unknown): Record<string, ReportMetric> => (group as { metrics?: Record<string, ReportMetric> } | null | undefined)?.metrics ?? {};
    const zero = (metrics: Record<string, ReportMetric>, ...keys: string[]): boolean => keys.every((key) => (metrics[key]?.value ?? 0) === 0);

    switch (category) {
        case 'overview':
            return zero(metricsOf((report as OverviewData).appointments), 'total');
        case 'appointments':
            return zero(metricsOf(report), 'total');
        case 'customers':
            return zero(metricsOf(report), 'active', 'new');
        case 'financial':
            return zero(metricsOf(report), 'booked_value', 'collected', 'cancelled_value');
        default:
            return zero(metricsOf(report), 'assigned', 'scheduled');
    }
}
