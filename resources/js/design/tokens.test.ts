import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

// Lu directement sur le disque : le thème est un fichier source, pas un module.
const css = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8');

/**
 * Garde-fou des jetons de design (docs/20) : chaque paire texte / fond reste lisible (WCAG 2.x : 4,5:1 pour du texte,
 * 3:1 pour la bordure d'un champ et l'anneau de focus). Si quelqu'un change une couleur du thème, ce test dit tout de suite
 * si elle devient illisible. Les valeurs sont celles du site web, sauf deux corrigées (bordure de champ, anneau de focus).
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
        ['encre sur le canvas', 'neutral-900', 'canvas', TEXT],
        ['texte courant sur surface', 'neutral-700', 'surface', TEXT],
        ['texte courant sur le canvas', 'neutral-700', 'canvas', TEXT],
        ['texte atténué sur surface', 'muted', 'surface', TEXT],
        ['texte atténué sur le canvas', 'muted', 'canvas', TEXT],
        ['texte atténué sur survol (surface-2)', 'muted', 'surface-2', TEXT],
        ['texte secondaire sur surface', 'neutral-600', 'surface', TEXT],
        ['texte blanc sur bouton principal', 'surface', 'primary-600', TEXT],
        ['texte blanc sur bouton principal survolé', 'surface', 'primary-700', TEXT],
        ['texte blanc sur bouton danger', 'surface', 'danger-700', TEXT],
        ['texte blanc sur bouton danger survolé', 'surface', 'danger-800', TEXT],
        ['lien teal sur surface', 'primary-600', 'surface', TEXT],
        ['lien teal sur le canvas', 'primary-600', 'canvas', TEXT],
        ['élément actif du menu (Teal Dark sur Mint)', 'primary-700', 'primary-100', TEXT],
        ['teal sur Mint', 'primary-600', 'primary-100', TEXT],
        ['teal sur survol de ligne', 'primary-600', 'primary-50', TEXT],
        ['succès', 'success-fg', 'success-bg', TEXT],
        ['avertissement', 'warning-fg', 'warning-bg', TEXT],
        ['erreur', 'danger-fg', 'danger-bg', TEXT],
        ['information', 'info-fg', 'info-bg', TEXT],
        ['neutre', 'neutral-fg', 'neutral-bg', TEXT],
        ['erreur sur surface', 'danger-fg', 'surface', TEXT],
        ['ambre sur Deep Ink (usage décoratif sur fond sombre)', 'amber', 'deep', TEXT],
        ['bordure de champ sur surface', 'line-strong', 'surface', CONTROL],
        ['bordure de champ sur le canvas', 'line-strong', 'canvas', CONTROL],
        ['anneau de focus sur surface', 'primary-600', 'surface', CONTROL],
        ['anneau de focus sur le canvas', 'primary-600', 'canvas', CONTROL],
    ])('%s ≥ %s', (_label, foreground, background, minimum) => {
        expect(ratio(token(foreground as string), token(background as string))).toBeGreaterThanOrEqual(minimum as number);
    });

    it("l'ambre du site ne sert pas d'anneau de focus sur fond clair (2,1:1 seulement)", () => {
        expect(ratio(token('amber'), token('surface'))).toBeLessThan(CONTROL);
    });

    it('lit bien les valeurs du thème', () => {
        expect(token('surface')).toBe('#ffffff');
        expect(token('primary-600')).toBe('#0e6b6b');
        expect(ratio('#000000', '#ffffff')).toBeCloseTo(21, 0);
    });
});
