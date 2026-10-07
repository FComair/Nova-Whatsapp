import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '../types';

type Params = Record<string, string | number>;

/**
 * Traduction côté interface. Les textes viennent de lang/{locale}/ui.php via les props Inertia partagées :
 * une seule source de vérité pour le serveur et le front (FR / NL / EN).
 */
export function useT() {
    const page = usePage<SharedProps>();

    function t(key: string, params: Params = {}): string {
        const value = key
            .split('.')
            .reduce<unknown>(
                (node, part) => (node !== null && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined),
                page.props.translations,
            );

        if (typeof value !== 'string') {
            return key;
        }

        return value.replace(/:(\w+)/g, (_match, name: string) => String(params[name] ?? `:${name}`));
    }

    return { t, locale: computed(() => page.props.locale) };
}
