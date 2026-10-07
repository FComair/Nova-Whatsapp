export interface TimeRange {
    start: string;
    end: string;
}

export interface DayState {
    weekday: number; // 1 = lundi ... 7 = dimanche (ISO 8601)
    open: boolean;
    ranges: TimeRange[];
}

export const DEFAULT_RANGE: TimeRange = { start: '09:00', end: '18:00' };
export const MAX_RANGES_PER_DAY = 3;

/** Convertit la réponse du serveur (plages par jour) en état d'édition pour les 7 jours. */
export function toDays(week: Record<string, TimeRange[]>): DayState[] {
    return Array.from({ length: 7 }, (_unused, index) => {
        const weekday = index + 1;
        const ranges = (week[String(weekday)] ?? []).map((range) => ({ ...range }));

        return { weekday, open: ranges.length > 0, ranges };
    });
}

/** Charge envoyée au serveur : un jour fermé n'a aucune plage. */
export function toPayload(days: DayState[]): { weekday: number; ranges: TimeRange[] }[] {
    return days.map((day) => ({ weekday: day.weekday, ranges: day.open ? day.ranges.map((range) => ({ ...range })) : [] }));
}

/** Ouvre ou ferme un jour. À l'ouverture, une plage par défaut est proposée. */
export function setOpen(day: DayState, open: boolean): DayState {
    return { ...day, open, ranges: open && day.ranges.length === 0 ? [{ ...DEFAULT_RANGE }] : day.ranges };
}

/** Ajoute une plage après la dernière, sans dépasser le maximum ; la nouvelle commence 1 h après la fin précédente si possible. */
export function addRange(day: DayState): DayState {
    if (day.ranges.length >= MAX_RANGES_PER_DAY) {
        return day;
    }

    const last = day.ranges[day.ranges.length - 1];
    const start = last ? addHours(last.end, 1) : DEFAULT_RANGE.start;
    const end = addHours(start, 2);

    return { ...day, open: true, ranges: [...day.ranges, { start, end }] };
}

export function removeRange(day: DayState, index: number): DayState {
    const ranges = day.ranges.filter((_range, i) => i !== index);

    return { ...day, ranges, open: ranges.length > 0 };
}

/** Copie les plages d'un jour source sur les jours cibles (par exemple du lundi au vendredi). */
export function copyDay(days: DayState[], fromWeekday: number, toWeekdays: number[]): DayState[] {
    const source = days.find((day) => day.weekday === fromWeekday);

    if (!source) {
        return days;
    }

    return days.map((day) => (toWeekdays.includes(day.weekday) ? { ...day, open: source.open, ranges: source.ranges.map((range) => ({ ...range })) } : day));
}

/** Ajoute des heures à « HH:MM », plafonné à 23:59 (une plage ne traverse pas minuit). */
export function addHours(time: string, hours: number): string {
    const [h, m] = time.split(':').map(Number);
    const total = Math.min(h * 60 + m + hours * 60, 23 * 60 + 59);

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}
