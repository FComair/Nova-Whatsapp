/**
 * Inscription intégrée de Meta (« Embedded Signup », docs/17) : le module de Meta n'est chargé qu'au clic de l'utilisateur
 * (c'est à ce moment seulement que Meta reçoit son adresse IP), puis le navigateur renvoie à Nova un code à usage unique
 * et les identifiants du numéro et du compte. Le jeton est échangé côté serveur : il ne passe jamais par le navigateur.
 */

export interface MetaSignupConfig {
    app_id: string;
    config_id: string;
    version: string;
}

export interface MetaSignupResult {
    code: string;
    phone_number_id: string;
    waba_id: string;
}

export type SignupEvent = { type: 'finish'; phone_number_id: string; waba_id: string } | { type: 'cancel' } | { type: 'error' };

/** Seules origines autorisées à envoyer des messages d'inscription : celles de Meta. */
const META_ORIGINS = ['https://www.facebook.com', 'https://web.facebook.com'];

/**
 * Lit un message `window.postMessage` de la fenêtre d'inscription. Renvoie null pour tout message qui n'en est pas un
 * (autre origine, autre format) : il est ignoré, jamais interprété.
 */
export function parseSignupEvent(origin: string, data: unknown): SignupEvent | null {
    if (!META_ORIGINS.includes(origin) || typeof data !== 'string') {
        return null;
    }

    let message: { type?: unknown; event?: unknown; data?: { phone_number_id?: unknown; waba_id?: unknown } };

    try {
        message = JSON.parse(data) as typeof message;
    } catch {
        return null;
    }

    if (message.type !== 'WA_EMBEDDED_SIGNUP') {
        return null;
    }

    if (message.event === 'CANCEL') {
        return { type: 'cancel' };
    }

    const phone = message.data?.phone_number_id;
    const waba = message.data?.waba_id;

    if ((message.event === 'FINISH' || message.event === 'FINISH_ONLY_WABA') && typeof phone === 'string' && typeof waba === 'string') {
        return { type: 'finish', phone_number_id: phone, waba_id: waba };
    }

    return { type: 'error' };
}

interface FacebookSdk {
    init(options: { appId: string; autoLogAppEvents: boolean; xfbml: boolean; version: string }): void;
    login(callback: (response: { authResponse?: { code?: string } | null }) => void, options: Record<string, unknown>): void;
}

declare global {
    interface Window {
        FB?: FacebookSdk;
    }
}

function loadSdk(config: MetaSignupConfig): Promise<FacebookSdk> {
    return new Promise((resolve, reject) => {
        const ready = (): void => {
            if (!window.FB) {
                reject(new Error('sdk'));

                return;
            }

            window.FB.init({ appId: config.app_id, autoLogAppEvents: false, xfbml: false, version: config.version });
            resolve(window.FB);
        };

        if (window.FB) {
            ready();

            return;
        }

        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.onload = ready;
        script.onerror = () => reject(new Error('sdk'));
        document.head.append(script);
    });
}

/** Ouvre la fenêtre de Meta. Résout avec le code et les identifiants ; rejette (`cancel`, `error`, `sdk`, `timeout`) sinon. */
export async function startMetaSignup(config: MetaSignupConfig, timeoutMs = 300_000): Promise<MetaSignupResult> {
    const sdk = await loadSdk(config);

    return new Promise((resolve, reject) => {
        let code: string | null = null;
        let ids: { phone_number_id: string; waba_id: string } | null = null;

        const finish = (): void => {
            if (code !== null && ids !== null) {
                cleanup();
                resolve({ code, ...ids });
            }
        };
        const fail = (reason: string): void => {
            cleanup();
            reject(new Error(reason));
        };
        const listener = (event: MessageEvent): void => {
            const parsed = parseSignupEvent(event.origin, event.data);

            if (parsed?.type === 'finish') {
                ids = { phone_number_id: parsed.phone_number_id, waba_id: parsed.waba_id };
                finish();
            } else if (parsed !== null) {
                fail(parsed.type);
            }
        };
        const timer = window.setTimeout(() => fail('timeout'), timeoutMs);
        const cleanup = (): void => {
            window.removeEventListener('message', listener);
            window.clearTimeout(timer);
        };

        window.addEventListener('message', listener);

        sdk.login(
            (response) => {
                if (response.authResponse?.code) {
                    code = response.authResponse.code;
                    finish();
                } else {
                    fail('cancel');
                }
            },
            { config_id: config.config_id, response_type: 'code', override_default_response_type: true, extras: { setup: {}, sessionInfoVersion: '3' } },
        );
    });
}
