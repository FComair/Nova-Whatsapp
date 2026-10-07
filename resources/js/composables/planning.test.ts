import { describe, expect, it } from 'vitest';
import { blockStyle, hourMarks, isoWeekday, layoutLanes, minutesToTime, shiftDate, visibleDays } from './planning';

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

    it('place côte à côte les rendez-vous qui se chevauchent', () => {
        const layout = layoutLanes([
            { id: 'a', start_minute: 600, end_minute: 660 },
            { id: 'b', start_minute: 630, end_minute: 690 },
            { id: 'c', start_minute: 720, end_minute: 750 },
        ]);

        expect(layout.a).toEqual({ lane: 0, lanes: 2 });
        expect(layout.b).toEqual({ lane: 1, lanes: 2 });
        expect(layout.c).toEqual({ lane: 0, lanes: 1 });
    });

    it('réutilise une colonne libérée dans un même groupe', () => {
        const layout = layoutLanes([
            { id: 'a', start_minute: 600, end_minute: 720 },
            { id: 'b', start_minute: 600, end_minute: 630 },
            { id: 'c', start_minute: 640, end_minute: 700 },
        ]);

        expect(layout.b.lane).toBe(0);
        expect(layout.c.lane).toBe(0);
        expect(layout.a).toEqual({ lane: 1, lanes: 2 });
    });

    it('donne le jour ISO de la semaine', () => {
        expect(isoWeekday('2026-06-01')).toBe(1);
        expect(isoWeekday('2026-06-07')).toBe(7);
    });

    describe('jours affichés', () => {
        const open = [{ start: 540, end: 1020 }];
        const days = ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07'].map((date, index) => ({
            date,
            open: index < 5 ? open : [],
        }));
        const anne = { id: 'a', schedule: { '1': open, '2': open, '4': open } };
        const luc = { id: 'l', schedule: { '5': open, '6': open } };

        it("garde les jours d'ouverture où quelqu'un travaille", () => {
            expect(visibleDays(days, [anne, luc], new Set()).map((day) => day.date)).toEqual(['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05']);
        });

        it('ne garde que les jours de travail du praticien filtré', () => {
            expect(visibleDays(days, [anne, luc], new Set(), 'a').map((day) => day.date)).toEqual(['2026-06-01', '2026-06-02', '2026-06-04']);
        });

        it('garde un jour fermé qui a déjà un rendez-vous', () => {
            expect(visibleDays(days, [anne], new Set(['2026-06-06'])).map((day) => day.date)).toContain('2026-06-06');
        });

        it("affiche toute la semaine quand aucun horaire n'est saisi", () => {
            expect(visibleDays(days, [], new Set())).toHaveLength(7);
        });
    });
});
