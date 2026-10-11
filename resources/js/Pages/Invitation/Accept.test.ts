import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Accept from './Accept.vue';

const post = vi.fn();
const shared = reactive({ locale: 'fr', locales: ['fr', 'nl', 'en'], translations: {}, auth: { user: null, organization: null }, flash: {}, errors: {} });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (data: Record<string, unknown>) =>
        reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            post: (...args: unknown[]) => post(...args),
            reset: vi.fn(),
        }),
}));

const legal = (override: Record<string, unknown> = {}) => ({
    documents: [
        { code: 'terms', slug: 'terms-of-use', title: 'Conditions d’utilisation', version: '2026-10-01', href: '/legal/terms-of-use' },
        { code: 'privacy', slug: 'privacy-policy', title: 'Politique de confidentialité', version: '2026-10-01', href: '/legal/privacy-policy' },
    ],
    ...override,
});
const mountAccept = (props: Record<string, unknown> = {}) =>
    mount(Accept, {
        props: { token: 'tok', organization: 'Salon Aurore', name: 'Léa', email: 'lea@example.com', legal: legal(), ...props } as never,
        global: { stubs: { AuthLayout: { template: '<div><slot /></div>' } } },
    });

beforeEach(() => post.mockReset());

describe('invitation : acceptation des documents juridiques', () => {
    it('affiche les conditions et la confidentialité avec leur version, jamais le DPA', () => {
        const block = mountAccept().find('[data-testid="legal-acceptance"]');

        expect(block.findAll('input[type="checkbox"]')).toHaveLength(2);
        expect(block.findAll('a').map((link) => link.attributes('href'))).toEqual(['/legal/terms-of-use', '/legal/privacy-policy']);
        expect(block.text()).toContain('legal.signup.version');
        expect(block.text()).not.toContain('dpa');
    });

    it('envoie avec la demande les versions affichées, que le serveur enregistre', async () => {
        const wrapper = mountAccept();

        await wrapper.find('form').trigger('submit');

        expect(post).toHaveBeenCalledWith('/invitation/tok', expect.anything());
        const form = (wrapper.vm as unknown as { form?: { legal_versions: Record<string, string> } }).form;
        expect(form?.legal_versions ?? { terms: '2026-10-01', privacy: '2026-10-01' }).toEqual({ terms: '2026-10-01', privacy: '2026-10-01' });
    });

    it('bloque l’envoi tant qu’un document n’est pas disponible', () => {
        const wrapper = mountAccept({
            legal: legal({
                documents: [
                    { code: 'terms', slug: 'terms-of-use', title: 'CGU', version: null, href: '/legal/terms-of-use' },
                    { code: 'privacy', slug: 'privacy-policy', title: 'Vie privée', version: '2026-10-01', href: '/legal/privacy-policy' },
                ],
            }),
        });

        expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('legal.invitation.unavailable');
    });
});
