import { describe, expect, it } from 'vitest';
import { clockLabel, nowInZone, timeLineOffset } from './clock';

describe('clock', () => {
    it("lit l'heure dans le fuseau du site, pas celle du navigateur", () => {
        const instant = new Date('2026-10-09T08:07:30Z');

        expect(nowInZone('Europe/Brussels', instant)).toEqual({ date: '2026-10-09', minutes: 10 * 60 + 7 });
        expect(nowInZone('UTC', instant)).toEqual({ date: '2026-10-09', minutes: 8 * 60 + 7 });
        expect(nowInZone('America/New_York', instant)).toEqual({ date: '2026-10-09', minutes: 4 * 60 + 7 });
    });

    it('change de jour avec le fuseau', () => {
        const instant = new Date('2026-10-09T23:30:00Z');

        expect(nowInZone('Europe/Brussels', instant)).toEqual({ date: '2026-10-10', minutes: 60 + 30 });
        expect(nowInZone('UTC', instant).date).toBe('2026-10-09');
    });

    it("suit l'heure d'été et l'heure d'hiver", () => {
        expect(nowInZone('Europe/Brussels', new Date('2026-07-01T10:00:00Z')).minutes).toBe(12 * 60);
        expect(nowInZone('Europe/Brussels', new Date('2026-12-01T10:00:00Z')).minutes).toBe(11 * 60);
    });

    it('lit minuit comme 00:00 et non 24:00', () => {
        expect(nowInZone('Europe/Brussels', new Date('2026-10-09T22:00:00Z'))).toEqual({ date: '2026-10-10', minutes: 0 });
    });

    it('place la ligne dans la grille, ou nulle part hors de la plage', () => {
        const range = { from: 420, to: 1200 };

        expect(timeLineOffset(range, 607)).toBe(187);
        expect(timeLineOffset(range, 420)).toBe(0);
        expect(timeLineOffset(range, 419)).toBeNull();
        expect(timeLineOffset(range, 1200)).toBeNull();
        expect(timeLineOffset(range, 1290)).toBeNull();
    });

    it("formate l'heure", () => {
        expect(clockLabel(607)).toBe('10:07');
        expect(clockLabel(5)).toBe('00:05');
    });
});
