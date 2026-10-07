import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';

/**
 * Montage des pages récentes avec des props réalistes : une page qui lève une erreur au rendu s'affiche en « écran blanc »
 * dans le navigateur, sans que ni le serveur ni les tests PHP ne le voient.
 */
const shared = reactive({
    locale: 'fr',
    locales: ['fr', 'nl', 'en'],
    translations: {} as Record<string, unknown>,
    auth: { user: { id: 'u1', email: 'proprio@example.com', locale: 'fr' }, organization: { id: 'o1', name: 'Salon Aurore', status: 'active', role: 'owner' } },
    flash: { status: null, warning: null, first_login: false, suggestions: [] },
    errors: {} as Record<string, string>,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    // Composants factices en objets simples (pas de defineComponent : un seul composant par fichier selon la règle de lint).
    Head: { render: () => null },
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), delete: vi.fn(), reset: vi.fn() }),
}));

const steps = ['company', 'locations', 'hours', 'services', 'practitioners', 'policies', 'whatsapp', 'templates', 'test'].map((key, index) => ({
    key,
    number: index + 1,
    slug: key,
    status: index < 6 ? 'done' : index === 6 ? 'current' : 'todo',
    available: index < 8,
}));
const wizard = { steps, current: 'whatsapp', step: 'whatsapp', total: 9 };
const whatsapp = { account: null, templates: [], allApproved: false, embedded: null };
const connected = {
    account: { phone_number_id: '104500000000001', waba_id: '204500000000002', display_phone: '+32 470 12 34 56' },
    templates: [
        { key: 'reminder', name: 'appointment_reminder', language: 'fr', status: 'approved', reason: null },
        { key: 'reminder', name: 'appointment_reminder', language: 'nl', status: 'missing', reason: null },
        { key: 'cancellation', name: 'appointment_cancelled', language: 'fr', status: 'rejected', reason: 'INVALID_FORMAT' },
        { key: 'cancellation', name: 'appointment_cancelled', language: 'nl', status: 'pending', reason: null },
    ],
    allApproved: false,
    embedded: { app_id: '1', config_id: '2', version: 'v21.0' },
};

async function page(path: string): Promise<ReturnType<typeof defineComponent>> {
    const modules = import.meta.glob('./**/*.vue');
    const load = modules[`./${path}.vue`];

    if (load === undefined) {
        throw new Error(`Page introuvable : ${path}`);
    }

    return ((await load()) as { default: ReturnType<typeof defineComponent> }).default;
}

describe('pages récentes', () => {
    beforeEach(() => {
        shared.flash = { status: null, warning: null, first_login: false, suggestions: [] };
        shared.errors = {};
    });

    it.each<[string, Record<string, unknown>]>([
        ['Onboarding/Whatsapp', { wizard, ...whatsapp }],
        ['Onboarding/Whatsapp', { wizard, ...connected }],
        ['Onboarding/Templates', { wizard: { ...wizard, step: 'templates', current: 'templates' }, ...whatsapp }],
        ['Onboarding/Templates', { wizard: { ...wizard, step: 'templates', current: 'templates' }, ...connected }],
        ['Account/Whatsapp', whatsapp],
        ['Account/Whatsapp', connected],
        ['Account/Pause', { status: 'active', canPause: true, maxDays: 90, defaultDays: 30, pauseUntil: null, message: null, upcoming: 2, expired: false }],
        [
            'Account/Pause',
            {
                status: 'paused',
                canPause: false,
                maxDays: 90,
                defaultDays: 30,
                pauseUntil: '2026-11-01T10:00:00Z',
                message: 'Fermé',
                upcoming: 0,
                expired: false,
            },
        ],
        [
            'Account/Security',
            {
                passwordChangedAt: null,
                passwordOld: false,
                twoFactor: { enabled: false, required: true, setup: { secret: 'ABC', qr: 'data:image/svg+xml;base64,AAA=' }, remainingCodes: 0 },
                recoveryCodes: null,
            },
        ],
        [
            'Account/Setup',
            {
                category: {
                    key: 'team',
                    total: 4,
                    done: 1,
                    left: 2,
                    complete: false,
                    steps: [
                        { key: 'services', state: 'done', optional: false, href: '/configuration/assistant/services', wizard: true },
                        { key: 'practitioners', state: 'current', optional: false, href: '/configuration/assistant/praticiens', wizard: true },
                        { key: 'policies', state: 'todo', optional: false, href: '/configuration/assistant/politiques', wizard: true },
                        { key: 'calendar', state: 'todo', optional: true, href: '/calendriers', wizard: false },
                    ],
                },
            },
        ],
        [
            'Account/Setup',
            {
                category: {
                    key: 'launch',
                    total: 2,
                    done: 1,
                    left: 0,
                    complete: true,
                    steps: [
                        { key: 'test_booking', state: 'soon', optional: false, href: null, wizard: true },
                        { key: 'two_factor', state: 'done', optional: false, href: '/securite', wizard: false },
                    ],
                },
            },
        ],
        [
            'Account/Profile',
            {
                personal: {
                    email: 'proprio@example.com',
                    pendingEmail: 'nouvelle@example.com',
                    phone: '+32470123456',
                    pendingPhone: '+32470222222',
                    locale: 'fr',
                },
                canEditCompany: true,
                company: { name: 'Salon Aurore', sector: 'hairdressing', country: 'BE', timezone: 'Europe/Brussels', locale: 'fr' },
                billing: { billing_name: 'Aurore SRL', vat_number: 'BE0123456789', line1: 'Rue du Test 12', postal_code: '1000', city: 'Bruxelles' },
                sectors: [
                    { value: 'hairdressing', label: 'Coiffeur & Barbier' },
                    { value: 'beauty', label: 'Beauté et esthétique' },
                ],
                countries: ['BE', 'FR'],
                timezones: ['Europe/Brussels', 'Europe/Paris'],
            },
        ],
        [
            'Account/Profile',
            {
                personal: { email: 'resp@example.com', pendingEmail: null, phone: null, pendingPhone: null, locale: 'nl' },
                canEditCompany: false,
                company: { name: 'Salon Aurore', sector: 'hairdressing', country: 'BE', timezone: 'Europe/Brussels', locale: 'fr' },
                billing: null,
                sectors: [
                    { value: 'hairdressing', label: 'Coiffeur & Barbier' },
                    { value: 'beauty', label: 'Beauté et esthétique' },
                ],
                countries: ['BE', 'FR'],
                timezones: ['Europe/Brussels', 'Europe/Paris'],
            },
        ],
        [
            'Dashboard',
            {
                onboarding: null,
                profile: null,
                trial: { days: 5 },
                firstSteps: [{ key: 'whatsapp', done: false }],
                summary: {
                    date: '2026-10-01',
                    counts: { today: 1, remaining: 1, completed: 0, cancelled: 0, tomorrow: 0, week: 1, new_customers: 0 },
                    next: null,
                    appointments: [],
                    truncated: false,
                    multi_site: false,
                },
            },
        ],
    ])('%s se monte sans erreur', async (path, props) => {
        const errors: unknown[] = [];
        const wrapper = mount(await page(path), { props, global: { config: { errorHandler: (error: unknown) => errors.push(error) } } });

        expect(errors).toEqual([]);
        expect(wrapper.html().length).toBeGreaterThan(100);
    });
});
