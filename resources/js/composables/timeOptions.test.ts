import { describe, expect, it } from 'vitest';
import { endOptions, rangeIsValid, startOptions, timeOptions, toMinutes } from './timeOptions';

describe('liste d’heures', () => {
    it('est un tableau fini, ordonné, de quart d’heure en quart d’heure', () => {
        const options = timeOptions('08:00', '10:00');

        expect(options).toEqual(['08:00', '08:15', '08:30', '08:45', '09:00', '09:15', '09:30', '09:45', '10:00']);
        expect(timeOptions('00:00', '23:59')).toHaveLength(96);
    });

    it('reste dans les bornes données et arrondit le minimum au pas suivant', () => {
        expect(timeOptions('09:01', '09:50')).toEqual(['09:15', '09:30', '09:45']);
        expect(timeOptions('18:00', '09:00')).toEqual([]);
    });

    it('garde toujours la valeur actuelle, même hors pas, sans doublon', () => {
        expect(timeOptions('09:00', '09:30', '09:10')).toEqual(['09:00', '09:10', '09:15', '09:30']);
        expect(timeOptions('09:00', '09:30', '09:15')).toEqual(['09:00', '09:15', '09:30']);
        expect(timeOptions('08:00', '08:30', '23:59')).toContain('23:59');
    });

    it('ignore une valeur actuelle qui n’est pas une heure', () => {
        expect(timeOptions('09:00', '09:30', '25:00')).toEqual(['09:00', '09:15', '09:30']);
        expect(timeOptions('09:00', '09:30', '')).toEqual(['09:00', '09:15', '09:30']);
    });

    it('lit une heure', () => {
        expect(toMinutes('09:30')).toBe(570);
        expect(toMinutes('24:00')).toBeNull();
        expect(toMinutes('9:30')).toBeNull();
    });
});

describe('début et fin d’une plage', () => {
    it('ne propose comme début que des heures avant la fin choisie', () => {
        const options = startOptions('09:00', '10:00');

        expect(options[0]).toBe('00:00');
        expect(options.at(-1)).toBe('09:45');
        expect(options).not.toContain('10:00');
    });

    it('ne propose comme fin que des heures après le début choisi', () => {
        const options = endOptions('09:00', '18:00');

        expect(options[0]).toBe('09:15');
        expect(options).toContain('18:00');
        expect(options).not.toContain('09:00');
        expect(options).not.toContain('08:45');
    });

    it('respecte les bornes de la journée de travail quand il y en a', () => {
        const bounds = { start: '08:00', end: '20:00' };

        expect(startOptions('09:00', '18:00', bounds)[0]).toBe('08:00');
        expect(endOptions('09:00', '18:00', bounds).at(-1)).toBe('20:00');
        expect(endOptions('09:00', '18:00', bounds)).not.toContain('20:15');
    });

    it('garde l’horaire déjà enregistré visible, même s’il est hors pas', () => {
        expect(startOptions('09:10', '18:00')).toContain('09:10');
        expect(endOptions('09:00', '23:59')).toContain('23:59');
    });
});

describe('contrôle fin après début', () => {
    it('refuse une fin égale ou antérieure au début', () => {
        expect(rangeIsValid({ start: '09:00', end: '18:00' })).toBe(true);
        expect(rangeIsValid({ start: '09:00', end: '09:00' })).toBe(false);
        expect(rangeIsValid({ start: '18:00', end: '09:00' })).toBe(false);
        expect(rangeIsValid({ start: '', end: '09:00' })).toBe(false);
    });
});
