import { nextTick } from 'vue';

const FLASH = ['ring-4', 'ring-primary-600/30', 'transition-shadow', 'rounded-card'];
const HEADING = /^H[1-6]$/;

/**
 * Après une recherche : fait défiler la page jusqu'à la section visée, y place le focus clavier et la met en évidence deux secondes
 * (un anneau, jamais la couleur seule). Un titre désigne toute sa section. Sans cible, la page reste en haut.
 */
export async function focusAnchor(id: string | null): Promise<void> {
    if (!id) {
        return;
    }

    await nextTick();

    const found = document.getElementById(id);
    const target = found !== null && HEADING.test(found.tagName) ? (found.closest('section') ?? found) : found;

    if (!target) {
        return;
    }

    if (!target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
    }

    const calm = typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    target.focus({ preventScroll: true });
    target.classList.add(...FLASH);
    window.setTimeout(() => target.classList.remove(...FLASH), 2000);
}

/** Cible demandée par l'adresse de la page (#section), s'il y en a une. */
export function hashAnchor(): string | null {
    const hash = window.location.hash.replace(/^#/, '');

    return hash === '' ? null : decodeURIComponent(hash);
}
