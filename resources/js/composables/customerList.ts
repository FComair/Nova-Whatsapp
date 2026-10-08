/** Aides de la liste des clients : filtres, vues de départ, colonnes, initiales. Pures et testées (customerList.test.ts). */

export type Filters = Partial<{
    status: string;
    appt: string;
    consent: string;
    practitioner: string;
    tag: string;
    source: string;
    days: number;
    created_days: number;
    visits_min: number;
}>;

export type ColumnKey = 'contact' | 'last' | 'next' | 'visits' | 'value' | 'whatsapp' | 'tags' | 'status' | 'source' | 'created';

/** Ordre d'affichage des colonnes optionnelles ; « Client » est toujours là. */
export const ALL_COLUMNS: ColumnKey[] = ['contact', 'last', 'next', 'visits', 'value', 'whatsapp', 'tags', 'status', 'source', 'created'];

export const DEFAULT_COLUMNS: ColumnKey[] = ['contact', 'last', 'next', 'visits', 'value', 'whatsapp', 'tags'];

/** Colonnes qu'on peut trier (clé de tri envoyée au serveur). */
export const SORTABLE: Partial<Record<ColumnKey | 'name', string>> = {
    name: 'name',
    last: 'last',
    next: 'next',
    visits: 'visits',
    value: 'value',
    created: 'created',
};

export interface StarterView {
    key: string;
    filters: Filters;
    sort?: string;
    dir?: 'asc' | 'desc';
}

/** Vues proposées d'office ; leurs noms sont dans `customers.views.*`. */
export const STARTER_VIEWS: StarterView[] = [
    { key: 'all', filters: {} },
    { key: 'upcoming', filters: { appt: 'upcoming' }, sort: 'next' },
    { key: 'inactive', filters: { appt: 'inactive', days: 90 }, sort: 'last', dir: 'asc' },
    { key: 'recent', filters: { created_days: 30 }, sort: 'created', dir: 'desc' },
    { key: 'no_show', filters: { appt: 'no_show' } },
    { key: 'opted_out', filters: { consent: 'opted_out' } },
];

const FILTER_KEYS: (keyof Filters)[] = ['status', 'appt', 'consent', 'practitioner', 'tag', 'source', 'days', 'created_days', 'visits_min'];

/** Retire les filtres vides pour ne garder que ce qui est réellement choisi. */
export function cleanFilters(filters: Filters): Filters {
    const clean: Record<string, string | number> = {};

    for (const key of FILTER_KEYS) {
        const value = filters[key];

        if (value !== undefined && value !== null && value !== '' && !(typeof value === 'number' && Number.isNaN(value))) {
            clean[key] = value;
        }
    }

    // « Sans rendez-vous depuis N jours » n'a de sens qu'avec le filtre « Sans visite récente ».
    if (clean.appt !== 'inactive') {
        delete clean.days;
    }

    return clean as Filters;
}

/** Nombre de filtres actifs (le statut par défaut n'en est pas un). */
export function activeFilterCount(filters: Filters): number {
    return Object.keys(cleanFilters(filters)).length;
}

export function sameFilters(a: Filters, b: Filters): boolean {
    const x = cleanFilters(a) as Record<string, unknown>;
    const y = cleanFilters(b) as Record<string, unknown>;
    const keys = new Set([...Object.keys(x), ...Object.keys(y)]);

    return [...keys].every((key) => String(x[key] ?? '') === String(y[key] ?? ''));
}

/** Paramètres d'URL de la liste : uniquement ce qui diffère du réglage par défaut. */
export function buildQuery(state: { q: string; filters: Filters; sort: string; dir: string; page?: number }): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    const q = state.q.trim();

    if (q !== '') {
        query.q = q;
    }

    Object.assign(query, cleanFilters(state.filters));

    if (state.sort !== 'name' || state.dir !== 'asc') {
        query.sort = state.sort;
        query.dir = state.dir;
    }

    if (state.page !== undefined && state.page > 1) {
        query.page = state.page;
    }

    return query;
}

/** Colonnes lues du stockage local ou d'une vue : on ignore les clés inconnues, et on retombe sur le défaut si rien ne reste. */
export function normalizeColumns(value: unknown): ColumnKey[] {
    if (!Array.isArray(value)) {
        return [...DEFAULT_COLUMNS];
    }

    const known = ALL_COLUMNS.filter((column) => value.includes(column));

    return known.length > 0 ? known : [...DEFAULT_COLUMNS];
}

/** « Sophie Martin » → « SM » ; un client sans nom : les deux derniers chiffres du téléphone. */
export function initials(name: string | null, phone: string): string {
    const words = (name ?? '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return phone.replace(/\D/g, '').slice(-2) || '?';
    }

    return (words.length === 1 ? words[0].slice(0, 2) : words[0][0] + words[words.length - 1][0]).toUpperCase();
}

/** Vue de départ ou enregistrée qui correspond exactement à l'état de l'écran (sinon null). */
export function matchingView(state: { q: string; filters: Filters }, starters: StarterView[], saved: { id: string; filters: Filters }[]): string | null {
    if (state.q.trim() !== '') {
        return null;
    }

    const starter = starters.find((view) => sameFilters(view.filters, state.filters));

    if (starter) {
        return `starter:${starter.key}`;
    }

    const own = saved.find((view) => sameFilters(view.filters, state.filters));

    return own ? `saved:${own.id}` : null;
}
