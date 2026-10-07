import { describe, expect, it } from 'vitest';
import { addHours, addRange, copyDay, removeRange, setOpen, toDays, toPayload } from './hours';

describe('hours', () => {
    it('construit les 7 jours, les jours absents étant fermés', () => {
        const days = toDays({ '1': [{ start: '09:00', end: '12:00' }], '3': [] });

        expect(days).toHaveLength(7);
        expect(days[0]).toMatchObject({ weekday: 1, open: true });
        expect(days[2]).toMatchObject({ weekday: 3, open: false, ranges: [] });
        expect(days[6]).toMatchObject({ weekday: 7, open: false });
    });

    it("n'envoie aucune plage pour un jour fermé", () => {
        const days = toDays({ '1': [{ start: '09:00', end: '12:00' }] });
        days[0] = { ...days[0], open: false };

        expect(toPayload(days)[0]).toEqual({ weekday: 1, ranges: [] });
    });

    it("propose une plage par défaut à l'ouverture d'un jour", () => {
        const day = setOpen({ weekday: 2, open: false, ranges: [] }, true);

        expect(day.open).toBe(true);
        expect(day.ranges).toEqual([{ start: '09:00', end: '18:00' }]);
    });

    it('ajoute une plage à la suite de la précédente, dans la limite de 3', () => {
        let day = setOpen({ weekday: 2, open: false, ranges: [{ start: '09:00', end: '12:00' }] }, true);
        day = addRange(day);
        expect(day.ranges[1]).toEqual({ start: '13:00', end: '15:00' });

        day = addRange(day);
        day = addRange(day);
        expect(day.ranges).toHaveLength(3);
    });

    it('ferme le jour quand la dernière plage est supprimée', () => {
        const day = removeRange({ weekday: 2, open: true, ranges: [{ start: '09:00', end: '12:00' }] }, 0);

        expect(day).toMatchObject({ open: false, ranges: [] });
    });

    it("copie un jour sur d'autres jours sans partager les objets", () => {
        const days = toDays({ '1': [{ start: '08:00', end: '17:00' }] });
        const copied = copyDay(days, 1, [2, 3, 4, 5]);

        expect(copied[3].ranges).toEqual([{ start: '08:00', end: '17:00' }]);
        expect(copied[5].open).toBe(false);

        copied[1].ranges[0].start = '10:00';
        expect(copied[0].ranges[0].start).toBe('08:00');
    });

    it('plafonne les heures à 23:59 (pas de plage après minuit)', () => {
        expect(addHours('22:30', 2)).toBe('23:59');
        expect(addHours('09:00', 1)).toBe('10:00');
    });
});
