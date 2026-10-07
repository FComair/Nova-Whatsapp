/** Format de date unique de l'application : JJ/MM/AAAA (et JJ/MM/AAAA HH:MM avec l'heure), quelle que soit la langue. */
const pad = (value: number): string => String(value).padStart(2, '0');

type Input = string | Date;

/** Un « 2026-10-07 » (jour sans heure) est lu à midi pour qu'aucun fuseau ne le décale d'un jour. */
function toDate(value: Input): Date {
    if (value instanceof Date) {
        return value;
    }

    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T12:00:00`) : new Date(value);
}

/** « 07/10/2026 ». Avec `utc`, le jour est celui du calendrier UTC (dates déjà converties côté serveur). */
export function formatDate(value: Input, utc = false): string {
    const date = toDate(value);

    return utc
        ? `${pad(date.getUTCDate())}/${pad(date.getUTCMonth() + 1)}/${date.getUTCFullYear()}`
        : `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
}

/** « 07/10/2026 14:30 ». */
export function formatDateTime(value: Input): string {
    const date = toDate(value);

    return `${formatDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** « jeudi 07/10/2026 » : nom du jour dans la langue, puis la date numérique. */
export function formatWeekdayDate(value: Input, locale: string, utc = false): string {
    const weekday = new Intl.DateTimeFormat(locale, { weekday: 'long', ...(utc ? { timeZone: 'UTC' } : {}) }).format(toDate(value));

    return `${weekday} ${formatDate(value, utc)}`;
}
