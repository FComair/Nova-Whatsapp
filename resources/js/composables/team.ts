/** Équipe et accès (Réglages > Équipe et accès, docs/34) : types et fonctions pures, testées à part. */

export type MemberStatus = 'active' | 'invited' | 'expired' | 'suspended' | 'removed';
export type MemberRole = 'owner' | 'site_manager' | 'practitioner' | 'assistant';
export type AccessLevel = 'full' | 'broad' | 'limited' | 'minimal';
export type TeamSort = 'name' | 'role' | 'status' | 'activity';

export interface TeamMember {
    id: string;
    kind: 'member' | 'invitation';
    name: string;
    email: string;
    phone: string | null;
    role: MemberRole;
    base_role: string;
    preset: 'practitioner' | 'assistant' | 'custom' | null;
    status: MemberStatus;
    is_owner: boolean;
    is_self: boolean;
    practitioner_id: string | null;
    photo_version: number | null;
    last_login_at: string | null;
    created_at: string | null;
    invited_at: string | null;
    expires_at?: string;
    capabilities: string[];
    level: AccessLevel;
    notes: string | null;
    upcoming_appointments?: number;
    editable: boolean;
}

export interface TeamCatalog {
    groups: Record<string, string[]>;
    protected: string[];
}

export interface TeamPreset {
    role: string;
    capabilities: string[];
}

export interface TeamFilters {
    q: string;
    role: string;
    status: string;
    level: string;
}

export const emptyFilters: TeamFilters = { q: '', role: '', status: '', level: '' };

/** Initiales d'un nom (deux lettres au plus), pour l'avatar quand il n'y a pas de photo. */
export function initials(name: string): string {
    const parts = name
        .trim()
        .split(/[\s@._-]+/)
        .filter((part) => part !== '');

    return (parts.length === 0 ? '?' : parts.length === 1 ? parts[0].slice(0, 2) : parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function normalise(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/** Recherche (nom, adresse, rôle) et filtres (rôle, statut, niveau d'accès). */
export function filterMembers(members: TeamMember[], filters: TeamFilters, roleLabel: (role: MemberRole) => string): TeamMember[] {
    const needle = normalise(filters.q.trim());

    return members.filter((member) => {
        if (needle !== '' && !normalise(`${member.name} ${member.email} ${roleLabel(member.role)}`).includes(needle)) {
            return false;
        }

        return (
            (filters.role === '' || member.role === filters.role) &&
            (filters.status === '' || member.status === filters.status || (filters.status === 'invited' && member.status === 'expired')) &&
            (filters.level === '' || member.level === filters.level)
        );
    });
}

const statusOrder: Record<MemberStatus, number> = { active: 0, invited: 1, expired: 2, suspended: 3, removed: 4 };
const roleOrder: Record<MemberRole, number> = { owner: 0, site_manager: 1, practitioner: 2, assistant: 3 };

/** Tri par nom, rôle, statut ou dernière activité ; le propriétaire reste toujours en tête. */
export function sortMembers(members: TeamMember[], sort: TeamSort, direction: 'asc' | 'desc' = 'asc'): TeamMember[] {
    const sign = direction === 'asc' ? 1 : -1;
    const compare = (a: TeamMember, b: TeamMember): number => {
        switch (sort) {
            case 'role':
                return roleOrder[a.role] - roleOrder[b.role] || a.name.localeCompare(b.name);
            case 'status':
                return statusOrder[a.status] - statusOrder[b.status] || a.name.localeCompare(b.name);
            case 'activity':
                return (a.last_login_at ?? '').localeCompare(b.last_login_at ?? '') || a.name.localeCompare(b.name);
            default:
                return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
        }
    };

    return [...members].sort((a, b) => (a.is_owner === b.is_owner ? sign * compare(a, b) : a.is_owner ? -1 : 1));
}

export interface AccessDiff {
    added: string[];
    removed: string[];
}

/** Ce qui est ajouté et retiré entre deux jeux de capacités. */
export function diffCapabilities(before: string[], after: string[]): AccessDiff {
    return { added: after.filter((capability) => !before.includes(capability)), removed: before.filter((capability) => !after.includes(capability)) };
}

/** Avertissements d'une configuration qui retire des accès essentiels (mêmes règles que le serveur). */
export function accessWarnings(role: string, capabilities: string[]): string[] {
    if (capabilities.length === 0) {
        return ['nothing'];
    }

    const warnings: string[] = [];

    if (!capabilities.includes('dashboard.view')) {
        warnings.push('no_dashboard');
    }

    if (role === 'practitioner' && !capabilities.includes('calendar.view')) {
        warnings.push('no_calendar');
    }

    return warnings;
}

/** Même ensemble de capacités, quel que soit l'ordre ? */
export function sameCapabilities(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((capability) => b.includes(capability));
}

/** Combien de capacités d'un groupe sont accordées. */
export function groupCount(keys: string[], granted: string[]): { on: number; total: number } {
    return { on: keys.filter((key) => granted.includes(key)).length, total: keys.length };
}

/** Rôle de base (côté serveur) à envoyer pour un rôle affiché. */
export function baseRoleOf(role: MemberRole | 'practitioner' | 'assistant'): 'practitioner' | 'receptionist' {
    return role === 'assistant' ? 'receptionist' : 'practitioner';
}

/** Les implications du serveur : voir tous les clients suppose de voir les siens ; voir toute l'activité suppose de voir les rapports. */
const implies: Record<string, string[]> = { 'customers.view_all': ['customers.view_own'], 'reports.all_data': ['reports.view'] };

/** Active ou désactive une capacité en gardant la cohérence : activer « tout voir » active aussi « voir les siens », et inversement. */
export function toggleCapability(granted: string[], key: string, on: boolean): string[] {
    const next = new Set(granted);

    if (on) {
        next.add(key);
        (implies[key] ?? []).forEach((other) => next.add(other));
    } else {
        next.delete(key);

        for (const [parent, children] of Object.entries(implies)) {
            if (children.includes(key)) {
                next.delete(parent);
            }
        }
    }

    return [...next];
}
