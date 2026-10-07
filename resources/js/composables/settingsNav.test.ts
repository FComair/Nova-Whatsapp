import { describe, expect, it } from 'vitest';
import { activeSettings, settingsGroups, settingsHome } from './settingsNav';

const keys = (role: string | null): string[] => settingsGroups(role).flatMap((group) => group.items.map((item) => item.key));

describe('menu des réglages', () => {
    it("propose toutes les pages au propriétaire, en quatre groupes, la mise en route d'abord", () => {
        expect(settingsGroups('owner').map((group) => group.key)).toEqual(['setup', 'account', 'integrations', 'privacy']);
        expect(keys('owner')).toEqual([
            'setup-business',
            'setup-team',
            'setup-messages',
            'setup-launch',
            'profile',
            'calendars',
            'whatsapp',
            'data',
            'security',
        ]);
    });

    it("réserve la mise en route au propriétaire, comme l'assistant, et suit les droits des routes pour le reste", () => {
        expect(keys('site_manager')).toEqual(['profile', 'calendars', 'security']);
        expect(keys('practitioner')).toEqual(['profile', 'calendars', 'security']);
        expect(keys('receptionist')).toEqual(['profile', 'security']);
    });

    it('refuse un rôle inconnu et ne garde que les groupes qui ont une page accessible', () => {
        expect(settingsGroups('receptionist').map((group) => group.key)).toEqual(['account', 'privacy']);
        expect(settingsGroups(null)).toEqual([]);
    });

    it('relie chaque entrée de mise en route à sa catégorie', () => {
        const items = settingsGroups('owner')[0].items;

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
        expect(activeSettings('/calendriers')).toBe('calendars');
        expect(activeSettings('/whatsapp?x=1')).toBe('whatsapp');
        expect(activeSettings('/compte/donnees')).toBe('data');
        expect(activeSettings('/compte/pause')).toBe('data');
        expect(activeSettings('/securite')).toBe('security');
        expect(activeSettings('/planning')).toBeNull();
        expect(activeSettings('/whatsappx')).toBeNull();
    });
});
