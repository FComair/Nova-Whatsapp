import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import LegalNotice from '../../Components/LegalNotice.vue';
import Settings from '../Account/Legal.vue';
import Show from './Show.vue';

const post = vi.fn();
const shared = reactive({
    locale: 'fr',
    locales: ['fr', 'nl', 'en'],
    translations: {},
    auth: { user: { id: 'u1' }, organization: { id: 'o1', name: 'Salon', role: 'owner', logo_version: null } },
    flash: { status: null },
    errors: {} as Record<string, string>,
    legal: { pending: [] as { code: string; slug: string; title: string; version: string; blocking: boolean }[] },
    settings: null,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared, url: '/reglages/profil' }),
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...args: unknown[]) => post(...args), get: vi.fn(), visit: vi.fn(), on: vi.fn() },
}));

const stubs = { SettingsLayout: { template: '<div><slot /></div>' }, LegalLayout: { template: '<div><slot /></div>' } };

beforeEach(() => {
    post.mockReset();
    shared.legal = { pending: [] };
    window.sessionStorage.clear();
});

describe('page d’un document juridique', () => {
    const document = (override: Record<string, unknown> = {}) => ({
        code: 'terms',
        slug: 'terms-of-use',
        title: 'Conditions d’utilisation',
        version: '2026-10-01',
        effective_on: '2026-10-01',
        html: '<h2 id="section-1">1. Objet</h2><p>Texte</p>',
        toc: [
            { id: 'section-1', title: '1. Objet' },
            { id: 'section-2', title: '2. Compte' },
        ],
        language: 'fr',
        fallback: false,
        is_current: true,
        current_url: '/legal/terms-of-use',
        changes: null,
        ...override,
    });
    const props = (override: Record<string, unknown> = {}, doc: Record<string, unknown> = {}) => ({
        document: document(doc),
        versions: [
            { version: '2026-12-01', effective_on: '2026-12-01', href: '/legal/terms-of-use/versions/2026-12-01', current: true, viewing: false },
            { version: '2026-10-01', effective_on: '2026-10-01', href: '/legal/terms-of-use/versions/2026-10-01', current: false, viewing: true },
        ],
        documents: [
            { code: 'terms', slug: 'terms-of-use', title: 'Conditions', active: true },
            { code: 'privacy', slug: 'privacy-policy', title: 'Confidentialité', active: false },
            { code: 'dpa', slug: 'dpa', title: 'DPA', active: false },
        ],
        ...override,
    });
    const show = (override: Record<string, unknown> = {}, doc: Record<string, unknown> = {}) =>
        mount(Show, { props: props(override, doc) as never, global: { stubs } });

    it('montre titre, version, date d’effet, le texte et un sommaire cliquable', () => {
        const wrapper = show();

        expect(wrapper.find('h1').text()).toBe('Conditions d’utilisation');
        expect(wrapper.find('[data-testid="legal-version"]').text()).toContain('legal.page.version');
        expect(wrapper.find('[data-testid="legal-effective"]').text()).toContain('legal.page.effective');
        expect(wrapper.find('.legal-body').html()).toContain('1. Objet');
        expect(wrapper.findAll('nav a[href^="#section-"]').map((link) => link.attributes('href'))).toEqual(['#section-1', '#section-2']);
    });

    it('relie les trois documents et marque celui qui est ouvert', () => {
        const links = show().findAll('nav a[href^="/legal/"]');

        expect(links.map((link) => link.attributes('href'))).toEqual(['/legal/terms-of-use', '/legal/privacy-policy', '/legal/dpa']);
        expect(links[0]!.attributes('aria-current')).toBe('page');
    });

    it('propose l’impression et le téléchargement de la version en vigueur', () => {
        const wrapper = show();

        expect(wrapper.findAll('button').some((button) => button.text() === 'legal.page.print')).toBe(true);
        expect(wrapper.find('a[download]').attributes('href')).toBe('/legal/terms-of-use/download');
    });

    it('signale une ancienne version et renvoie vers celle en vigueur, sans proposer le téléchargement', () => {
        const wrapper = show({}, { is_current: false });

        expect(wrapper.find('[data-testid="legal-old"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="legal-old"] a').attributes('href')).toBe('/legal/terms-of-use');
        expect(wrapper.find('a[download]').exists()).toBe(false);
    });

    it('liste les versions, la version en vigueur et celle qu’on lit', () => {
        const text = show().find('section[aria-labelledby="legal-history"]').text();

        expect(text).toContain('2026-12-01');
        expect(text).toContain('legal.page.current');
        expect(text).toContain('legal.page.viewing');
    });

    it('affiche un document déposé en PDF dans un cadre, avec un lien de secours et le téléchargement du PDF', () => {
        const wrapper = show({}, { html: '', toc: [], file: { url: '/legal/terms-of-use/file', name: 'cgu.pdf', size: 1024 } });

        expect(wrapper.find('[data-testid="legal-pdf"] iframe').attributes('src')).toBe('/legal/terms-of-use/file');
        expect(wrapper.find('[data-testid="legal-pdf"] a').attributes('href')).toBe('/legal/terms-of-use/file');
        expect(wrapper.find('a[download]').text()).toContain('legal.page.download_pdf');
    });

    it('n’affiche pas de cadre PDF pour un texte', () => {
        expect(show().find('[data-testid="legal-pdf"]').exists()).toBe(false);
    });

    it('dit quand la langue demandée n’existe pas encore', () => {
        expect(show({}, { fallback: true, language: 'fr' }).find('[data-testid="legal-fallback"]').exists()).toBe(true);
        expect(show().find('[data-testid="legal-fallback"]').exists()).toBe(false);
    });
});

describe('réglages › juridique', () => {
    const row = (override: Record<string, unknown> = {}) => ({
        code: 'terms',
        slug: 'terms-of-use',
        title: 'Conditions d’utilisation',
        version: '2026-10-01',
        effective_on: '2026-10-01',
        state: 'accepted',
        blocking: false,
        accepted: { version: '2026-10-01', at: '2026-10-02T10:00:00Z', context: 'signup' },
        history: [{ version: '2026-10-01', at: '2026-10-02T10:00:00Z', context: 'signup' }],
        changes: null,
        ...override,
    });
    const settings = (documents: Record<string, unknown>[]) => mount(Settings, { props: { documents } as never, global: { stubs } });

    it('montre la version acceptée, sa date et son contexte, des liens de lecture, d’impression et de téléchargement', () => {
        const wrapper = settings([row()]);
        const card = wrapper.find('[data-testid="legal-terms-of-use"]');

        expect(card.find('[data-testid="accepted-line"]').text()).toContain('legal.settings.accepted');
        expect(card.find('a[href="/legal/terms-of-use"]').attributes('target')).toBe('_blank');
        expect(card.find('a[href="/legal/terms-of-use?print=1"]').exists()).toBe(true);
        expect(card.find('a[href="/legal/terms-of-use/download"]').exists()).toBe(true);
        expect(card.text()).toContain('legal.settings.state.accepted');
    });

    it('ne propose aucune acceptation quand tout est à jour, ni pour une simple nouvelle version', () => {
        const wrapper = settings([row(), row({ code: 'privacy', slug: 'privacy-policy', state: 'update', version: '2026-12-01' })]);

        expect(wrapper.findAll('button').filter((button) => button.text().includes('legal.settings.accept_one'))).toHaveLength(0);
        expect(wrapper.find('[data-testid="legal-blocked"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('legal.settings.update_note');
    });

    it('propose d’accepter un document manquant, ou une version significative, avec un avis clair', async () => {
        const wrapper = settings([
            row({ state: 'reacceptance', blocking: true, version: '2026-12-01' }),
            row({ code: 'privacy', slug: 'privacy-policy', state: 'missing', accepted: null, history: [] }),
        ]);

        expect(wrapper.find('[data-testid="legal-blocked"]').text()).toContain('legal.settings.blocked_title');
        const buttons = wrapper.findAll('button').filter((button) => button.text().includes('legal.settings.accept_one'));
        expect(buttons).toHaveLength(2);

        await buttons[0]!.trigger('click');
        expect(post).toHaveBeenCalledWith('/reglages/juridique/accepter', { documents: ['terms-of-use'] }, expect.anything());
    });

    it('dit qu’aucune acceptation n’est enregistrée, et que le DPA concerne le propriétaire', () => {
        const wrapper = settings([
            row({ state: 'missing', accepted: null, history: [] }),
            row({ code: 'dpa', slug: 'dpa', state: 'not_applicable', accepted: null, history: [] }),
        ]);

        expect(wrapper.text()).toContain('legal.settings.not_recorded');
        expect(wrapper.find('[data-testid="legal-dpa"]').text()).toContain('legal.settings.dpa_owner');
        expect(wrapper.find('[data-testid="legal-dpa"] button').exists()).toBe(false);
    });

    it('garde l’historique de chaque acceptation', () => {
        const wrapper = settings([
            row({
                history: [
                    { version: '2026-12-01', at: '2026-12-02T10:00:00Z', context: 'reacceptance' },
                    { version: '2026-10-01', at: '2026-10-02T10:00:00Z', context: 'signup' },
                ],
            }),
        ]);

        expect(wrapper.findAll('details li')).toHaveLength(2);
    });
});

describe('avis après connexion', () => {
    it('reste invisible quand rien ne manque', () => {
        expect(mount(LegalNotice).find('[data-testid="legal-notice"]').exists()).toBe(false);
    });

    it('signale une acceptation manquante sans rien bloquer, et se masque pour la session', async () => {
        shared.legal = { pending: [{ code: 'terms', slug: 'terms-of-use', title: 'CGU', version: '2026-10-01', blocking: false }] };
        const wrapper = mount(LegalNotice);

        expect(wrapper.find('[data-testid="legal-notice"] a').attributes('href')).toBe('/reglages/juridique');

        await wrapper.find('button').trigger('click');
        expect(wrapper.find('[data-testid="legal-notice"]').exists()).toBe(false);
        expect(mount(LegalNotice).find('[data-testid="legal-notice"]').exists()).toBe(false);
    });

    it('nomme exactement les documents qui manquent', () => {
        shared.legal = {
            pending: [
                { code: 'terms', slug: 'terms-of-use', title: 'Conditions d’utilisation', version: '2026-10-01', blocking: false },
                { code: 'privacy', slug: 'privacy-policy', title: 'Politique de confidentialité', version: '2026-10-01', blocking: false },
            ],
        };

        shared.translations = { legal: { notice: { missing_named: 'Acceptation non enregistrée : :documents.' } } } as never;
        const text = mount(LegalNotice).find('[data-testid="legal-notice-text"]').text();
        shared.translations = {};

        expect(text).toBe('Acceptation non enregistrée : Conditions d’utilisation, Politique de confidentialité.');
    });

    it('n’affiche pas d’avis pour une version bloquante : la page Juridique prend le relais', () => {
        shared.legal = { pending: [{ code: 'terms', slug: 'terms-of-use', title: 'CGU', version: '2026-12-01', blocking: true }] };

        expect(mount(LegalNotice).find('[data-testid="legal-notice"]').exists()).toBe(false);
    });
});
