/**
 * Heure actuelle dans le fuseau du site (jamais celui du navigateur), pour la ligne « maintenant » du planning.
 * `nowInZone` et `timeLineOffset` sont pures et testées ; `useClock` partage une seule minuterie entre tous les composants.
 */
import { onBeforeUnmount, onMounted, reactive, readonly } from 'vue';

export interface ZonedNow {
    /** Jour « AAAA-MM-JJ » dans le fuseau. */
    date: string;
    /** Minutes depuis minuit dans le fuseau (avec fraction de minute ignorée). */
    minutes: number;
}

export function nowInZone(timeZone: string, at: Date = new Date()): ZonedNow {
    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(at);
    const get = (type: string): string => parts.find((part) => part.type === type)?.value ?? '00';

    return { date: `${get('year')}-${get('month')}-${get('day')}`, minutes: Number(get('hour')) * 60 + Number(get('minute')) };
}

/** Minutes → « 10:07 ». */
export function clockLabel(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Position de la ligne (en minutes depuis le haut de la grille), ou null quand l'heure est hors de la plage affichée. */
export function timeLineOffset(range: { from: number; to: number }, minutes: number): number | null {
    return minutes >= range.from && minutes < range.to ? minutes - range.from : null;
}

/** Une minuterie par fuseau, partagée par toutes les lignes et étiquettes : un seul calcul toutes les 30 secondes. */
const clocks = new Map<string, { state: ZonedNow; timer: ReturnType<typeof setInterval> | null; users: number }>();
const TICK_MS = 30_000;

function entry(timeZone: string): { state: ZonedNow; timer: ReturnType<typeof setInterval> | null; users: number } {
    let found = clocks.get(timeZone);

    if (found === undefined) {
        found = { state: reactive(nowInZone(timeZone)), timer: null, users: 0 };
        clocks.set(timeZone, found);
    }

    return found;
}

function refresh(timeZone: string): void {
    Object.assign(entry(timeZone).state, nowInZone(timeZone));
}

/** Heure du site, mise à jour toutes les 30 secondes et au retour sur l'onglet ; s'arrête quand plus aucun composant ne l'utilise. */
export function useClock(timeZone: string): Readonly<ZonedNow> {
    const clock = entry(timeZone);
    // Mise à jour dès l'installation : jamais une première image avec une heure périmée.
    refresh(timeZone);
    const onVisible = (): void => {
        if (document.visibilityState === 'visible') {
            refresh(timeZone);
        }
    };

    onMounted(() => {
        clock.users += 1;
        refresh(timeZone);

        if (clock.timer === null) {
            clock.timer = setInterval(() => refresh(timeZone), TICK_MS);
        }

        document.addEventListener('visibilitychange', onVisible);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        clock.users -= 1;

        if (clock.users <= 0 && clock.timer !== null) {
            clearInterval(clock.timer);
            clock.timer = null;
        }
    });

    return readonly(clock.state);
}
