import { describe, expect, it } from 'vitest';
import { formatVat, parseVat } from './vat';

describe('vat', () => {
    it('accepte un taux entier ou décimal, avec virgule ou point', () => {
        expect(parseVat('21')).toBe(21);
        expect(parseVat('21.5')).toBe(21.5);
        expect(parseVat('21,5')).toBe(21.5);
        expect(parseVat('6.25')).toBe(6.25);
        expect(parseVat(' 12 % ')).toBe(12);
        expect(parseVat('0')).toBe(0);
        expect(parseVat('100')).toBe(100);
    });

    it("refuse ce qui n'est pas un taux valide entre 0 et 100", () => {
        for (const input of ['', 'abc', '-1', '100.01', '101', '21.555', '1e2', '21,5,1', '%', '.5', '0x10']) {
            expect(parseVat(input), input).toBeNull();
        }
    });

    it('affiche le taux avec le séparateur de la langue', () => {
        expect(formatVat(21, 'fr')).toBe('21 %');
        expect(formatVat(21.5, 'fr').replace(/ | /g, ' ')).toBe('21,5 %');
        expect(formatVat(21.5, 'en')).toBe('21.5 %');
    });
});
