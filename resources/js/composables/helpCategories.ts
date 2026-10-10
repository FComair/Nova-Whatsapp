import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '../types';
import { useT } from './useT';

/**
 * Nom et description d'une catégorie du centre d'aide. Le serveur les fournit dans la langue du client (catégories gérées par le
 * Super Admin, docs/30) ; à défaut, on retombe sur les textes du fichier de langue.
 */
export function useHelpCategories() {
    const page = usePage<SharedProps>();
    const { t } = useT();

    const info = (slug: string) => page.props.helpCategories?.[slug];

    return {
        categoryName: (slug: string): string => info(slug)?.name || t(`help.categories.${slug}.name`),
        categoryDescription: (slug: string): string => info(slug)?.description ?? t(`help.categories.${slug}.description`),
        categoryIcon: (slug: string): string => info(slug)?.icon ?? 'rocket',
    };
}
