import { describe, expect, it } from 'vitest';
import { logoUrl, shopColor, shopInitials } from './shopAvatar';

describe('initiales du repli', () => {
    it('prend la première lettre des deux premiers mots', () => {
        expect(shopInitials('Barbier Deux Test')).toBe('BD');
        expect(shopInitials('salon aurore')).toBe('SA');
    });

    it("prend les deux premières lettres d'un nom d'un seul mot", () => {
        expect(shopInitials('Rendivoo')).toBe('NO');
        expect(shopInitials('X')).toBe('X');
    });

    it('gère les accents, les tirets et les espaces superflus', () => {
        expect(shopInitials('  Éclat-Beauté  ')).toBe('ÉB');
    });

    it('ne plante pas sur un nom vide', () => {
        expect(shopInitials('   ')).toBe('?');
    });
});

describe('couleur du repli', () => {
    it('est stable pour un même établissement', () => {
        expect(shopColor('0198-abc')).toBe(shopColor('0198-abc'));
    });

    it('reste dans la palette accessible', () => {
        for (const id of ['a', 'b', 'c', '0198-xyz', '']) {
            expect(shopColor(id)).toMatch(/^#[0-9A-F]{6}$/);
        }
    });
});

describe('adresse du logo', () => {
    it('inclut la version pour forcer le rechargement', () => {
        expect(logoUrl('/reglages/profil/logo', 64, 1700000000)).toBe('/reglages/profil/logo/64?v=1700000000');
    });

    it('est vide sans logo', () => {
        expect(logoUrl('/reglages/profil/logo', 256, null)).toBeNull();
    });
});
