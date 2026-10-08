import { describe, expect, it } from 'vitest';
import { STARTER_VIEWS, activeFilterCount, buildQuery, cleanFilters, initials, matchingView, normalizeColumns, sameFilters } from './customerList';

describe('customerList', () => {
    it('retire les filtres vides et le nombre de jours sans objet', () => {
        expect(cleanFilters({ status: '', appt: 'upcoming', days: 90, tag: '' })).toEqual({ appt: 'upcoming' });
        expect(cleanFilters({ appt: 'inactive', days: 60 })).toEqual({ appt: 'inactive', days: 60 });
    });

    it('compte les filtres actifs', () => {
        expect(activeFilterCount({})).toBe(0);
        expect(activeFilterCount({ appt: 'inactive', days: 90, consent: 'opted_out' })).toBe(3);
    });

    it("compare deux jeux de filtres sans tenir compte de l'ordre ni des vides", () => {
        expect(sameFilters({ appt: 'no_show', tag: '' }, { appt: 'no_show' })).toBe(true);
        expect(sameFilters({ appt: 'no_show' }, { appt: 'upcoming' })).toBe(false);
    });

    it("n'envoie que ce qui change dans l'URL", () => {
        expect(buildQuery({ q: ' ', filters: {}, sort: 'name', dir: 'asc', page: 1 })).toEqual({});
        expect(buildQuery({ q: 'léa', filters: { tag: 'vip' }, sort: 'value', dir: 'desc', page: 3 })).toEqual({
            q: 'léa',
            tag: 'vip',
            sort: 'value',
            dir: 'desc',
            page: 3,
        });
    });

    it('ignore les colonnes inconnues et retombe sur le défaut', () => {
        expect(normalizeColumns(['next', 'zzz', 'contact'])).toEqual(['contact', 'next']);
        expect(normalizeColumns(['zzz'])).toContain('last');
        expect(normalizeColumns(null)).toContain('contact');
    });

    it('calcule les initiales', () => {
        expect(initials('Sophie Anne Martin', '+32470123456')).toBe('SM');
        expect(initials('Zoé', '+32470123456')).toBe('ZO');
        expect(initials(null, '+32470123456')).toBe('56');
    });

    it("reconnaît la vue qui correspond à l'écran", () => {
        expect(matchingView({ q: '', filters: { appt: 'upcoming' } }, STARTER_VIEWS, [])).toBe('starter:upcoming');
        expect(matchingView({ q: '', filters: { tag: 'vip' } }, STARTER_VIEWS, [{ id: 'abc', filters: { tag: 'vip' } }])).toBe('saved:abc');
        expect(matchingView({ q: 'x', filters: {} }, STARTER_VIEWS, [])).toBeNull();
    });
});
