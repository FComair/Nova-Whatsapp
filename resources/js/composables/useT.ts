import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '../types';

type Params = Record<string, string | number>;

/**
 * Traduction côté interface. Les textes viennent de lang/{locale}/ui.php via les props Inertia partagées :
 * une seule source de vérité pour le serveur et le front (FR / NL / EN).
 */
/** « un|plusieurs » : singulier pour 1 (et 0 en français), pluriel sinon, quand le paramètre `count` est fourni. */
function plural(value: string, count: number, locale: string): string {
    const forms = value.split('|');

    if (forms.length < 2) {
        return value;
    }

    const singular = locale === 'fr' ? count < 2 : count === 1;

    return singular ? forms[0] : forms[1];
}

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

        const text = typeof params.count === 'number' ? plural(value, params.count, page.props.locale) : value;

        return text.replace(/:(\w+)/g, (_match, name: string) => String(params[name] ?? `:${name}`));
    }

    return { t, locale: computed(() => page.props.locale) };
}
