import { computed, ref } from 'vue';

/**
 * Fuseau d'affichage de la personne connectée. Les rendez-vous sont enregistrés en UTC et planifiés dans le fuseau de
 * l'établissement (Location.timezone) ; ici on ne fait que convertir ce qui s'affiche, jamais ce qui est saisi.
 * Ordre : choix enregistré, sinon fuseau détecté par le navigateur, sinon rien (la personne est alors invitée à le choisir).
 */
export const TIMEZONE_STORAGE_KEY = 'nova-timezone';

const stored = ref<string | null>(null);
const detected = ref<string | null>(null);

export function isValidTimezone(value: unknown): value is string {
    if (typeof value !== 'string' || value === '') {
        return false;
    }

    try {
        new Intl.DateTimeFormat('en-GB', { timeZone: value });

        return true;
    } catch {
        return false;
    }
}

export function detectTimezone(): string | null {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return isValidTimezone(zone) ? zone : null;
    } catch {
        return null;
    }
}

/** À appeler une fois au démarrage. */
export function initTimezone(): void {
    detected.value = detectTimezone();

    try {
        const value = window.localStorage.getItem(TIMEZONE_STORAGE_KEY);
        stored.value = isValidTimezone(value) ? value : null;
    } catch {
        stored.value = null;
    }
}

export const viewerTimezone = computed<string | null>(() => stored.value ?? detected.value);

export function setViewerTimezone(zone: string): void {
    if (!isValidTimezone(zone)) {
        return;
    }

    stored.value = zone;

    try {
        window.localStorage.setItem(TIMEZONE_STORAGE_KEY, zone);
    } catch {
        // Stockage indisponible : le choix vaut pour cette session seulement.
    }
}

/** Décalage (en ms) du fuseau par rapport à UTC à l'instant donné. */
function offsetAt(zone: string, instant: number): number {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date(instant));
    const get = (type: string): number => Number(parts.find((part) => part.type === type)?.value ?? 0);

    return Date.UTC(get('year'), get('month') - 1, get('day'), get('hour'), get('minute'), get('second')) - Math.floor(instant / 1000) * 1000;
}

/** « 2026-06-02 » + « 10:00 » lus dans `zone` : l'instant correspondant (ms depuis 1970). */
export function instantOf(date: string, time: string, zone: string): number {
    const [y, m, d] = date.split('-').map(Number) as [number, number, number];
    const [h, min] = time.split(':').map(Number) as [number, number];
    const guess = Date.UTC(y, m - 1, d, h, min);
    const first = guess - offsetAt(zone, guess);

    return guess - offsetAt(zone, first);
}

/** Sigle court du fuseau à cette date (« CET », « CEST », « GMT+9 »), heure d'été comprise. */
export function zoneLabel(zone: string, instant: number = Date.now()): string {
    const name = new Intl.DateTimeFormat('en-GB', { timeZone: zone, timeZoneName: 'short' })
        .formatToParts(new Date(instant))
        .find((part) => part.type === 'timeZoneName')?.value;

    return name ?? zone;
}

export interface LocalMoment {
    date: string;
    time: string;
    zone: string;
}

/** Une date et une heure de l'établissement, converties dans le fuseau de la personne (ou laissées telles quelles si inconnu). */
export function toViewer(date: string, time: string, fromZone: string, viewer: string | null = viewerTimezone.value): LocalMoment {
    const zone = viewer !== null && isValidTimezone(fromZone) ? viewer : fromZone;

    if (!isValidTimezone(fromZone) || zone === fromZone) {
        return { date, time, zone: fromZone };
    }

    const instant = instantOf(date, time, fromZone);
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: zone,
        hourCycle: 'h23',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).formatToParts(new Date(instant));
    const get = (type: string): string => parts.find((part) => part.type === type)?.value ?? '00';

    return { date: `${get('year')}-${get('month')}-${get('day')}`, time: `${get('hour')}:${get('minute')}`, zone };
}

/** « 14:00 CET » : l'heure dans le fuseau de la personne, avec son sigle. */
export function formatTimeWithZone(date: string, time: string, fromZone: string, viewer: string | null = viewerTimezone.value): string {
    const moment = toViewer(date, time, fromZone, viewer);

    return `${moment.time} ${zoneLabel(moment.zone, instantOf(moment.date, moment.time, moment.zone))}`;
}

/** « 14:00–15:00 CET » (ou avec le jour entre parenthèses si la conversion change de date). */
export function formatRangeWithZone(date: string, start: string, end: string, fromZone: string, viewer: string | null = viewerTimezone.value): string {
    const from = toViewer(date, start, fromZone, viewer);
    const to = toViewer(date, end, fromZone, viewer);
    const label = zoneLabel(from.zone, instantOf(from.date, from.time, from.zone));

    return `${from.time}–${to.time} ${label}`;
}

/** Vrai quand la personne n'est pas dans le fuseau de l'établissement : les heures affichées sont alors converties. */
export function differsFrom(zone: string, viewer: string | null = viewerTimezone.value): boolean {
    return viewer !== null && viewer !== zone;
}

/** Tous les fuseaux IANA connus du navigateur, pour le sélecteur. */
export function allTimezones(): string[] {
    const intl = Intl as unknown as { supportedValuesOf?: (key: string) => string[] };

    try {
        return intl.supportedValuesOf?.('timeZone') ?? [];
    } catch {
        return [];
    }
}
