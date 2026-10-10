import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

type Handler = (event: { detail: { visit: Record<string, unknown> } }) => unknown;

const handlers: Record<string, Handler> = {};
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, handler: Handler) => {
            handlers[name] = handler;
        },
        visit: (...args: unknown[]) => visit(...args),
    },
}));

const unsaved = await import('./unsaved');

function mountForm(attributes = ''): { form: HTMLFormElement; input: HTMLInputElement } {
    document.body.innerHTML = `<main><form ${attributes}><input name="name" value="Ana" /></form></main>`;

    return { form: document.querySelector('form')!, input: document.querySelector('input')! };
}

function type(input: HTMLInputElement, value: string): void {
    input.dispatchEvent(new Event('focusin', { bubbles: true }));
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeAll(() => unsaved.installUnsavedGuard());

beforeEach(() => {
    unsaved.clearUnsaved();
    visit.mockReset();
});

describe('modifications non enregistrées', () => {
    it('ne signale rien tant que rien n’a changé, ni quand on revient à la valeur de départ', () => {
        const { input } = mountForm();

        input.dispatchEvent(new Event('focusin', { bubbles: true }));
        expect(unsaved.hasUnsavedChanges()).toBe(false);

        type(input, 'Anaïs');
        expect(unsaved.hasUnsavedChanges()).toBe(true);

        type(input, 'Ana');
        expect(unsaved.hasUnsavedChanges()).toBe(false);
    });

    it('oublie les modifications après un enregistrement réussi', () => {
        const { input } = mountForm();
        type(input, 'Anaïs');

        handlers.success!({ detail: { visit: {} } });

        expect(unsaved.hasUnsavedChanges()).toBe(false);
    });

    it.each([['role="search"'], ['method="get"'], ['data-unsaved-ignore']])('ignore les formulaires de recherche et de filtres (%s)', (attributes) => {
        const { input } = mountForm(attributes);

        type(input, 'autre chose');

        expect(unsaved.hasUnsavedChanges()).toBe(false);
    });

    it('limite la question à la fenêtre ouverte', () => {
        const { input } = mountForm();
        type(input, 'Anaïs');

        expect(unsaved.hasUnsavedChanges(document.querySelector('main'))).toBe(true);
        expect(unsaved.hasUnsavedChanges(document.createElement('div'))).toBe(false);
    });
});

describe('quitter l’écran', () => {
    const leave = (url: string, method = 'get') => handlers.before!({ detail: { visit: { url: new URL(url, window.location.href), method } } });

    it('laisse partir sans question quand rien n’est à perdre', () => {
        mountForm();

        expect(leave('/clients')).toBeUndefined();
        expect(unsaved.unsavedPrompt.open).toBe(false);
    });

    it('bloque la visite et pose la question quand un formulaire est modifié', () => {
        const { input } = mountForm();
        type(input, 'Anaïs');

        expect(leave('/clients')).toBe(false);
        expect(unsaved.unsavedPrompt.open).toBe(true);

        unsaved.answerUnsaved('cancel');
        expect(unsaved.unsavedPrompt.open).toBe(false);
        expect(visit).not.toHaveBeenCalled();
    });

    it('« Quitter sans enregistrer » reprend la visite', async () => {
        const { input } = mountForm();
        type(input, 'Anaïs');
        leave('/clients');

        unsaved.answerUnsaved('leave');
        await Promise.resolve();
        await Promise.resolve();

        expect(visit).toHaveBeenCalledWith(expect.stringContaining('/clients'), expect.objectContaining({ method: 'get' }));
        expect(unsaved.hasUnsavedChanges()).toBe(false);
    });

    it('« Rester et enregistrer » envoie le formulaire sans quitter', async () => {
        const { form, input } = mountForm();
        form.requestSubmit = vi.fn();
        type(input, 'Anaïs');
        leave('/clients');

        unsaved.answerUnsaved('save');
        await Promise.resolve();
        await Promise.resolve();

        expect(form.requestSubmit).toHaveBeenCalled();
        expect(visit).not.toHaveBeenCalled();
    });

    it('ne gêne ni un enregistrement (non GET) ni un filtre sur la même page', () => {
        const { input } = mountForm();
        type(input, 'Anaïs');

        expect(leave('/clients/42', 'put')).toBeUndefined();
        expect(leave(window.location.pathname)).toBeUndefined();
    });

    it('ferme une fenêtre sans question s’il n’y a rien à perdre, avec question sinon', async () => {
        const close = vi.fn();
        mountForm();

        await unsaved.guardedLeave(close);
        expect(close).toHaveBeenCalledOnce();

        const { input } = mountForm();
        type(input, 'Anaïs');
        const pending = unsaved.guardedLeave(close);
        expect(unsaved.unsavedPrompt.open).toBe(true);
        unsaved.answerUnsaved('leave');
        await pending;

        expect(close).toHaveBeenCalledTimes(2);
    });
});
