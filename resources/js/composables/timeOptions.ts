/** Heures proposées dans les listes de plages horaires : un tableau fini, ordonné, calculé une fois (jamais une liste qui « tourne »). */

/** Pas des listes, en minutes (identique pour toutes les plages de travail). */
export const STEP_MINUTES = 15;

export const DAY_START = '00:00';
export const DAY_END = '23:59';

/** « 09:30 » → 570 ; null si ce n'est pas une heure valide. */
export function toMinutes(time: string): number | null {
    const match = /^(\d{2}):(\d{2})$/.exec(time);

    if (match === null) {
        return null;
    }

    const minutes = Number(match[1]) * 60 + Number(match[2]);

    return Number(match[1]) <= 23 && Number(match[2]) <= 59 ? minutes : null;
}

export function fromMinutes(total: number): string {
    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/**
 * Heures de `min` à `max` (comprises), de `step` en `step` minutes. La valeur actuelle y figure toujours, même hors pas
 * (un horaire déjà enregistré, 09:10 ou 23:59, ne doit pas disparaître de la liste).
 */
export function timeOptions(min: string, max: string, current?: string, step = STEP_MINUTES): string[] {
    const from = toMinutes(min) ?? 0;
    const to = toMinutes(max) ?? 23 * 60 + 59;
    const options: string[] = [];

    for (let minutes = Math.ceil(from / step) * step; minutes <= to; minutes += step) {
        options.push(fromMinutes(minutes));
    }

    if (current !== undefined && current !== '' && toMinutes(current) !== null && !options.includes(current)) {
        options.push(current);
        options.sort();
    }

    return options;
}

/** Heures de début possibles : strictement avant la fin choisie. */
export function startOptions(start: string, end: string, bounds?: { start: string; end: string }): string[] {
    const last = toMinutes(end);
    const max = last === null ? (bounds?.end ?? DAY_END) : fromMinutes(Math.max(0, last - 1));

    return timeOptions(bounds?.start ?? DAY_START, max, start);
}

/** Heures de fin possibles : strictement après le début choisi. */
export function endOptions(start: string, end: string, bounds?: { start: string; end: string }): string[] {
    const first = toMinutes(start);
    const min = first === null ? (bounds?.start ?? DAY_START) : fromMinutes(first + 1);

    return timeOptions(min, bounds?.end ?? DAY_END, end);
}

/** La fin doit être après le début. */
export function rangeIsValid(range: { start: string; end: string }): boolean {
    const start = toMinutes(range.start);
    const end = toMinutes(range.end);

    return start !== null && end !== null && end > start;
}
