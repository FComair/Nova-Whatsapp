/** Hauteur d'une minute dans la grille du planning (60 px par heure). */
export const PIXELS_PER_MINUTE = 1;

/** Minutes depuis minuit → « 09:30 ». */
export function minutesToTime(minutes: number): string {
    const hours = Math.floor(minutes / 60);

    return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Position verticale d'un bloc dans la grille : les blocs très courts gardent une hauteur cliquable. */
export function blockStyle(range: { from: number }, startMinute: number, endMinute: number): { top: string; height: string } {
    const top = (startMinute - range.from) * PIXELS_PER_MINUTE;
    const height = Math.max((endMinute - startMinute) * PIXELS_PER_MINUTE, 24);

    return { top: `${top}px`, height: `${height}px` };
}

/** Repères horaires de la grille (une ligne par heure entière). */
export function hourMarks(range: { from: number; to: number }): number[] {
    const marks: number[] = [];

    for (let minute = Math.ceil(range.from / 60) * 60; minute < range.to; minute += 60) {
        marks.push(minute);
    }

    return marks;
}

/** Décale une date « AAAA-MM-JJ » d'un nombre de jours (calcul en UTC : sans effet de l'heure d'été). */
export function shiftDate(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const shifted = new Date(Date.UTC(year, month - 1, day + days));

    return shifted.toISOString().slice(0, 10);
}

/** Erreur métier (« slot » : créneau indisponible, « booking » : règle non respectée) hors des champs du formulaire. */
export function extraError(errors: object, key: 'slot' | 'booking'): string | undefined {
    return (errors as Record<string, string | undefined>)[key];
}
