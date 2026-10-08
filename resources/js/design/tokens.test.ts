import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// Lu directement sur le disque : le thème est un fichier source, pas un module.
const css = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8');

/**
 * Garde-fou des jetons de design (docs/19) : chaque paire texte / fond reste lisible (WCAG 2.x : 4,5:1 pour du texte,
 * 3:1 pour la bordure d'un champ). Si quelqu'un change une couleur du thème, ce test dit tout de suite si elle devient illisible.
 */
function token(name: string): string {
    const match = new RegExp(String.raw`--color-${name}: *(#[0-9a-fA-F]{6})`).exec(css);

    if (match === null) {
        throw new Error(`Jeton introuvable : ${name}`);
    }

    return match[1]!;
}

function luminance(hex: string): number {
    const [r, g, b] = [1, 3, 5].map((index) => {
        const value = parseInt(hex.slice(index, index + 2), 16) / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    }) as [number, number, number];

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(foreground: string, background: string): number {
    const [light, dark] = [luminance(foreground), luminance(background)].sort((a, b) => b - a) as [number, number];

    return (light + 0.05) / (dark + 0.05);
}

const TEXT = 4.5;
const CONTROL = 3;

describe('contrastes des jetons', () => {
    it.each([
        ['texte sur surface', 'neutral-900', 'surface', TEXT],
        ['texte sur fond de page', 'neutral-900', 'canvas', TEXT],
        ['texte atténué sur surface', 'muted', 'surface', TEXT],
        ['texte atténué sur fond de page', 'muted', 'canvas', TEXT],
        ['texte atténué sur surface-2', 'muted', 'surface-2', TEXT],
        ['texte blanc sur bouton principal', 'surface', 'primary-600', TEXT],
        ['texte blanc sur bouton principal survolé', 'surface', 'primary-700', TEXT],
        ['texte blanc sur bouton danger', 'surface', 'danger-700', TEXT],
        ['lien sur surface', 'primary-700', 'surface', TEXT],
        ['lien sur fond sélectionné', 'primary-700', 'primary-50', TEXT],
        ['texte blanc sur accent WhatsApp', 'surface', 'accent-700', TEXT],
        ['succès', 'success-fg', 'success-bg', TEXT],
        ['avertissement', 'warning-fg', 'warning-bg', TEXT],
        ['erreur', 'danger-fg', 'danger-bg', TEXT],
        ['information', 'info-fg', 'info-bg', TEXT],
        ['neutre', 'neutral-fg', 'neutral-bg', TEXT],
        ['erreur sur surface', 'danger-fg', 'surface', TEXT],
        ['bordure de champ sur surface', 'line-strong', 'surface', CONTROL],
        ['bordure de champ sur fond de page', 'line-strong', 'canvas', CONTROL],
    ])('%s ≥ %s', (_label, foreground, background, minimum) => {
        expect(ratio(token(foreground as string), token(background as string))).toBeGreaterThanOrEqual(minimum as number);
    });

    it('lit bien les valeurs du thème', () => {
        expect(token('surface')).toBe('#ffffff');
        expect(ratio('#000000', '#ffffff')).toBeCloseTo(21, 0);
    });
});
