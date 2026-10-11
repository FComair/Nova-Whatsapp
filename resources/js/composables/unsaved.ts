import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Protection contre la perte de modifications : un formulaire dont les champs ont changé depuis qu'on l'a ouvert est « modifié » jusqu'à ce qu'il
 * soit enregistré. Quitter la page (lien, bouton Retour, menu) ou fermer une fenêtre avec un formulaire modifié ouvre la confirmation à trois choix.
 * Les formulaires de recherche et de filtres (`role="search"`, `method="get"`, `data-unsaved-ignore`) ne comptent pas.
 */
export type LeaveChoice = 'leave' | 'save' | 'cancel';

export const unsavedPrompt = reactive({ open: false });

let snapshots = new WeakMap<HTMLFormElement, string>();
const dirty = new Set<HTMLFormElement>();
let resolver: ((choice: LeaveChoice) => void) | null = null;
let bypass = false;

function watched(form: HTMLFormElement | null): form is HTMLFormElement {
    return (
        form !== null &&
        !form.hasAttribute('data-unsaved-ignore') &&
        form.getAttribute('role') !== 'search' &&
        form.closest('[role="search"]') === null &&
        (form.getAttribute('method') ?? '').toLowerCase() !== 'get'
    );
}

function serialise(form: HTMLFormElement): string {
    return JSON.stringify([...new FormData(form).entries()].map(([name, value]) => [name, value instanceof File ? `${value.name}:${value.size}` : value]));
}

function formOf(event: Event): HTMLFormElement | null {
    const form = (event.target as Element | null)?.closest?.('form') ?? null;

    return watched(form) ? form : null;
}

function remember(event: Event): void {
    const form = formOf(event);

    if (form !== null && !snapshots.has(form)) {
        snapshots.set(form, serialise(form));
    }
}

function evaluate(event: Event): void {
    const form = formOf(event);

    if (form === null) {
        return;
    }

    const before = snapshots.get(form);

    if (before === undefined || before !== serialise(form)) {
        dirty.add(form);
    } else {
        dirty.delete(form);
    }
}

/** Vrai quand un formulaire (ou, avec `within`, un formulaire de cet élément) a des modifications non enregistrées. */
export function hasUnsavedChanges(within?: ParentNode | null): boolean {
    for (const form of dirty) {
        if (!form.isConnected) {
            dirty.delete(form);
        } else if (within === undefined || within === null || within.contains(form)) {
            return true;
        }
    }

    return false;
}

/** Oublie les modifications (après un enregistrement réussi ou un changement de page). */
export function clearUnsaved(): void {
    dirty.clear();
    snapshots = new WeakMap();
}

/** Ouvre la confirmation et attend le choix de la personne. */
export function askBeforeLeaving(): Promise<LeaveChoice> {
    resolver?.('cancel');
    unsavedPrompt.open = true;

    return new Promise((resolve) => {
        resolver = resolve;
    });
}

export function answerUnsaved(choice: LeaveChoice): void {
    unsavedPrompt.open = false;
    const done = resolver;
    resolver = null;
    done?.(choice);
}

/** Enregistre le premier formulaire modifié (choix « Rester et enregistrer »). */
function saveFirstUnsaved(): void {
    const form = [...dirty].find((candidate) => candidate.isConnected);

    form?.requestSubmit();
}

/**
 * Ferme une fenêtre (ou quitte un écran) en demandant d'abord confirmation si elle contient des modifications non enregistrées.
 * `proceed` s'exécute quand la fermeture est confirmée ou qu'il n'y a rien à perdre.
 */
export async function guardedLeave(proceed: () => void, within?: ParentNode | null): Promise<void> {
    if (!hasUnsavedChanges(within)) {
        proceed();

        return;
    }

    const choice = await askBeforeLeaving();

    if (choice === 'leave') {
        clearUnsaved();
        proceed();
    } else if (choice === 'save') {
        saveFirstUnsaved();
    }
}

let installed = false;

/** Branche la détection des modifications et le blocage de la navigation (une seule fois pour toute l'application). */
export function installUnsavedGuard(): void {
    if (installed || typeof document === 'undefined') {
        return;
    }

    installed = true;

    for (const type of ['focusin', 'pointerdown', 'keydown']) {
        document.addEventListener(type, remember, true);
    }

    document.addEventListener('input', evaluate, true);
    document.addEventListener('change', evaluate, true);
    router.on('success', clearUnsaved);

    // Quitter l'écran par un lien ou un bouton Retour : visite GET vers une autre page, bloquée tant qu'on n'a pas choisi.
    router.on('before', (event) => {
        const visit = event.detail.visit;
        const target = visit.url instanceof URL ? visit.url : new URL(String(visit.url), window.location.href);

        if (bypass || visit.method !== 'get' || !hasUnsavedChanges() || target.pathname === window.location.pathname) {
            return;
        }

        void askBeforeLeaving().then((choice) => {
            if (choice === 'leave') {
                clearUnsaved();
                bypass = true;
                router.visit(target.href, { method: 'get', replace: visit.replace, preserveScroll: visit.preserveScroll, preserveState: visit.preserveState });
                bypass = false;
            } else if (choice === 'save') {
                saveFirstUnsaved();
            }
        });

        return false;
    });

    // Fermeture de l'onglet, rechargement ou autre site : confirmation du navigateur.
    window.addEventListener('beforeunload', (event) => {
        if (hasUnsavedChanges()) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
}
