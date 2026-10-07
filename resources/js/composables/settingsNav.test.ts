import { describe, expect, it } from 'vitest';
import { activeSettings, settingsGroups, settingsHome } from './settingsNav';

const keys = (role: string | null): string[] => settingsGroups(role).flatMap((group) => group.items.map((item) => item.key));

describe('menu des réglages', () => {
    it('propose toutes les pages au propriétaire, en trois groupes', () => {
        expect(settingsGroups('owner').map((group) => group.key)).toEqual(['account', 'integrations', 'privacy']);
        expect(keys('owner')).toEqual(['profile', 'calendars', 'whatsapp', 'data', 'security']);
    });

    it('suit les droits des routes pour les autres rôles', () => {
        expect(keys('site_manager')).toEqual(['profile', 'calendars', 'security']);
        expect(keys('practitioner')).toEqual(['profile', 'calendars', 'security']);
        expect(keys('receptionist')).toEqual(['profile', 'security']);
    });

    it('refuse un rôle inconnu et ne garde que les groupes qui ont une page accessible', () => {
        expect(settingsGroups('receptionist').map((group) => group.key)).toEqual(['account', 'privacy']);
        expect(settingsGroups(null)).toEqual([]);
    });

    it('ouvre la première page accessible', () => {
        expect(settingsHome('owner')).toBe('/reglages/profil');
        expect(settingsHome('receptionist')).toBe('/reglages/profil');
    });

    it('reconnaît la page active, y compris la pause qui dépend de « Mes données »', () => {
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
