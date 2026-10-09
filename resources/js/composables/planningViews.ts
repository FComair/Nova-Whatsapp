/** Vues du planning : jour, 3 jours, semaine de travail, praticiens. Règles pures (colonnes, pas de navigation), testées. */

export type PlanningView = 'day' | '3days' | 'week' | 'practitioners';

export const PLANNING_VIEWS: PlanningView[] = ['day', '3days', 'week', 'practitioners'];

/** Vue par défaut : la semaine de travail, comme avant l'arrivée des vues. */
export const DEFAULT_VIEW: PlanningView = 'week';

export function parseView(value: unknown): PlanningView {
    return PLANNING_VIEWS.includes(value as PlanningView) ? (value as PlanningView) : DEFAULT_VIEW;
}

/**
 * Colonnes de la grille : des jours, ou des praticiens (vue « Praticiens » avec plusieurs praticiens affichés, pour le jour choisi).
 * Avec un seul praticien affiché, la vue « Praticiens » montre sa semaine de travail : des colonnes de jours.
 */
export function columnMode(view: PlanningView, shownPractitioners: number): 'days' | 'practitioners' {
    return view === 'practitioners' && shownPractitioners !== 1 ? 'practitioners' : 'days';
}

/** Jours dont Précédent et Suivant décalent la date : 1 jour, 3 jours, 7 jours (semaine), ou 1 jour pour plusieurs praticiens. */
export function stepDays(view: PlanningView, shownPractitioners: number): number {
    if (view === 'day') {
        return 1;
    }

    if (view === '3days') {
        return 3;
    }

    return columnMode(view, shownPractitioners) === 'practitioners' ? 1 : 7;
}

/** Clé de traduction des boutons Précédent et Suivant : « jour », « 3 jours » ou « semaine ». */
export function stepKey(view: PlanningView, shownPractitioners: number): 'day' | 'days3' | 'week' {
    const step = stepDays(view, shownPractitioners);

    return step === 1 ? 'day' : step === 3 ? 'days3' : 'week';
}

/** Les vues « Jour » et « 3 jours » montrent tous leurs jours, même fermés ; les autres masquent les jours sans horaires. */
export function hidesClosedDays(view: PlanningView): boolean {
    return view === 'week' || view === 'practitioners';
}

/** Largeur minimale de la grille (px) : l'axe des heures plus une largeur utile par colonne, sinon défilement horizontal. */
export function gridMinWidth(columns: number, view: PlanningView): number {
    const column = view === 'day' ? 320 : view === '3days' ? 200 : 132;

    return 56 + Math.max(columns, 1) * column;
}
