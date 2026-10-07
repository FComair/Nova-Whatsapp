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

/**
 * Répartition des rendez-vous qui se chevauchent dans une même journée : chacun reçoit une colonne (`lane`) et le nombre de colonnes
 * de son groupe (`lanes`), pour les afficher côte à côte au lieu de les superposer. Un bloc très court garde sa hauteur minimale
 * (24 px) : il compte comme occupant cet espace.
 */
export function layoutLanes(items: { id: string; start_minute: number; end_minute: number }[]): Record<string, { lane: number; lanes: number }> {
    const minimum = 24 / PIXELS_PER_MINUTE;
    const sorted = [...items].sort((a, b) => a.start_minute - b.start_minute || a.end_minute - b.end_minute);
    const layout: Record<string, { lane: number; lanes: number }> = {};
    let group: string[] = [];
    let groupEnd = -1;
    let laneEnds: number[] = [];

    const close = (): void => {
        group.forEach((id) => (layout[id].lanes = laneEnds.length));
        group = [];
        laneEnds = [];
    };

    for (const item of sorted) {
        const end = Math.max(item.end_minute, item.start_minute + minimum);

        if (group.length > 0 && item.start_minute >= groupEnd) {
            close();
        }

        let lane = laneEnds.findIndex((laneEnd) => laneEnd <= item.start_minute);

        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(end);
        } else {
            laneEnds[lane] = end;
        }

        layout[item.id] = { lane, lanes: 0 };
        group.push(item.id);
        groupEnd = Math.max(groupEnd, end);
    }

    close();

    return layout;
}

/** Jour ISO de la semaine d'une date « AAAA-MM-JJ » : 1 = lundi … 7 = dimanche. */
export function isoWeekday(date: string): number {
    const day = new Date(`${date}T12:00:00Z`).getUTCDay();

    return day === 0 ? 7 : day;
}

/**
 * Jours à afficher dans la semaine : ceux où le site est ouvert ET où au moins un praticien (celui du filtre, ou n'importe lequel)
 * travaille d'après son profil. Un jour qui porte déjà un rendez-vous reste toujours visible. Si rien ne reste (horaires pas encore
 * saisis), toute la semaine est affichée plutôt qu'un calendrier vide.
 */
export function visibleDays<T extends { date: string; open: { start: number; end: number }[] }>(
    days: T[],
    practitioners: { id: string; schedule: Record<string, { start: number; end: number }[]> }[],
    bookedDates: Set<string>,
    practitionerId = '',
): T[] {
    const pool = practitionerId === '' ? practitioners : practitioners.filter((practitioner) => practitioner.id === practitionerId);
    const shown = days.filter(
        (day) =>
            bookedDates.has(day.date) ||
            (day.open.length > 0 && pool.some((practitioner) => (practitioner.schedule[String(isoWeekday(day.date))]?.length ?? 0) > 0)),
    );

    return shown.length > 0 ? shown : days;
}
