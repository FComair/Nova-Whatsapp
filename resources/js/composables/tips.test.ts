import { describe, expect, it } from 'vitest';
import { parseAmountCents, shareBalance, sharesToPayload, totalPaid } from './tips';

describe('tips : lecture d’un montant', () => {
    it('lit les montants avec virgule ou point, au plus deux décimales', () => {
        expect(parseAmountCents('5')).toBe(500);
        expect(parseAmountCents('5,5')).toBe(550);
        expect(parseAmountCents('5.05')).toBe(505);
        expect(parseAmountCents(' 12,00 € ')).toBe(1200);
        expect(parseAmountCents('0')).toBe(0);
    });

    it('refuse un montant négatif, vide, trop précis ou illisible', () => {
        for (const bad of ['', '-5', '5.123', 'abc', '1e3', '5,,5', '1 000 000 000']) {
            expect(parseAmountCents(bad)).toBeNull();
        }
    });
});

describe('tips : répartition', () => {
    it('est équilibrée quand les parts totalisent exactement le pourboire', () => {
        expect(shareBalance(1000, { a: '6', b: '4' })).toEqual({ state: 'balanced', allocated: 1000, remaining: 0 });
    });

    it('dit ce qu’il reste ou ce qui dépasse', () => {
        expect(shareBalance(1000, { a: '6', b: '3' })).toEqual({ state: 'short', allocated: 900, remaining: 100 });
        expect(shareBalance(1000, { a: '6', b: '5' })).toEqual({ state: 'over', allocated: 1100, remaining: -100 });
    });

    it('compte une part vide pour zéro et signale une part illisible', () => {
        expect(shareBalance(500, { a: '5', b: '' }).state).toBe('balanced');
        expect(shareBalance(500, { a: '5', b: 'x' }).state).toBe('invalid');
        expect(shareBalance(500, {}).state).toBe('short');
    });

    it('n’envoie que les parts renseignées et positives', () => {
        expect(sharesToPayload({ a: '6', b: '', c: '0', d: '4,50' })).toEqual([
            { practitioner_id: 'a', amount: '6' },
            { practitioner_id: 'd', amount: '4,50' },
        ]);
    });

    it('additionne l’encaissé du service et le pourboire pour le total payé', () => {
        expect(totalPaid(5000, 500)).toBe(5500);
        expect(totalPaid(5000, 0)).toBe(5000);
    });
});
