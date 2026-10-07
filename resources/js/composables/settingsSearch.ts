/**
 * Recherche dans les Réglages, faite dans le navigateur sur un index qui contient les textes des trois langues.
 * Une requête est comparée aux trois langues à la fois, quelle que soit la langue de l'interface.
 */

export type SearchLocale = 'fr' | 'nl' | 'en';

export const SEARCH_LOCALES: SearchLocale[] = ['fr', 'nl', 'en'];

export type Localized = Record<SearchLocale, string>;

export interface SearchEntry {
    id: string;
    group: string;
    page: string;
    href: string;
    anchor: string | null;
    title: Localized;
    description: Localized | null;
    keywords: Localized;
}

export type MatchField = 'title' | 'keyword' | 'description';

export interface SearchResult {
    entry: SearchEntry;
    score: number;
    /** Langue dans laquelle la meilleure correspondance a été trouvée. */
    locale: SearchLocale;
    field: MatchField;
    /** Mot de l'index qui correspond, quand il vient d'une autre langue que celle de l'interface (affiché dans une pastille). */
    matchedWord: string | null;
}

export interface Segment {
    text: string;
    hit: boolean;
}

export const MIN_QUERY_LENGTH = 2;
export const MAX_RESULTS = 8;

/** Minuscules, sans accents ni ponctuation : « Employé-e » → « employe e ». */
export function normalize(text: string): string {
    return Array.from(text)
        .map(foldChar)
        .join('')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim();
}

/** Une lettre sans accent, toujours une seule lettre en sortie pour garder les positions du texte d'origine. */
function foldChar(char: string): string {
    const folded = char.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

    return Array.from(folded).length === 1 ? folded : char.toLowerCase();
}

/** Racine légère, commune aux trois langues : pluriels et « e » final (employé, employés, employee, employees → employ). */
export function stem(word: string): string {
    let root = word;

    if (root.length > 5 && root.endsWith('en')) {
        root = root.slice(0, -2);
    } else if (root.length > 3 && (root.endsWith('s') || root.endsWith('x'))) {
        root = root.slice(0, -1);
    }

    return root.length > 3 ? root.replace(/e+$/, '') : root;
}

/** Distance d'édition (insertion, suppression, remplacement, échange de deux lettres voisines), arrêtée au-delà de `max`. */
export function distance(a: string, b: string, max: number): number {
    if (Math.abs(a.length - b.length) > max) {
        return max + 1;
    }

    const rows: number[][] = [];

    for (let i = 0; i <= a.length; i++) {
        rows[i] = [i];
    }

    for (let j = 1; j <= b.length; j++) {
        rows[0]![j] = j;
    }

    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            let best = Math.min(rows[i - 1]![j]! + 1, rows[i]![j - 1]! + 1, rows[i - 1]![j - 1]! + cost);

            if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
                best = Math.min(best, rows[i - 2]![j - 2]! + 1);
            }

            rows[i]![j] = best;
        }
    }

    return rows[a.length]![b.length]!;
}

/** Fautes tolérées : aucune sous 5 lettres, une jusqu'à 8, deux à partir de 9. */
function allowedTypos(length: number): number {
    return length < 5 ? 0 : length < 9 ? 1 : 2;
}

type Kind = 'exact' | 'prefix' | 'typo' | null;

/** Comment un mot de la requête correspond à un mot de l'index. */
function wordMatch(query: string, token: string): Kind {
    if (token === query || stem(token) === stem(query)) {
        return 'exact';
    }

    if (query.length >= MIN_QUERY_LENGTH && token.startsWith(query)) {
        return 'prefix';
    }

    const typos = allowedTypos(query.length);

    if (typos > 0 && (distance(query, token, typos) <= typos || distance(stem(query), stem(token), typos) <= typos)) {
        return 'typo';
    }

    return null;
}

interface FieldMatch {
    score: number;
    word: string | null;
}

/** Correspondance d'une requête avec un texte : tous les mots de la requête doivent être trouvés. */
function matchField(words: string[], text: string, base: { exact: number; prefix: number; typo: number }): FieldMatch | null {
    const normalized = normalize(text);

    if (normalized === '') {
        return null;
    }

    const tokens = normalized.split(' ');
    let score = Infinity;
    let first: string | null = null;

    for (const word of words) {
        let best: { kind: Kind; token: string } | null = null;

        for (const token of tokens) {
            const kind = wordMatch(word, token);

            if (kind !== null && (best === null || rank(kind) > rank(best.kind))) {
                best = { kind, token };
            }
        }

        if (best === null) {
            return null;
        }

        first ??= best.token;
        score = Math.min(score, base[best.kind as 'exact' | 'prefix' | 'typo']);
    }

    return { score, word: first };
}

function rank(kind: Kind): number {
    return kind === 'exact' ? 3 : kind === 'prefix' ? 2 : kind === 'typo' ? 1 : 0;
}

const TITLE = { exact: 60, prefix: 55, typo: 20 };
const KEYWORD = { exact: 45, prefix: 40, typo: 18 };
const DESCRIPTION = { exact: 30, prefix: 27, typo: 12 };

/** Texte d'origine du mot de l'index qui correspond (pour la pastille de langue). */
function originalWord(text: string, normalizedWord: string | null): string | null {
    if (normalizedWord === null) {
        return null;
    }

    return text.split(/[^\p{L}\p{N}]+/u).find((part) => normalize(part) === normalizedWord) ?? normalizedWord;
}

function bestForLocale(
    entry: SearchEntry,
    words: string[],
    phrase: string,
    locale: SearchLocale,
): { score: number; field: MatchField; word: string | null } | null {
    const title = entry.title[locale];
    const normalizedTitle = normalize(title);
    let best: { score: number; field: MatchField; word: string | null } | null = null;

    const consider = (score: number, field: MatchField, word: string | null): void => {
        if (best === null || score > best.score) {
            best = { score, field, word };
        }
    };

    const inTitle = matchField(words, title, TITLE);
    const titleWord = inTitle ? originalWord(title, inTitle.word) : null;

    if (normalizedTitle === phrase) {
        consider(100, 'title', titleWord);
    } else if (normalizedTitle.startsWith(phrase)) {
        consider(80, 'title', titleWord);
    }

    if (inTitle) {
        consider(inTitle.score, 'title', titleWord);
    }

    const inKeywords = matchField(words, entry.keywords[locale], KEYWORD);

    if (inKeywords) {
        consider(inKeywords.score, 'keyword', originalWord(entry.keywords[locale], inKeywords.word));
    }

    const description = entry.description?.[locale] ?? '';
    const inDescription = description === '' ? null : matchField(words, description, DESCRIPTION);

    if (inDescription) {
        consider(inDescription.score, 'description', originalWord(description, inDescription.word));
    }

    return best;
}

/**
 * Cherche dans l'index. Classement : titre exact, titre qui commence par la requête, mot du titre, mot-clé, description, faute de
 * frappe ; à égalité, ordre alphabétique des titres dans la langue de l'interface. Les résultats sont ensuite regroupés par
 * groupe du menu (le groupe du meilleur résultat d'abord) et limités à `limit`.
 */
export function searchEntries(entries: SearchEntry[], query: string, locale: SearchLocale, limit = MAX_RESULTS): SearchResult[] {
    const phrase = normalize(query);
    const words = phrase.split(' ').filter((word) => word.length > 0);

    if (phrase.length < MIN_QUERY_LENGTH) {
        return [];
    }

    const found: SearchResult[] = [];

    for (const entry of entries) {
        let best: SearchResult | null = null;

        // La langue de l'interface passe en premier : à score égal, elle l'emporte sur les autres.
        for (const candidate of [locale, ...SEARCH_LOCALES.filter((other) => other !== locale)]) {
            const match = bestForLocale(entry, words, phrase, candidate);

            if (match !== null && (best === null || match.score > best.score)) {
                best = {
                    entry,
                    score: match.score,
                    locale: candidate,
                    field: match.field,
                    matchedWord: candidate === locale ? null : match.word,
                };
            }
        }

        if (best !== null) {
            found.push(best);
        }
    }

    found.sort((a, b) => b.score - a.score || a.entry.title[locale].localeCompare(b.entry.title[locale], locale));

    return groupByMenu(found.slice(0, limit));
}

/** Même ordre de groupes que le menu ; à l'intérieur d'un groupe, l'ordre du classement est conservé. */
function groupByMenu(results: SearchResult[]): SearchResult[] {
    const firstSeen = new Map<string, number>();

    results.forEach((result, index) => {
        if (!firstSeen.has(result.entry.group)) {
            firstSeen.set(result.entry.group, index);
        }
    });

    return [...results].sort((a, b) => {
        const byGroup = (firstSeen.get(a.entry.group) ?? 0) - (firstSeen.get(b.entry.group) ?? 0);

        return byGroup !== 0 ? byGroup : results.indexOf(a) - results.indexOf(b);
    });
}

/** Découpe un texte pour mettre en évidence les mots qui correspondent à la requête. */
export function highlight(text: string, query: string): Segment[] {
    const words = normalize(query)
        .split(' ')
        .filter((word) => word.length > 0);

    if (words.length === 0) {
        return [{ text, hit: false }];
    }

    const folded = Array.from(text).map(foldChar);
    const chars = Array.from(text);
    const flags = chars.map(() => false);
    const normalizedChars = folded.map((char) => (/[\p{L}\p{N}]/u.test(char) ? char : ' '));

    let index = 0;

    while (index < chars.length) {
        if (normalizedChars[index] === ' ') {
            index++;
            continue;
        }

        let end = index;

        while (end < chars.length && normalizedChars[end] !== ' ') {
            end++;
        }

        const token = normalizedChars.slice(index, end).join('');

        for (const word of words) {
            const kind = wordMatch(word, token);

            if (kind === 'prefix') {
                flags.fill(true, index, index + word.length);
            } else if (kind !== null) {
                flags.fill(true, index, end);
            }
        }

        index = end;
    }

    const segments: Segment[] = [];

    chars.forEach((char, position) => {
        const last = segments[segments.length - 1];

        if (last !== undefined && last.hit === flags[position]) {
            last.text += char;
        } else {
            segments.push({ text: char, hit: flags[position]! });
        }
    });

    return segments;
}
