import { describe, expect, it } from 'vitest';
import { MAX_ZOOM, clampCrop, initialCrop, minZoom, panBy, placement, scaleOf, scaledPlacement, zoomTo } from './logoCrop';

const landscape = { width: 800, height: 400 };
const portrait = { width: 400, height: 800 };
const square = { width: 500, height: 500 };
const VIEW = 200;

describe('logoCrop : cadrage sans déformation', () => {
    it('à zoom 1 le petit côté remplit le carré, l’autre déborde', () => {
        const rect = placement(initialCrop(), VIEW, landscape);

        expect(rect.height).toBe(VIEW);
        expect(rect.width).toBe(400);
        expect(rect.top).toBe(0);
        expect(rect.left).toBe(-100);
    });

    it('garde les proportions de l’image à tous les zooms (jamais d’étirement)', () => {
        for (const zoom of [minZoom(landscape), 1, 1.7, MAX_ZOOM]) {
            const rect = placement(clampCrop({ zoom, u: 0.3, v: 0.6 }, VIEW, landscape), VIEW, landscape);

            expect(rect.width / rect.height).toBeCloseTo(landscape.width / landscape.height, 6);
        }
    });

    it('permet de dézoomer jusqu’à voir l’image entière, pas plus', () => {
        expect(minZoom(landscape)).toBe(0.5);
        expect(minZoom(portrait)).toBe(0.5);
        expect(minZoom(square)).toBe(1);

        const out = zoomTo(initialCrop(), 0.1, VIEW, landscape);
        const rect = placement(out, VIEW, landscape);

        expect(out.zoom).toBe(0.5);
        expect(rect.width).toBe(VIEW);
        expect(rect.height).toBe(100);
        // Image plus petite que le carré : centrée, des marges de chaque côté.
        expect(rect.top).toBe(50);
    });

    it('borne le zoom maximal', () => {
        expect(zoomTo(initialCrop(), 99, VIEW, square).zoom).toBe(MAX_ZOOM);
    });

    it('ne laisse jamais de vide quand l’image est plus grande que le carré', () => {
        const dragged = panBy(initialCrop(), 10_000, 10_000, VIEW, landscape);
        const rect = placement(dragged, VIEW, landscape);

        expect(rect.left).toBeLessThanOrEqual(0);
        expect(rect.left + rect.width).toBeGreaterThanOrEqual(VIEW);
        expect(rect.top).toBeLessThanOrEqual(0);
        expect(rect.top + rect.height).toBeGreaterThanOrEqual(VIEW);

        const other = placement(panBy(initialCrop(), -10_000, -10_000, VIEW, landscape), VIEW, landscape);
        expect(other.left + other.width).toBeCloseTo(VIEW, 6);
    });

    it('fait suivre l’image au doigt', () => {
        const start = placement(initialCrop(), VIEW, landscape);
        const moved = placement(panBy(initialCrop(), -30, 0, VIEW, landscape), VIEW, landscape);

        expect(moved.left).toBeCloseTo(start.left - 30, 6);
    });

    it('garde le centre quand on zoome, et la même zone visible au même zoom', () => {
        const state = { zoom: 2, u: 0.7, v: 0.4 };
        const clamped = clampCrop(state, VIEW, square);

        expect(zoomTo(state, 2, VIEW, square)).toEqual(clamped);
        expect(zoomTo(state, 3, VIEW, square).u).toBeCloseTo(0.7, 6);
    });

    it('est indépendant de la taille de l’écran : mêmes proportions dans la zone et dans les aperçus', () => {
        const state = clampCrop({ zoom: 1.8, u: 0.4, v: 0.55 }, VIEW, landscape);
        const big = placement(clampCrop(state, 300, landscape), 300, landscape);
        const small = scaledPlacement(state, VIEW, landscape, 32);

        expect(big.width / 300).toBeCloseTo(small.width / 32, 6);
        expect(big.left / 300).toBeCloseTo(small.left / 32, 6);
        expect(big.top / 300).toBeCloseTo(small.top / 32, 6);
    });

    it('calcule l’échelle : le petit côté × zoom', () => {
        expect(scaleOf(VIEW, landscape, 1)).toBe(0.5);
        expect(scaleOf(VIEW, landscape, 2)).toBe(1);
        expect(scaleOf(VIEW, portrait, 1)).toBe(0.5);
    });

    it('réinitialise sur l’image carrée : cadrage centré, zoom 1', () => {
        expect(clampCrop(initialCrop(), VIEW, square)).toEqual({ zoom: 1, u: 0.5, v: 0.5 });
    });
});
