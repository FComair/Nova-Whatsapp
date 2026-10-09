/**
 * Pourboires côté formulaire : lecture d'un montant saisi, répartition entre praticiens et contrôle que les parts totalisent le
 * pourboire. Mêmes règles que le serveur (BookingTips), qui revérifie tout.
 */

/** Saisie « 5 », « 5,5 », « 5.50 » → centimes ; null si ce n'est pas un montant valide (au plus 2 décimales, pas de signe). */
export function parseAmountCents(input: string): number | null {
    const cleaned = input.trim().replace(/[  €]/g, '');
    const match = /^(\d{1,7})(?:[.,](\d{1,2}))?$/.exec(cleaned);

    if (match === null) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0'));
}

export type ShareState = 'balanced' | 'short' | 'over' | 'invalid';

export interface ShareBalance {
    state: ShareState;
    /** Total des parts saisies (centimes) ; les parts vides comptent pour zéro. */
    allocated: number;
    /** Reste à répartir (positif) ou dépassement (négatif), en centimes. */
    remaining: number;
}

/** Compare les parts saisies (par praticien) au pourboire : « balanced » quand elles totalisent exactement le pourboire. */
export function shareBalance(totalCents: number, shares: Record<string, string>): ShareBalance {
    let allocated = 0;

    for (const value of Object.values(shares)) {
        if (value.trim() === '') {
            continue;
        }

        const cents = parseAmountCents(value);

        if (cents === null) {
            return { state: 'invalid', allocated, remaining: totalCents - allocated };
        }

        allocated += cents;
    }

    const remaining = totalCents - allocated;

    return { state: remaining === 0 ? 'balanced' : remaining > 0 ? 'short' : 'over', allocated, remaining };
}

/** Parts à envoyer au serveur : seulement celles qui sont renseignées et positives. */
export function sharesToPayload(shares: Record<string, string>): { practitioner_id: string; amount: string }[] {
    return Object.entries(shares)
        .filter(([, amount]) => (parseAmountCents(amount) ?? 0) > 0)
        .map(([practitioner_id, amount]) => ({ practitioner_id, amount }));
}

/** Total payé = encaissé pour le service + pourboire. Le pourboire n'est jamais inclus dans l'encaissé du service. */
export function totalPaid(collectedCents: number, tipCents: number): number {
    return collectedCents + tipCents;
}
