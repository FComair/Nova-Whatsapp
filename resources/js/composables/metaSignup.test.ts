import { afterEach, describe, expect, it, vi } from 'vitest';
import { parseSignupEvent, startMetaSignup } from './metaSignup';

const message = (event: string, data: Record<string, unknown> = {}): string => JSON.stringify({ type: 'WA_EMBEDDED_SIGNUP', event, data, version: '3' });

describe('parseSignupEvent', () => {
    it("lit la fin de l'inscription avec les identifiants", () => {
        expect(parseSignupEvent('https://www.facebook.com', message('FINISH', { phone_number_id: '104', waba_id: '204' }))).toEqual({
            type: 'finish',
            phone_number_id: '104',
            waba_id: '204',
        });
        expect(parseSignupEvent('https://web.facebook.com', message('FINISH', { phone_number_id: '1', waba_id: '2' }))?.type).toBe('finish');
    });

    it("lit l'abandon", () => {
        expect(parseSignupEvent('https://www.facebook.com', message('CANCEL', { current_step: 'PHONE_NUMBER_SETUP' }))).toEqual({ type: 'cancel' });
    });

    it('traite une fin sans identifiants ou un événement inconnu comme une erreur', () => {
        expect(parseSignupEvent('https://www.facebook.com', message('FINISH'))).toEqual({ type: 'error' });
        expect(parseSignupEvent('https://www.facebook.com', message('AUTRE'))).toEqual({ type: 'error' });
    });

    it("ignore tout message d'une autre origine", () => {
        expect(parseSignupEvent('https://evil.example', message('FINISH', { phone_number_id: '1', waba_id: '2' }))).toBeNull();
        expect(parseSignupEvent('https://www.facebook.com.evil.example', message('FINISH', { phone_number_id: '1', waba_id: '2' }))).toBeNull();
    });

    it("ignore ce qui n'est pas un message d'inscription", () => {
        expect(parseSignupEvent('https://www.facebook.com', 'pas du json')).toBeNull();
        expect(parseSignupEvent('https://www.facebook.com', JSON.stringify({ type: 'AUTRE' }))).toBeNull();
        expect(parseSignupEvent('https://www.facebook.com', { type: 'WA_EMBEDDED_SIGNUP' })).toBeNull();
    });

    it("n'accepte que des identifiants textuels", () => {
        expect(parseSignupEvent('https://www.facebook.com', message('FINISH', { phone_number_id: 104, waba_id: 204 }))).toEqual({ type: 'error' });
    });
});

describe('startMetaSignup', () => {
    afterEach(() => {
        delete window.FB;
        vi.useRealTimers();
    });

    const config = { app_id: '1', config_id: '2', version: 'v21.0' };

    it("refuse de démarrer tant que le module de Meta n'est pas chargé", async () => {
        await expect(startMetaSignup(config)).rejects.toThrow('sdk');
    });

    it("ouvre la fenêtre de Meta immédiatement, sans attente avant l'appel", () => {
        const login = vi.fn();
        window.FB = { init: vi.fn(), login };

        void startMetaSignup(config);

        expect(login).toHaveBeenCalledTimes(1);
        expect(login.mock.calls[0][1]).toMatchObject({ config_id: '2', response_type: 'code' });
    });

    it('prévient quand la page garde le focus (fenêtre probablement bloquée)', () => {
        vi.useFakeTimers();
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        window.FB = { init: vi.fn(), login: vi.fn() };
        const onPopupBlocked = vi.fn();

        void startMetaSignup(config, { onPopupBlocked });
        vi.advanceTimersByTime(3_000);

        expect(onPopupBlocked).toHaveBeenCalledTimes(1);
    });

    it('ne prévient pas quand la fenêtre a pris le focus', () => {
        vi.useFakeTimers();
        window.FB = { init: vi.fn(), login: vi.fn() };
        const onPopupBlocked = vi.fn();

        void startMetaSignup(config, { onPopupBlocked });
        window.dispatchEvent(new Event('blur'));
        vi.advanceTimersByTime(3_000);

        expect(onPopupBlocked).not.toHaveBeenCalled();
    });

    it('résout avec le code et les identifiants', async () => {
        window.FB = { init: vi.fn(), login: (callback) => callback({ authResponse: { code: 'abc' } }) };
        const pending = startMetaSignup(config);
        window.dispatchEvent(
            new MessageEvent('message', { origin: 'https://www.facebook.com', data: message('FINISH', { phone_number_id: '1', waba_id: '2' }) }),
        );

        await expect(pending).resolves.toEqual({ code: 'abc', phone_number_id: '1', waba_id: '2' });
    });
});
