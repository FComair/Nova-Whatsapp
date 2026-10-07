/** Initiales et couleur du repli affiché quand l'établissement n'a pas de logo. */

// Fonds sombres : le texte blanc garde un contraste d'au moins 4,5:1.
const COLORS = ['#0E6B5C', '#2450C8', '#7A3FB5', '#B3261E', '#8A5A00', '#1F6F8B', '#5B4B9A', '#3F6B1F'];

/** Deux lettres au plus : premières lettres des deux premiers mots (« Barbier Deux Test » → « BD »). */
export function shopInitials(name: string): string {
    const words = name
        .trim()
        .split(/[\s\-_'’.]+/u)
        .filter((word) => /\p{L}|\p{N}/u.test(word));

    if (words.length === 0) {
        return '?';
    }

    const letters =
        words.length === 1
            ? Array.from(words[0]!)
                  .filter((c) => /\p{L}|\p{N}/u.test(c))
                  .slice(0, 2)
            : words.slice(0, 2).map((word) => Array.from(word)[0]!);

    return letters.join('').toLocaleUpperCase();
}

/** Couleur stable pour un établissement : calculée depuis son identifiant, jamais aléatoire. */
export function shopColor(id: string): string {
    let hash = 0;

    for (const char of id) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return COLORS[hash % COLORS.length]!;
}

/** Adresse du logo ; le numéro de version change à chaque envoi, ce qui force le rechargement malgré le cache long. */
export function logoUrl(base: string, size: 64 | 256, version: number | null | undefined): string | null {
    return version ? `${base}/${size}?v=${version}` : null;
}
