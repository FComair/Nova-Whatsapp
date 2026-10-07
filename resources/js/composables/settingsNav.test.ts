import { describe, expect, it } from 'vitest';
import { activeSettings, settingsGroups, settingsHome } from './settingsNav';

const keys = (role: string | null): string[] => settingsGroups(role).flatMap((group) => group.items.map((item) => item.key));

describe('menu des réglages', () => {
    it('propose toutes les pages au propriétaire, en deux groupes', () => {
        expect(settingsGroups('owner').map((group) => group.key)).toEqual(['integrations', 'privacy']);
        expect(keys('owner')).toEqual(['calendars', 'whatsapp', 'data', 'security']);
    });

    it('suit les droits des routes pour les autres rôles', () => {
        expect(keys('site_manager')).toEqual(['calendars', 'security']);
        expect(keys('practitioner')).toEqual(['calendars', 'security']);
        expect(keys('receptionist')).toEqual(['security']);
    });

    it('masque un groupe vide et refuse un rôle inconnu', () => {
        expect(settingsGroups('receptionist').map((group) => group.key)).toEqual(['privacy']);
        expect(settingsGroups(null)).toEqual([]);
    });

    it('ouvre la première page accessible', () => {
        expect(settingsHome('owner')).toBe('/calendriers');
        expect(settingsHome('receptionist')).toBe('/securite');
    });

    it('reconnaît la page active, y compris la pause qui dépend de « Mes données »', () => {
        expect(activeSettings('/calendriers')).toBe('calendars');
        expect(activeSettings('/whatsapp?x=1')).toBe('whatsapp');
        expect(activeSettings('/compte/donnees')).toBe('data');
        expect(activeSettings('/compte/pause')).toBe('data');
        expect(activeSettings('/securite')).toBe('security');
        expect(activeSettings('/planning')).toBeNull();
        expect(activeSettings('/whatsappx')).toBeNull();
    });
});
