import { describe, expect, it, vi } from 'vitest';
import { useT } from './useT';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            locale: 'nl',
            translations: {
                errors: { E3: 'Te veel pogingen. Probeer het opnieuw over :minutes minuten.' },
                common: { app_name: 'Rendivoo' },
                n: { items: ':count klant|:count klanten' },
            },
        },
    }),
}));

describe('useT', () => {
    it('renvoie le texte traduit', () => {
        expect(useT().t('common.app_name')).toBe('Rendivoo');
    });

    it('remplace les paramètres', () => {
        expect(useT().t('errors.E3', { minutes: 15 })).toBe('Te veel pogingen. Probeer het opnieuw over 15 minuten.');
    });

    it('renvoie la clé quand la traduction est absente, sans planter', () => {
        expect(useT().t('inexistant.cle')).toBe('inexistant.cle');
    });

    it('laisse un paramètre non fourni visible plutôt que de le supprimer', () => {
        expect(useT().t('errors.E3')).toBe('Te veel pogingen. Probeer het opnieuw over :minutes minuten.');
    });

    it('choisit le singulier ou le pluriel selon count', () => {
        expect(useT().t('n.items', { count: 1 })).toBe('1 klant');
        expect(useT().t('n.items', { count: 0 })).toBe('0 klanten');
        expect(useT().t('n.items', { count: 12 })).toBe('12 klanten');
    });

    it('expose la langue courante', () => {
        expect(useT().locale.value).toBe('nl');
    });
});
