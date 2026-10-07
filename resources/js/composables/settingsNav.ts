import { routes } from '../routes';

export type SettingsKey = 'profile' | 'calendars' | 'whatsapp' | 'data' | 'security';

export interface SettingsItem {
    key: SettingsKey;
    href: string;
    /** Clé de traduction du libellé (celle déjà utilisée par l'ancien menu). */
    label: string;
    /** Préfixes d'adresse qui appartiennent à cette page (la page « Pause » fait partie de « Mes données »). */
    prefixes: string[];
}

export interface SettingsGroup {
    key: 'account' | 'integrations' | 'privacy';
    items: SettingsItem[];
}

/** Droits d'accès de chaque page, identiques à ceux des routes (routes/web.php). */
function visible(key: SettingsKey, role: string | null): boolean {
    if (role === null) {
        return false;
    }

    return {
        profile: true,
        calendars: role !== 'receptionist',
        whatsapp: role === 'owner',
        data: role === 'owner',
        security: true,
    }[key];
}

const all: Record<'account' | 'integrations' | 'privacy', SettingsItem[]> = {
    account: [{ key: 'profile', href: routes.profile, label: 'account_profile.nav', prefixes: [routes.profile] }],
    integrations: [
        { key: 'calendars', href: routes.calendars, label: 'calendars.nav', prefixes: [routes.calendars] },
        { key: 'whatsapp', href: routes.whatsapp, label: 'whatsapp.nav', prefixes: [routes.whatsapp] },
    ],
    privacy: [
        { key: 'data', href: routes.accountData, label: 'account_data.nav', prefixes: [routes.accountData, routes.accountPause, routes.accountDeletion] },
        { key: 'security', href: routes.security.show, label: 'security.nav', prefixes: [routes.security.show] },
    ],
};

/** Groupes du menu Réglages pour un rôle : un groupe sans page accessible disparaît. */
export function settingsGroups(role: string | null): SettingsGroup[] {
    return (['account', 'integrations', 'privacy'] as const)
        .map((key) => ({ key, items: all[key].filter((item) => visible(item.key, role)) }))
        .filter((group) => group.items.length > 0);
}

/** Première page accessible : destination du lien « Réglages » de la barre principale. */
export function settingsHome(role: string | null): string {
    return settingsGroups(role)[0]?.items[0]?.href ?? routes.security.show;
}

function pathOf(url: string): string {
    return url.split('?')[0].split('#')[0];
}

function matches(path: string, prefix: string): boolean {
    return path === prefix || path.startsWith(`${prefix}/`);
}

/** Page de réglages correspondant à une adresse, ou null hors des réglages. */
export function activeSettings(url: string): SettingsKey | null {
    const path = pathOf(url);

    return [...all.account, ...all.integrations, ...all.privacy].find((item) => item.prefixes.some((prefix) => matches(path, prefix)))?.key ?? null;
}
