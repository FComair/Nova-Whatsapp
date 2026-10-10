import { describe, expect, it } from 'vitest';
import { activeSettings, settingsGroups, settingsHome } from './settingsNav';

// Capacités que chaque rôle a par défaut et qui ouvrent une page des réglages (le serveur est la source de vérité, voir docs/34).
const permissions: Record<string, string[]> = {
    owner: ['settings.team', 'calendars.own', 'settings.integrations', 'whatsapp.connect', 'whatsapp.templates'],
    site_manager: ['calendars.own'],
    practitioner: ['calendars.own'],
    receptionist: [],
};

const keys = (role: string | null, granted: string[] = permissions[role ?? ''] ?? []): string[] =>
    settingsGroups(role, granted).flatMap((group) => group.items.map((item) => item.key));

describe('menu des réglages', () => {
    it("propose toutes les pages au propriétaire, en quatre groupes, la mise en route d'abord", () => {
        expect(settingsGroups('owner', permissions.owner).map((group) => group.key)).toEqual(['setup', 'account', 'integrations', 'privacy']);
        expect(keys('owner')).toEqual([
            'setup-business',
            'setup-team',
            'setup-messages',
            'setup-launch',
            'profile',
            'team',
            'calendars',
            'whatsapp',
            'whatsapp-qr',
            'data',
            'legal',
            'security',
        ]);
    });

    it("réserve la mise en route au propriétaire, comme l'assistant, et suit les droits des routes pour le reste", () => {
        expect(keys('site_manager')).toEqual(['profile', 'calendars', 'legal', 'security']);
        expect(keys('practitioner')).toEqual(['profile', 'calendars', 'legal', 'security']);
        expect(keys('receptionist')).toEqual(['profile', 'legal', 'security']);
    });

    it('refuse un rôle inconnu et ne garde que les groupes qui ont une page accessible', () => {
        expect(settingsGroups('receptionist', permissions.receptionist).map((group) => group.key)).toEqual(['account', 'privacy']);
        expect(settingsGroups(null)).toEqual([]);
    });

    it("ouvre « Équipe et accès » à qui peut gérer l'équipe, quel que soit son rôle", () => {
        expect(keys('practitioner', ['calendars.own', 'settings.team'])).toContain('team');
        expect(keys('receptionist', ['settings.team'])).toEqual(['profile', 'team', 'legal', 'security']);
        expect(keys('site_manager')).not.toContain('team');
    });

    it('suit les permissions pour les intégrations : WhatsApp et QR codes, calendriers', () => {
        expect(keys('receptionist', ['whatsapp.templates'])).toContain('whatsapp');
        expect(keys('receptionist', ['whatsapp.templates'])).not.toContain('whatsapp-qr');
        expect(keys('practitioner', ['whatsapp.connect'])).toEqual(expect.arrayContaining(['whatsapp', 'whatsapp-qr']));
        expect(keys('practitioner', [])).not.toContain('calendars');
    });

    it('relie chaque entrée de mise en route à sa catégorie', () => {
        const items = settingsGroups('owner', permissions.owner)[0].items;

        expect(items.map((item) => item.setup)).toEqual(['business', 'team', 'messages', 'launch']);
        expect(items.map((item) => item.href)).toEqual([
            '/reglages/demarrage/business',
            '/reglages/demarrage/team',
            '/reglages/demarrage/messages',
            '/reglages/demarrage/launch',
        ]);
    });

    it('ouvre toujours le profil, accessible à tous les rôles', () => {
        expect(settingsHome()).toBe('/reglages/profil');
    });

    it('reconnaît la page active, y compris la pause qui dépend de « Mes données »', () => {
        expect(activeSettings('/reglages/demarrage/team')).toBe('setup-team');
        expect(activeSettings('/reglages/profil')).toBe('profile');
        expect(activeSettings('/reglages/equipe')).toBe('team');
        expect(activeSettings('/calendriers')).toBe('calendars');
        expect(activeSettings('/whatsapp?x=1')).toBe('whatsapp');
        expect(activeSettings('/compte/donnees')).toBe('data');
        expect(activeSettings('/compte/pause')).toBe('data');
        expect(activeSettings('/securite')).toBe('security');
        expect(activeSettings('/reglages/juridique')).toBe('legal');
        expect(activeSettings('/planning')).toBeNull();
        expect(activeSettings('/whatsappx')).toBeNull();
    });
});
