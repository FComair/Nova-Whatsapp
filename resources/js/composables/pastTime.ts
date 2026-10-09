/**
 * Pas de rendez-vous dans le passé, côté formulaire : mêmes règles que le serveur (heure du site, à la minute près), pour griser
 * les heures passées et bloquer l'envoi avant même de le tenter. Le serveur reste l'autorité : il revérifie tout.
 */
import { computed, type MaybeRefOrGetter, toValue } from 'vue';
import { nowInZone, useClock, type ZonedNow } from './clock';
import { toMinutes } from './timeOptions';
import { useT } from './useT';

/** Toute la journée est passée : aucune heure n'est encore possible. */
export const WHOLE_DAY = 24 * 60;

/** Première minute encore possible pour ce jour : null (aucune limite), l'heure actuelle (aujourd'hui) ou WHOLE_DAY (jour passé). */
export function minimumMinutes(date: string, now: ZonedNow): number | null {
    if (date === '' || date > now.date) {
        return null;
    }

    return date < now.date ? WHOLE_DAY : now.minutes;
}

/** Le début « date + heure » (heure du site) est-il déjà passé ? Faux tant que la date ou l'heure n'est pas complète. */
export function isPastStart(date: string, time: string, now: ZonedNow): boolean {
    const minutes = toMinutes(time);
    const minimum = minimumMinutes(date, now);

    return minutes !== null && minimum !== null && minutes < minimum;
}

interface Original {
    date: string;
    time: string;
}

/**
 * Contrôle « heure passée » d'un formulaire de rendez-vous, recalculé avec l'horloge du site (toutes les 30 secondes et au retour
 * sur l'onglet) : une heure qui passe pendant que le formulaire reste ouvert devient invalide d'elle-même.
 * `original` : l'horaire déjà enregistré d'un rendez-vous qu'on modifie. Tant qu'on ne change ni la date ni l'heure, il n'est pas contrôlé.
 */
export function usePastTimeGuard(
    timeZone: string,
    date: MaybeRefOrGetter<string>,
    time: MaybeRefOrGetter<string>,
    original?: MaybeRefOrGetter<Original | null>,
) {
    const { t } = useT();
    const clock = useClock(timeZone);
    const message = computed(() => t('planning.errors.past_time'));
    const unchanged = (): boolean => {
        const kept = toValue(original ?? null);

        return kept !== null && kept.date === toValue(date) && kept.time === toValue(time);
    };

    /** Première heure encore sélectionnable pour la date choisie (null : toutes). */
    const minMinutes = computed(() => minimumMinutes(toValue(date), clock));
    /** Jour passé : signalé sur le champ date, avant même d'avoir choisi une heure. */
    const dateError = computed(() => (minMinutes.value === WHOLE_DAY && !unchanged() ? message.value : undefined));
    /** Heure passée aujourd'hui. */
    const timeError = computed(() =>
        minMinutes.value !== WHOLE_DAY && isPastStart(toValue(date), toValue(time), clock) && !unchanged() ? message.value : undefined,
    );

    /** À l'envoi : relit l'heure maintenant (sans attendre le prochain tick) et dit si le début est encore valide. */
    function validNow(): boolean {
        if (unchanged()) {
            return true;
        }

        return !isPastStart(toValue(date), toValue(time), nowInZone(timeZone)) && !(minimumMinutes(toValue(date), nowInZone(timeZone)) === WHOLE_DAY);
    }

    return { minMinutes, dateError, timeError, message, validNow };
}
