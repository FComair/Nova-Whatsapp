import { describe, expect, it } from 'vitest';
import { differsFrom, formatRangeWithZone, formatTimeWithZone, instantOf, toViewer } from './timezone';

describe('fuseau d’affichage', () => {
    it('convertit l’heure de l’établissement vers le fuseau de la personne', () => {
        expect(toViewer('2026-06-02', '10:00', 'Europe/Brussels', 'Asia/Tokyo')).toEqual({ date: '2026-06-02', time: '17:00', zone: 'Asia/Tokyo' });
    });

    it('change aussi le jour quand la conversion passe minuit', () => {
        expect(toViewer('2026-06-02', '22:00', 'Europe/Brussels', 'Asia/Tokyo')).toEqual({ date: '2026-06-03', time: '05:00', zone: 'Asia/Tokyo' });
    });

    it('respecte l’heure d’été', () => {
        expect(instantOf('2026-07-01', '12:00', 'Europe/Brussels')).toBe(Date.UTC(2026, 6, 1, 10, 0));
        expect(instantOf('2026-01-15', '12:00', 'Europe/Brussels')).toBe(Date.UTC(2026, 0, 15, 11, 0));
    });

    it('indique le fuseau', () => {
        expect(formatTimeWithZone('2026-01-15', '14:00', 'Europe/Brussels', 'Europe/Brussels')).toBe('14:00 CET');
        expect(formatTimeWithZone('2026-07-01', '14:00', 'Europe/Brussels', 'Europe/Brussels')).toBe('14:00 CEST');
        expect(formatRangeWithZone('2026-01-15', '14:00', '15:00', 'Europe/Brussels', 'Europe/Brussels')).toBe('14:00–15:00 CET');
    });

    it('garde l’heure de l’établissement, avec son sigle, quand le fuseau de la personne est inconnu', () => {
        expect(formatTimeWithZone('2026-01-15', '14:00', 'Europe/Brussels', null)).toBe('14:00 CET');
        expect(differsFrom('Europe/Brussels', null)).toBe(false);
        expect(differsFrom('Europe/Brussels', 'Asia/Tokyo')).toBe(true);
    });
});
