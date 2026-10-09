/** Taux de TVA saisi à la main : virgule ou point, signe % toléré, de 0 à 100 avec deux décimales au plus. Pur et testé. */

/** Le nombre saisi, ou null s'il n'est pas un taux valide. */
export function parseVat(input: string): number | null {
    const clean = input.replace(/[\s%]+/g, '').replace(',', '.');

    if (!/^\d{1,3}(\.\d{1,2})?$/.test(clean)) {
        return null;
    }

    const rate = Number(clean);

    return rate >= 0 && rate <= 100 ? rate : null;
}

/** « 21 % » ou « 21,5 % » selon la langue (séparateur décimal local). */
export function formatVat(rate: number, locale: string): string {
    return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(rate)} %`;
}
