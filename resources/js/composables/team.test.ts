import { describe, expect, it } from 'vitest';
import {
    accessWarnings,
    baseRoleOf,
    diffCapabilities,
    emptyFilters,
    filterMembers,
    groupCount,
    initials,
    sameCapabilities,
    sortMembers,
    toggleCapability,
    type MemberRole,
    type TeamMember,
} from './team';

const member = (overrides: Partial<TeamMember>): TeamMember => ({
    id: 'm',
    kind: 'member',
    name: 'Anna Dupont',
    email: 'anna@example.com',
    phone: null,
    role: 'practitioner',
    base_role: 'practitioner',
    preset: 'practitioner',
    status: 'active',
    is_owner: false,
    is_self: false,
    practitioner_id: null,
    photo_version: null,
    last_login_at: null,
    created_at: null,
    invited_at: null,
    capabilities: [],
    level: 'limited',
    notes: null,
    editable: true,
    ...overrides,
});

const label = (role: MemberRole): string => ({ owner: 'Propriétaire', site_manager: 'Responsable', practitioner: 'Praticien', assistant: 'Assistant' })[role];

const team = [
    member({ id: 'owner', name: 'Salon Aurore', email: 'proprio@example.com', role: 'owner', is_owner: true, level: 'full' }),
    member({ id: 'a', name: 'Élodie Martin', email: 'elodie@example.com', role: 'assistant', status: 'invited', level: 'minimal' }),
    member({ id: 'b', name: 'Anna Dupont', email: 'anna@example.com', role: 'practitioner', last_login_at: '2026-10-09T10:00:00Z' }),
    member({
        id: 'c',
        name: 'Zoé Bernard',
        email: 'zoe@example.com',
        role: 'practitioner',
        status: 'suspended',
        last_login_at: '2026-10-01T10:00:00Z',
        level: 'broad',
    }),
];

describe('team', () => {
    it("donne deux lettres d'initiales, avec un repli", () => {
        expect(initials('Anna Dupont')).toBe('AD');
        expect(initials('élodie')).toBe('ÉL');
        expect(initials('  ')).toBe('?');
        expect(initials('anna.dupont@example.com')).toBe('AC');
    });

    it("cherche sans tenir compte des accents ni de la casse, dans le nom, l'adresse et le rôle", () => {
        const names = (q: string) => filterMembers(team, { ...emptyFilters, q }, label).map((m) => m.id);

        expect(names('elodie')).toEqual(['a']);
        expect(names('ÉLODIE')).toEqual(['a']);
        expect(names('zoe@')).toEqual(['c']);
        expect(names('praticien')).toEqual(['b', 'c']);
        expect(names('zzz')).toEqual([]);
    });

    it('filtre par rôle, statut et niveau d’accès, et compte une invitation expirée comme invitée', () => {
        const ids = (filters: Partial<typeof emptyFilters>) => filterMembers(team, { ...emptyFilters, ...filters }, label).map((m) => m.id);

        expect(ids({ role: 'practitioner' })).toEqual(['b', 'c']);
        expect(ids({ status: 'suspended' })).toEqual(['c']);
        expect(ids({ level: 'full' })).toEqual(['owner']);
        expect(filterMembers([member({ id: 'x', status: 'expired' })], { ...emptyFilters, status: 'invited' }, label)).toHaveLength(1);
        expect(ids({ role: 'practitioner', status: 'active' })).toEqual(['b']);
    });

    it('trie par nom, rôle, statut ou activité, le propriétaire toujours en tête', () => {
        const ids = (sort: Parameters<typeof sortMembers>[1], dir: 'asc' | 'desc' = 'asc') => sortMembers(team, sort, dir).map((m) => m.id);

        expect(ids('name')).toEqual(['owner', 'b', 'a', 'c']);
        expect(ids('name', 'desc')).toEqual(['owner', 'c', 'a', 'b']);
        expect(ids('role')).toEqual(['owner', 'b', 'c', 'a']);
        expect(ids('status')).toEqual(['owner', 'b', 'a', 'c']);
        expect(ids('activity', 'desc')).toEqual(['owner', 'b', 'c', 'a']);
    });

    it('calcule ce qui est ajouté et retiré', () => {
        expect(diffCapabilities(['a', 'b'], ['b', 'c'])).toEqual({ added: ['c'], removed: ['a'] });
        expect(diffCapabilities(['a'], ['a'])).toEqual({ added: [], removed: [] });
    });

    it('avertit d’un accès essentiel retiré, comme le serveur', () => {
        expect(accessWarnings('practitioner', [])).toEqual(['nothing']);
        expect(accessWarnings('practitioner', ['reports.view'])).toEqual(['no_dashboard', 'no_calendar']);
        expect(accessWarnings('receptionist', ['calendar.view'])).toEqual(['no_dashboard']);
        expect(accessWarnings('practitioner', ['dashboard.view', 'calendar.view'])).toEqual([]);
    });

    it('compare des ensembles sans tenir compte de l’ordre et compte un groupe', () => {
        expect(sameCapabilities(['a', 'b'], ['b', 'a'])).toBe(true);
        expect(sameCapabilities(['a'], ['a', 'b'])).toBe(false);
        expect(groupCount(['a', 'b', 'c'], ['b', 'x'])).toEqual({ on: 1, total: 3 });
    });

    it('active et désactive en gardant les implications', () => {
        expect(toggleCapability([], 'customers.view_all', true).sort()).toEqual(['customers.view_all', 'customers.view_own']);
        expect(toggleCapability(['customers.view_all', 'customers.view_own'], 'customers.view_own', false)).toEqual([]);
        expect(toggleCapability([], 'reports.all_data', true).sort()).toEqual(['reports.all_data', 'reports.view']);
        expect(toggleCapability(['reports.view', 'reports.all_data'], 'reports.view', false)).toEqual([]);
    });

    it('traduit le rôle affiché en rôle de base du serveur', () => {
        expect(baseRoleOf('assistant')).toBe('receptionist');
        expect(baseRoleOf('practitioner')).toBe('practitioner');
    });
});
