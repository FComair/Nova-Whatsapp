/** Recherche dans le centre d'aide : sans accents ni majuscules, tous les mots doivent se retrouver. Pure et testée. */

export interface HelpEntry {
    category: string;
    slug: string;
    title: string;
    summary: string;
    tags: string[];
    text: string;
}

/** Minuscules et sans accents : « Réservé » et « reserve » se valent. */
export function fold(value: string): string {
    return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

export const MIN_QUERY = 2;

export function terms(query: string): string[] {
    return fold(query)
        .split(/[^a-z0-9+]+/)
        .filter((term) => term.length > 0);
}

/**
 * Articles qui contiennent tous les mots saisis, les meilleurs d'abord : un mot dans le titre compte le plus, puis les étiquettes,
 * le résumé et enfin le corps. Moins de 2 caractères : aucun résultat.
 */
export function searchHelp(entries: HelpEntry[], query: string, limit = 50): HelpEntry[] {
    const words = terms(query);

    if (query.trim().length < MIN_QUERY || words.length === 0) {
        return [];
    }

    const scored: { entry: HelpEntry; score: number }[] = [];

    for (const entry of entries) {
        const title = fold(entry.title);
        const tags = fold(entry.tags.join(' '));
        const summary = fold(entry.summary);
        const text = fold(entry.text);
        let score = 0;
        let matchesAll = true;

        for (const word of words) {
            const inTitle = title.includes(word);
            const inTags = tags.includes(word);
            const inSummary = summary.includes(word);
            const inText = text.includes(word);

            if (!inTitle && !inTags && !inSummary && !inText) {
                matchesAll = false;
                break;
            }

            // Un mot qui commence un mot du titre vaut un peu plus qu'un mot trouvé au milieu.
            const startsTitleWord = inTitle && title.split(/[^a-z0-9+]+/).some((part) => part.startsWith(word));

            score += (inTitle ? (startsTitleWord ? 12 : 10) : 0) + (inTags ? 6 : 0) + (inSummary ? 4 : 0) + (inText ? 1 : 0);
        }

        if (matchesAll) {
            scored.push({ entry, score });
        }
    }

    return scored
        .sort((a, b) => b.score - a.score || a.entry.title.localeCompare(b.entry.title))
        .slice(0, limit)
        .map((item) => item.entry);
}
