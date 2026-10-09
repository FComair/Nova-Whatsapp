import { describe, expect, it } from 'vitest';
import { AVATAR_PIXELS, fitWithin, photoUrl, sourceSize, validateDimensions, validatePhoto } from './practitionerPhoto';

describe('practitionerPhoto', () => {
    it("accepte JPG, PNG et WebP jusqu'à 5 Mo, refuse le reste", () => {
        expect(validatePhoto({ type: 'image/jpeg', size: 1000 })).toBeNull();
        expect(validatePhoto({ type: 'image/png', size: 1000 })).toBeNull();
        expect(validatePhoto({ type: 'image/webp', size: 5 * 1024 * 1024 })).toBeNull();
        expect(validatePhoto({ type: 'image/webp', size: 5 * 1024 * 1024 + 1 })).toBe('too_large');
        expect(validatePhoto({ type: 'image/gif', size: 1000 })).toBe('type');
        expect(validatePhoto({ type: 'application/pdf', size: 1000 })).toBe('type');
        expect(validatePhoto({ type: '', size: 1000 })).toBe('type');
    });

    it('exige de 200 à 6 000 pixels de chaque côté', () => {
        expect(validateDimensions(200, 200)).toBeNull();
        expect(validateDimensions(6000, 4000)).toBeNull();
        expect(validateDimensions(199, 800)).toBe('dimensions');
        expect(validateDimensions(800, 199)).toBe('dimensions');
        expect(validateDimensions(6001, 800)).toBe('dimensions');
    });

    it('réduit sans déformer ni agrandir', () => {
        expect(fitWithin(4000, 3000, 1024)).toEqual({ width: 1024, height: 768 });
        expect(fitWithin(3000, 4000, 1024)).toEqual({ width: 768, height: 1024 });
        expect(fitWithin(800, 600, 1024)).toEqual({ width: 800, height: 600 });
        expect(fitWithin(1024, 1024, 1024)).toEqual({ width: 1024, height: 1024 });
    });

    it("choisit l'image de 64 px pour les petits avatars et celle de 256 px pour les grands", () => {
        expect(sourceSize('xs')).toBe(64);
        expect(sourceSize('sm')).toBe(64);
        expect(sourceSize('md')).toBe(256);
        expect(sourceSize('lg')).toBe(256);
        expect(sourceSize('xl')).toBe(256);
        expect(AVATAR_PIXELS.xl).toBeGreaterThan(AVATAR_PIXELS.xs);
    });

    it("construit l'adresse de la photo avec sa version", () => {
        expect(photoUrl('abc', 64, 1760000000)).toBe('/praticiens/abc/photo/64?v=1760000000');
    });
});
