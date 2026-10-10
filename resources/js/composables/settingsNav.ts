import { routes } from '../routes';

export type SetupCategory = 'business' | 'team' | 'messages' | 'launch';

export type SettingsKey =
    | 'setup-business'
    | 'setup-team'
    | 'setup-messages'
    | 'setup-launch'
    | 'profile'
    | 'team'
    | 'calendars'
    | 'whatsapp'
    | 'whatsapp-qr'
    | 'data'
    | 'legal'
    | 'security';

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

/** Droits d'accès de chaque page, identiques à ceux des routes (routes/web.php) : les permissions du membre, avec ses défauts de rôle. */
function visible(key: SettingsKey, role: string | null, permissions: string[]): boolean {
    if (role === null) {
        return false;
    }

    if (key.startsWith('setup-')) {
        return role === 'owner';
    }

    const has = (...capabilities: string[]): boolean => capabilities.some((capability) => permissions.includes(capability));

    return {
        profile: true,
        team: has('settings.team'),
        calendars: has('calendars.own', 'settings.integrations'),
        whatsapp: has('whatsapp.connect', 'whatsapp.templates'),
        'whatsapp-qr': has('whatsapp.connect'),
        data: role === 'owner',
        legal: true,
        security: true,
    }[key as 'profile' | 'team' | 'calendars' | 'whatsapp' | 'whatsapp-qr' | 'data' | 'legal' | 'security'];
}

function setupItem(category: SetupCategory): SettingsItem {
    const href = `${routes.setup}/${category}`;

    return { key: `setup-${category}`, href, label: `setup.categories.${category}.nav`, prefixes: [href], setup: category };
}

const all: Record<SettingsGroup['key'], SettingsItem[]> = {
    setup: [setupItem('business'), setupItem('team'), setupItem('messages'), setupItem('launch')],
    account: [
        { key: 'profile', href: routes.profile, label: 'account_profile.nav', prefixes: [routes.profile] },
        { key: 'team', href: routes.team, label: 'team.nav', prefixes: [routes.team] },
    ],
    integrations: [
        { key: 'calendars', href: routes.calendars, label: 'calendars.nav', prefixes: [routes.calendars] },
        { key: 'whatsapp', href: routes.whatsapp, label: 'whatsapp.nav', prefixes: [routes.whatsapp] },
        { key: 'whatsapp-qr', href: routes.whatsappQr, label: 'whatsapp_qr.nav', prefixes: [routes.whatsappQr] },
    ],
    privacy: [
        { key: 'data', href: routes.accountData, label: 'account_data.nav', prefixes: [routes.accountData, routes.accountPause, routes.accountDeletion] },
        { key: 'legal', href: routes.legalSettings, label: 'legal.nav', prefixes: [routes.legalSettings] },
        { key: 'security', href: routes.security.show, label: 'security.nav', prefixes: [routes.security.show] },
    ],
};

const order: SettingsGroup['key'][] = ['setup', 'account', 'integrations', 'privacy'];

/** Groupes du menu Réglages pour un rôle : un groupe sans page accessible disparaît. */
export function settingsGroups(role: string | null, permissions: string[] = []): SettingsGroup[] {
    return order.map((key) => ({ key, items: all[key].filter((item) => visible(item.key, role, permissions)) })).filter((group) => group.items.length > 0);
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
