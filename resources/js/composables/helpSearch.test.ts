import { describe, expect, it } from 'vitest';
import { fold, type HelpEntry, searchHelp, terms } from './helpSearch';

const entry = (slug: string, title: string, summary: string, tags: string[], text = ''): HelpEntry => ({
    category: 'customers',
    slug,
    title,
    summary,
    tags,
    text,
});

const index = [
    entry(
        'find',
        'Retrouver et filtrer vos clients',
        'La recherche et les vues enregistrées.',
        ['clients', 'téléphone'],
        'Un numéro se retrouve quel que soit son format.',
    ),
    entry('block', 'Étiqueter, archiver ou bloquer', 'Les actions sur une sélection.', ['étiquettes', 'archiver'], 'Archiver retire les clients de la liste.'),
    entry('qr', 'Réserver avec un QR code', 'Afficher un QR code en boutique.', ['affiche'], 'Le client scanne le code.'),
];

describe('helpSearch', () => {
    it('ignore accents et majuscules', () => {
        expect(fold('Réservé À Étienne')).toBe('reserve a etienne');
        expect(searchHelp(index, 'ETIQUETER').map((item) => item.slug)).toEqual(['block']);
        expect(searchHelp(index, 'telephone').map((item) => item.slug)).toEqual(['find']);
    });

    it('exige que tous les mots se retrouvent', () => {
        expect(searchHelp(index, 'qr code').map((item) => item.slug)).toEqual(['qr']);
        expect(searchHelp(index, 'qr archiver')).toEqual([]);
    });

    it('classe le titre avant le résumé et le corps', () => {
        const results = searchHelp(index, 'clients').map((item) => item.slug);

        expect(results[0]).toBe('find');
        expect(results).toContain('block');
    });

    it('cherche dans le corps', () => {
        expect(searchHelp(index, 'scanne').map((item) => item.slug)).toEqual(['qr']);
    });

    it('ne cherche pas en dessous de 2 caractères', () => {
        expect(searchHelp(index, 'c')).toEqual([]);
        expect(searchHelp(index, '  ')).toEqual([]);
    });

    it('limite le nombre de résultats', () => {
        expect(searchHelp(index, 'client', 1)).toHaveLength(1);
    });

    it('découpe les mots sans signes', () => {
        expect(terms('Qu’est-ce que +32 ?')).toEqual(['qu', 'est', 'ce', 'que', '+32']);
    });
});
