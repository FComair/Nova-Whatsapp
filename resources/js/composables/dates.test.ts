import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime, formatWeekdayDate } from './dates';

describe('dates', () => {
    it('affiche un jour sans heure en JJ/MM/AAAA, sans décalage de fuseau', () => {
        expect(formatDate('2026-10-07')).toBe('07/10/2026');
        expect(formatDate('2026-01-01')).toBe('01/01/2026');
    });

    it('affiche la même date quelle que soit la langue', () => {
        expect(formatWeekdayDate('2026-10-07', 'en')).toBe('Wednesday 07/10/2026');
        expect(formatWeekdayDate('2026-10-07', 'fr')).toBe('mercredi 07/10/2026');
        expect(formatWeekdayDate('2026-10-07', 'nl')).toBe('woensdag 07/10/2026');
    });

    it("ajoute l'heure en HH:MM", () => {
        expect(formatDateTime(new Date(2026, 9, 7, 9, 5))).toBe('07/10/2026 09:05');
    });
});
