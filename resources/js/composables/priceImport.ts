/** Aides de la page de vérification d'un import de liste de prix. */

export interface ReviewRow {
    key: string;
    name: string;
    /** Prix tel que saisi (« 38,50 »). */
    price: string;
    category: string;
    duration: string;
    durationGuessed: boolean;
    vat: number | null;
    action: 'new' | 'update' | 'ignore';
    serviceId: string | null;
    warnings: string[];
    ignoredReason: string | null;
    suggestion: { service_id: string; name: string; score: number } | null;
    selected: boolean;
}

/** Avertissements qui demandent un regard de l'utilisateur (les autres sont de simples informations). */
const CHECK = ['price_zero', 'price_low', 'price_high', 'low_confidence', 'duplicate'];

/**
 * Saisie d'un prix → centimes, ou null si elle n'est pas valide (« 38 », « 38,5 », « 38.50 », « € 38,50 » ; 99 999,99 au plus).
 */
export function parsePriceInput(text: string): number | null {
    const clean = text.replace(/[€\s]/g, '');

    if (!/^\d{1,5}([.,]\d{1,2})?$/.test(clean)) {
        return null;
    }

    return Math.round(Number(clean.replace(',', '.')) * 100);
}

/** Une ligne qui doit être regardée : prix douteux, lecture incertaine ou doublon (les lignes ignorées ne comptent pas). */
export function needsCheck(row: Pick<ReviewRow, 'action' | 'warnings'>): boolean {
    return row.action !== 'ignore' && row.warnings.some((warning) => CHECK.includes(warning));
}

export interface Summary {
    added: number;
    updated: number;
    ignored: number;
    toCheck: number;
}

/** Compteurs de la barre de résumé, recalculés à chaque modification. */
export function summarize(rows: Pick<ReviewRow, 'action' | 'warnings'>[]): Summary {
    return {
        added: rows.filter((row) => row.action === 'new').length,
        updated: rows.filter((row) => row.action === 'update').length,
        ignored: rows.filter((row) => row.action === 'ignore').length,
        toCheck: rows.filter(needsCheck).length,
    };
}
