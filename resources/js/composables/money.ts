/** Formate des centimes en montant localisé (« 40,00 € » en français, « €40.00 » en anglais). */
export function formatPrice(cents: number, locale: string, currency = 'EUR'): string {
    return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(cents / 100);
}

/**
 * Centimes → saisie pour un champ (« 40 » ou « 40,50 »), avec la virgule ou le point de la langue.
 * Les montants ronds n'affichent pas de décimales.
 */
export function centsToInput(cents: number, locale: string): string {
    const separator = new Intl.NumberFormat(locale).format(1.1).charAt(1);
    const euros = Math.floor(cents / 100);
    const remainder = cents % 100;

    return remainder === 0 ? String(euros) : `${euros}${separator}${String(remainder).padStart(2, '0')}`;
}
