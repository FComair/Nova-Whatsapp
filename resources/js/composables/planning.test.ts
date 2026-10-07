import { describe, expect, it } from 'vitest';
import { blockStyle, hourMarks, minutesToTime, shiftDate } from './planning';

describe('planning', () => {
    it('formate les minutes en heure', () => {
        expect(minutesToTime(0)).toBe('00:00');
        expect(minutesToTime(9 * 60 + 5)).toBe('09:05');
        expect(minutesToTime(17 * 60 + 30)).toBe('17:30');
    });

    it('positionne un bloc dans la grille', () => {
        expect(blockStyle({ from: 540 }, 600, 630)).toEqual({ top: '60px', height: '30px' });
    });

    it('garde une hauteur minimale aux blocs très courts', () => {
        expect(blockStyle({ from: 540 }, 540, 545).height).toBe('24px');
    });

    it('liste les heures entières de la plage', () => {
        expect(hourMarks({ from: 540, to: 720 })).toEqual([540, 600, 660]);
        expect(hourMarks({ from: 570, to: 700 })).toEqual([600, 660]);
    });

    it("décale une date, y compris autour du changement d'heure", () => {
        expect(shiftDate('2026-06-01', 1)).toBe('2026-06-02');
        expect(shiftDate('2026-03-01', -1)).toBe('2026-02-28');
        expect(shiftDate('2026-03-28', 1)).toBe('2026-03-29');
        expect(shiftDate('2026-12-31', 1)).toBe('2027-01-01');
    });
});
