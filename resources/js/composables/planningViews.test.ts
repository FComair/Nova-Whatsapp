import { describe, expect, it } from 'vitest';
import { columnMode, gridMinWidth, hidesClosedDays, parseView, stepDays, stepKey } from './planningViews';

describe('planningViews', () => {
    it('retombe sur la semaine pour une vue inconnue', () => {
        expect(parseView('3days')).toBe('3days');
        expect(parseView('practitioners')).toBe('practitioners');
        expect(parseView('mois')).toBe('week');
        expect(parseView(undefined)).toBe('week');
    });

    it('choisit des colonnes de praticiens seulement quand plusieurs sont affichés', () => {
        expect(columnMode('practitioners', 3)).toBe('practitioners');
        expect(columnMode('practitioners', 2)).toBe('practitioners');
        expect(columnMode('practitioners', 1)).toBe('days');
        expect(columnMode('week', 3)).toBe('days');
        expect(columnMode('day', 3)).toBe('days');
    });

    it('décale la date selon la vue', () => {
        expect(stepDays('day', 4)).toBe(1);
        expect(stepDays('3days', 4)).toBe(3);
        expect(stepDays('week', 4)).toBe(7);
        expect(stepDays('practitioners', 3)).toBe(1);
        expect(stepDays('practitioners', 1)).toBe(7);
    });

    it('donne la bonne clé de traduction de navigation', () => {
        expect(stepKey('day', 2)).toBe('day');
        expect(stepKey('3days', 2)).toBe('days3');
        expect(stepKey('week', 2)).toBe('week');
        expect(stepKey('practitioners', 1)).toBe('week');
        expect(stepKey('practitioners', 2)).toBe('day');
    });

    it('masque les jours fermés seulement en semaine de travail et en vue praticiens', () => {
        expect(hidesClosedDays('week')).toBe(true);
        expect(hidesClosedDays('practitioners')).toBe(true);
        expect(hidesClosedDays('day')).toBe(false);
        expect(hidesClosedDays('3days')).toBe(false);
    });

    it('calcule une largeur minimale selon la vue et le nombre de colonnes', () => {
        expect(gridMinWidth(1, 'day')).toBe(376);
        expect(gridMinWidth(3, '3days')).toBe(656);
        expect(gridMinWidth(5, 'week')).toBe(716);
        expect(gridMinWidth(0, 'week')).toBe(188);
    });
});
