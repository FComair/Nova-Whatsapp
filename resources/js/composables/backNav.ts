import { routes } from '../routes';
import { settingsHome } from './settingsNav';

/** Où ramène le bouton « Retour » d'un écran, et comment il s'appelle (clé de traduction `back_nav.*`). */
export interface BackTarget {
    label: string;
    href: string;
}

type Rule = [pattern: RegExp, target: (match: RegExpMatchArray) => BackTarget | null];

const to = (label: string, href: string): BackTarget => ({ label, href });
const dashboard = to('back_nav.dashboard', routes.dashboard);

/**
 * Retour de chaque écran de l'espace client, du plus précis au plus général. Un écran de premier niveau (menu principal) revient au tableau de bord ;
 * un écran plus profond revient à son parent. Le tableau de bord lui-même n'a pas de retour. Reste aligné sur routes/web.php.
 */
const rules: Rule[] = [
    [/^\/tableau-de-bord/, () => null],

    [/^\/clients\/fidelite\/(clients|messages|programme|rapport|utiliser)/, () => to('back_nav.loyalty', routes.loyalty)],
    [/^\/clients\/fidelite\/?$/, () => to('back_nav.customers', routes.customers)],
    [/^\/clients\/[^/]+$/, () => to('back_nav.customers', routes.customers)],
    [/^\/clients\/?$/, () => dashboard],

    [/^\/facturation\/.+/, () => to('back_nav.billing', routes.billing)],
    [/^\/facturation\/?$/, () => dashboard],

    [/^\/rapports\/.+/, () => to('back_nav.reports', routes.reports)],
    [/^\/rapports\/?$/, () => dashboard],

    [/^\/aide\/tickets\/[^/]+/, () => to('back_nav.tickets', `${routes.help}/tickets`)],
    [/^\/aide\/(tickets|contact|recherche|avis)(\/|$)/, () => to('back_nav.help', routes.help)],
    [/^\/aide\/([^/]+)\/[^/]+$/, (m) => to('back_nav.help_topic', `${routes.help}/${m[1]}`)],
    [/^\/aide\/[^/]+$/, () => to('back_nav.help', routes.help)],
    [/^\/aide\/?$/, () => dashboard],

    [/^\/qr-whatsapp/, () => to('back_nav.whatsapp', routes.whatsapp)],
    [/^\/configuration\/services\/import/, () => to('back_nav.settings', settingsHome())],
    [/^\/reglages\/demarrage\/.+/, () => to('back_nav.settings', settingsHome())],

    // Pages de réglages et de compte : un seul niveau sous le tableau de bord.
    [/^\/(reglages|securite|whatsapp|calendriers|compte|mon-profil|planning)(\/|$)/, () => dashboard],
];

/** Retour de l'écran à l'adresse donnée, ou `null` quand il n'en a pas (tableau de bord, écrans hors espace principal). */
export function backTarget(url: string): BackTarget | null {
    const path = url.split(/[?#]/)[0] ?? '';

    for (const [pattern, target] of rules) {
        const match = path.match(pattern);

        if (match !== null) {
            return target(match);
        }
    }

    return null;
}

const pathOf = (url: string): string => url.split(/[?#]/)[0]?.replace(/\/+$/, '') ?? '';

// Pile des écrans visités pendant cette session : « Retour » rejoue l'historique du navigateur quand l'écran précédent est bien le parent
// (même liste, mêmes filtres, même page, même défilement), sinon il va à l'adresse du parent.
let stack: string[] = [];

/** À appeler à chaque navigation Inertia avec l'adresse de la page affichée. */
export function trackNavigation(url: string): void {
    if (stack.length >= 2 && stack[stack.length - 2] === url) {
        stack.pop();

        return;
    }

    if (stack[stack.length - 1] !== url) {
        stack = [...stack, url].slice(-50);
    }
}

/** Vrai quand l'écran précédent est le parent visé : « Retour » peut alors revenir en arrière dans l'historique. */
export function canReturnInHistory(href: string): boolean {
    const previous = stack[stack.length - 2];

    return previous !== undefined && pathOf(previous) === pathOf(href);
}

export function resetNavigationTrail(): void {
    stack = [];
}
