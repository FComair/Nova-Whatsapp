import { describe, expect, it } from 'vitest';
import { needsCheck, parsePriceInput, summarize } from './priceImport';

describe('saisie d’un prix', () => {
    it('accepte les écritures courantes et donne des centimes', () => {
        expect(parsePriceInput('38')).toBe(3800);
        expect(parsePriceInput('38,5')).toBe(3850);
        expect(parsePriceInput('38.50')).toBe(3850);
        expect(parsePriceInput(' € 38,50 ')).toBe(3850);
        expect(parsePriceInput('0')).toBe(0);
        expect(parsePriceInput('99999,99')).toBe(9999999);
    });

    it('refuse ce qui n’est pas un montant', () => {
        for (const text of ['', 'abc', '-5', '12,345', '1 000 000', '100000', '12,', '1,2,3']) {
            expect(parsePriceInput(text)).toBeNull();
        }
    });
});

describe('lignes à vérifier', () => {
    it('signale les prix douteux, la lecture incertaine et les doublons, pas les simples informations', () => {
        expect(needsCheck({ action: 'new', warnings: ['price_zero'] })).toBe(true);
        expect(needsCheck({ action: 'update', warnings: ['low_confidence'] })).toBe(true);
        expect(needsCheck({ action: 'new', warnings: ['duplicate'] })).toBe(true);
        expect(needsCheck({ action: 'new', warnings: ['no_duration', 'unit_ignored'] })).toBe(false);
        expect(needsCheck({ action: 'new', warnings: [] })).toBe(false);
    });

    it('ne compte pas une ligne ignorée', () => {
        expect(needsCheck({ action: 'ignore', warnings: ['price_zero'] })).toBe(false);
    });
});

describe('résumé', () => {
    it('compte chaque catégorie et se met à jour avec les modifications', () => {
        const rows: { action: 'new' | 'update' | 'ignore'; warnings: string[] }[] = [
            { action: 'new', warnings: [] },
            { action: 'new', warnings: ['price_low'] },
            { action: 'update', warnings: [] },
            { action: 'ignore', warnings: ['price_zero'] },
        ];

        expect(summarize(rows)).toEqual({ added: 2, updated: 1, ignored: 1, toCheck: 1 });

        rows[1]!.action = 'ignore';

        expect(summarize(rows)).toEqual({ added: 1, updated: 1, ignored: 2, toCheck: 0 });
    });
});
