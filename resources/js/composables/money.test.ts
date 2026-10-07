import { describe, expect, it } from 'vitest';
import { centsToInput, formatPrice } from './money';

describe('money', () => {
    it('affiche les montants ronds sans décimales dans un champ', () => {
        expect(centsToInput(4000, 'fr')).toBe('40');
        expect(centsToInput(0, 'fr')).toBe('0');
    });

    it('utilise le séparateur décimal de la langue', () => {
        expect(centsToInput(4050, 'fr')).toBe('40,50');
        expect(centsToInput(4050, 'nl')).toBe('40,50');
        expect(centsToInput(4050, 'en')).toBe('40.50');
        expect(centsToInput(4005, 'en')).toBe('40.05');
    });

    it('formate un prix avec la devise', () => {
        expect(formatPrice(4050, 'en')).toBe('€40.50');
        expect(formatPrice(4050, 'fr').replace(/\s/g, ' ')).toBe('40,50 €');
    });
});
