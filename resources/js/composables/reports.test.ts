import { describe, expect, it } from 'vitest';
import type { ReportMetric } from '../types/reports';
import {
    activeFilterCount,
    bucketLabel,
    changeOf,
    filterQuery,
    formatMetric,
    formatMinutes,
    formatRate,
    heatmapTable,
    isEmptyReport,
    niceScale,
    queryString,
} from './reports';

const metric = (partial: Partial<ReportMetric>): ReportMetric => ({ value: 0, previous: null, delta: null, pct: null, kind: 'count', note: null, ...partial });

describe('reports : mise en forme', () => {
    it('écrit les taux, durées et montants dans la langue', () => {
        expect(formatRate(0.125, 'en')).toBe('13 %');
        expect(formatRate(0.05, 'en')).toBe('5 %');
        expect(formatRate(0.0333, 'en')).toBe('3.3 %');
        expect(formatRate(null, 'en')).toBe('—');
        expect(formatMinutes(750, 'en')).toBe('12 h 30');
        expect(formatMinutes(45, 'en')).toBe('45 min');
        expect(formatMinutes(120, 'en')).toBe('2 h');
        expect(formatMetric(12500, 'money', 'en', 'EUR')).toContain('125.00');
        expect(formatMetric(null, 'count', 'en')).toBe('—');
    });

    it('étiquette les intervalles de courbe', () => {
        expect(bucketLabel('2026-06-09', 'day')).toBe('09/06');
        expect(bucketLabel('2026-06-01', 'month')).toBe('06/2026');
    });

    it('choisit une échelle d’axe lisible', () => {
        expect(niceScale(0)).toEqual({ max: 4, step: 1 });
        expect(niceScale(7)).toEqual({ max: 8, step: 2 });
        expect(niceScale(130)).toEqual({ max: 150, step: 50 });
    });
});

describe('reports : comparaisons neutres', () => {
    it('donne l’écart absolu et le pourcentage quand la base le permet', () => {
        const change = changeOf(metric({ value: 12, previous: 10, delta: 2, pct: 20 }), 'en');

        expect(change).toMatchObject({ direction: 'up', delta: '+2', pct: '+20 %', note: null });
    });

    it('signale une baisse, un statu quo et l’absence de comparaison', () => {
        expect(changeOf(metric({ value: 8, previous: 10, delta: -2, pct: -20 }), 'en').direction).toBe('down');
        expect(changeOf(metric({ value: 10, previous: 10, delta: 0, pct: 0 }), 'en').direction).toBe('same');
        expect(changeOf(metric({ value: 3, previous: null }), 'en')).toMatchObject({ direction: 'none', note: null });
    });

    it('n’invente pas de pourcentage sur une base nulle ou trop petite', () => {
        expect(changeOf(metric({ value: 4, previous: 0, delta: 4, pct: null, note: 'zero_baseline' }), 'en')).toMatchObject({
            delta: '+4',
            pct: null,
            note: 'zero_baseline',
        });
        expect(changeOf(metric({ value: 4, previous: 2, delta: 2, pct: null, note: 'small_baseline' }), 'en')).toMatchObject({
            pct: null,
            note: 'small_baseline',
        });
    });

    it('exprime l’écart d’un taux en points', () => {
        const change = changeOf(metric({ kind: 'rate', value: 0.25, previous: 0.2, delta: 5, pct: null }), 'en');

        expect(change).toMatchObject({ direction: 'up', delta: '+5 pt', pct: null, note: null });
    });

    it('formate un écart de montant dans sa devise', () => {
        const change = changeOf(metric({ kind: 'money', value: 15000, previous: 10000, delta: 5000, pct: 50 }), 'en', 'EUR');

        expect(change.delta).toContain('50.00');
        expect(change.delta?.startsWith('+')).toBe(true);
    });
});

describe('reports : filtres et adresses', () => {
    const filters = {
        preset: 'this_month',
        from: '2026-06-01',
        to: '2026-06-30',
        location: null,
        practitioner: 'p1',
        service: null,
        status: ['completed', 'no_show'],
        source: 'whatsapp',
        payment: null,
    };

    it('reproduit la période et les filtres, et change de catégorie sans les perdre', () => {
        const query = filterQuery(filters, { category: 'financial' });

        expect(query).toEqual({ preset: 'this_month', practitioner: 'p1', source: 'whatsapp', status: ['completed', 'no_show'], category: 'financial' });
        expect(queryString(query)).toBe('?preset=this_month&practitioner=p1&source=whatsapp&status%5B%5D=completed&status%5B%5D=no_show&category=financial');
    });

    it('n’envoie les dates que pour une période personnalisée', () => {
        expect(filterQuery({ ...filters, preset: 'custom' })).toMatchObject({ preset: 'custom', from: '2026-06-01', to: '2026-06-30' });
        expect(filterQuery(filters)).not.toHaveProperty('from');
    });

    it('compte les filtres actifs hors période', () => {
        expect(activeFilterCount(filters)).toBe(3);
        expect(activeFilterCount({ ...filters, practitioner: null, source: null, status: [] })).toBe(0);
    });
});

describe('reports : tableaux et états', () => {
    it('met une carte de chaleur en tableau, un jour par ligne', () => {
        const cells = Object.fromEntries(
            Array.from({ length: 7 }, (_unused, index) => [String(index + 1), Array.from({ length: 24 }, (_x, hour) => (index === 1 && hour === 10 ? 3 : 0))]),
        );
        const table = heatmapTable({ cells, first_hour: 9, last_hour: 11 }, { day: 'Day', weekday: (day) => `D${day}` });

        expect(table.columns.map((column) => column.label)).toEqual(['Day', '09:00', '10:00', '11:00']);
        expect(table.rows[1]).toMatchObject({ day: 'D2', h10: 3, h9: 0 });
        expect(table.rows).toHaveLength(7);
    });

    it('reconnaît un rapport vide selon la catégorie', () => {
        const zero = {
            metrics: {
                total: metric({ value: 0 }),
                active: metric({ value: 0 }),
                new: metric({ value: 0 }),
                booked_value: metric({ value: 0 }),
                collected: metric({ value: 0 }),
                cancelled_value: metric({ value: 0 }),
                assigned: metric({ value: 0 }),
                scheduled: metric({ value: 0 }),
            },
        };

        expect(isEmptyReport('appointments', zero)).toBe(true);
        expect(isEmptyReport('customers', zero)).toBe(true);
        expect(isEmptyReport('financial', zero)).toBe(true);
        expect(isEmptyReport('practitioners', zero)).toBe(true);
        expect(isEmptyReport('overview', { appointments: zero })).toBe(true);
        expect(isEmptyReport('appointments', { metrics: { total: metric({ value: 4 }) } })).toBe(false);
        expect(isEmptyReport('practitioners', { metrics: { assigned: metric({ value: 0 }), scheduled: metric({ value: 600 }) } })).toBe(false);
        expect(isEmptyReport('appointments', null)).toBe(false);
    });
});
