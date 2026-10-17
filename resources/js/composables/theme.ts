import { ref } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';

// Clé historique, volontairement conservée : la renommer ferait perdre à chacun son choix de thème (produit rebaptisé Rendivoo, docs/42).
export const THEME_STORAGE_KEY = 'nova-theme';
export const THEME_PREFERENCES: readonly ThemePreference[] = ['light', 'dark', 'system'];

const preference = ref<ThemePreference>('system');

function isPreference(value: unknown): value is ThemePreference {
    return typeof value === 'string' && (THEME_PREFERENCES as readonly string[]).includes(value);
}

function storedPreference(): ThemePreference {
    try {
        const value = window.localStorage.getItem(THEME_STORAGE_KEY);

        return isPreference(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

export function resolveTheme(value: ThemePreference, systemPrefersDark: boolean): 'light' | 'dark' {
    return value === 'system' ? (systemPrefersDark ? 'dark' : 'light') : value;
}

function systemPrefersDark(): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function apply(): void {
    document.documentElement.dataset.theme = resolveTheme(preference.value, systemPrefersDark());
}

/** À appeler une fois au démarrage : lit le choix enregistré, l'applique et suit le thème du système. */
export function initTheme(): void {
    preference.value = storedPreference();
    apply();

    if (typeof window.matchMedia === 'function') {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (preference.value === 'system') {
                apply();
            }
        });
    }
}

export function useTheme() {
    function setPreference(value: ThemePreference): void {
        preference.value = value;

        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, value);
        } catch {
            // Stockage indisponible (navigation privée) : le choix vaut pour cette session seulement.
        }

        apply();
    }

    return { preference, setPreference };
}
