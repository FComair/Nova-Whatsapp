import { beforeEach, describe, expect, it } from 'vitest';
import { backTarget, canReturnInHistory, resetNavigationTrail, trackNavigation } from './backNav';

describe('destination du bouton Retour', () => {
    it.each([
        ['/tableau-de-bord', null],
        ['/planning', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/clients', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/clients?q=ana&page=2', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/clients/0192-abc', ['back_nav.customers', '/clients']],
        ['/clients/fidelite', ['back_nav.customers', '/clients']],
        ['/clients/fidelite/programme', ['back_nav.loyalty', '/clients/fidelite']],
        ['/clients/fidelite/rapport?from=2026-01-01', ['back_nav.loyalty', '/clients/fidelite']],
        ['/rapports', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/rapports/imprimer', ['back_nav.reports', '/rapports']],
        ['/facturation', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/facturation/factures/abc', ['back_nav.billing', '/facturation']],
        ['/facturation/simulation', ['back_nav.billing', '/facturation']],
        ['/aide', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/aide/tickets', ['back_nav.help', '/aide']],
        ['/aide/tickets/T-1001', ['back_nav.tickets', '/aide/tickets']],
        ['/aide/contact', ['back_nav.help', '/aide']],
        ['/aide/demarrage', ['back_nav.help', '/aide']],
        ['/aide/demarrage/premiers-pas', ['back_nav.help_topic', '/aide/demarrage']],
        ['/reglages/equipe', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/reglages/profil', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/securite', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/qr-whatsapp', ['back_nav.whatsapp', '/whatsapp']],
        ['/whatsapp', ['back_nav.dashboard', '/tableau-de-bord']],
        ['/connexion', null],
    ])('%s', (url, expected) => {
        const target = backTarget(url);

        expect(target === null ? null : [target.label, target.href]).toEqual(expected);
    });
});

describe('retour dans l’historique', () => {
    beforeEach(() => resetNavigationTrail());

    it('revient en arrière quand l’écran précédent est le parent (mêmes filtres et page)', () => {
        trackNavigation('/clients?q=ana&page=2');
        trackNavigation('/clients/42');

        expect(canReturnInHistory('/clients')).toBe(true);
    });

    it('va à l’adresse du parent quand on arrive d’ailleurs', () => {
        trackNavigation('/tableau-de-bord');
        trackNavigation('/clients/42');

        expect(canReturnInHistory('/clients')).toBe(false);
    });

    it('ne se trompe pas après un retour du navigateur', () => {
        trackNavigation('/clients');
        trackNavigation('/clients/42');
        trackNavigation('/clients');
        trackNavigation('/planning');

        expect(canReturnInHistory('/clients')).toBe(true);
        expect(canReturnInHistory('/tableau-de-bord')).toBe(false);
    });

    it('n’a pas de précédent au premier écran', () => {
        trackNavigation('/clients/42');

        expect(canReturnInHistory('/clients')).toBe(false);
    });
});
