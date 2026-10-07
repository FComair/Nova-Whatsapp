import { routes } from '../routes';

export type SetupCategory = 'business' | 'team' | 'messages' | 'launch';

export type SettingsKey = 'setup-business' | 'setup-team' | 'setup-messages' | 'setup-launch' | 'profile' | 'calendars' | 'whatsapp' | 'data' | 'security';

export interface SettingsItem {
    key: SettingsKey;
    href: string;
    /** Clé de traduction du libellé. */
    label: string;
    /** Préfixes d'adresse qui appartiennent à cette page (la page « Pause » fait partie de « Mes données »). */
    prefixes: string[];
    /** Catégorie de « Démarrage » représentée par cette entrée (pour la pastille « reste à faire »). */
    setup?: SetupCategory;
}

export interface SettingsGroup {
    key: 'setup' | 'account' | 'integrations' | 'privacy';
    items: SettingsItem[];
}

/** Droits d'accès de chaque page, identiques à ceux des routes (routes/web.php). */
function visible(key: SettingsKey, role: string | null): boolean {
    if (role === null) {
        return false;
    }

    if (key.startsWith('setup-')) {
        return role === 'owner';
    }

    return {
        profile: true,
        calendars: role !== 'receptionist',
        whatsapp: role === 'owner',
        data: role === 'owner',
        security: true,
    }[key as 'profile' | 'calendars' | 'whatsapp' | 'data' | 'security'];
}

function setupItem(category: SetupCategory): SettingsItem {
    const href = `${routes.setup}/${category}`;

    return { key: `setup-${category}`, href, label: `setup.categories.${category}.nav`, prefixes: [href], setup: category };
}

const all: Record<SettingsGroup['key'], SettingsItem[]> = {
    setup: [setupItem('business'), setupItem('team'), setupItem('messages'), setupItem('launch')],
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

const order: SettingsGroup['key'][] = ['setup', 'account', 'integrations', 'privacy'];

/** Groupes du menu Réglages pour un rôle : un groupe sans page accessible disparaît. */
export function settingsGroups(role: string | null): SettingsGroup[] {
    return order.map((key) => ({ key, items: all[key].filter((item) => visible(item.key, role)) })).filter((group) => group.items.length > 0);
}

/** Page d'accueil des réglages : le profil, accessible à tous les rôles. */
export function settingsHome(): string {
    return routes.profile;
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

    return order.flatMap((key) => all[key]).find((item) => item.prefixes.some((prefix) => matches(path, prefix)))?.key ?? null;
}
