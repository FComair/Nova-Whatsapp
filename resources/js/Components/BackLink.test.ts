import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { resetNavigationTrail, trackNavigation } from '../composables/backNav';
import BackLink from './BackLink.vue';

const shared = reactive({ locale: 'fr', translations: { back_nav: { aria: 'Navigation de retour', customers: 'Retour aux clients' } } });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared, url: '/clients/42' }),
    Link: { props: ['href'], template: '<a :href="href" v-bind="$attrs"><slot /></a>' },
}));

const mountLink = () => mount(BackLink, { props: { href: '/clients', label: 'back_nav.customers' } });

beforeEach(() => {
    vi.restoreAllMocks();
    resetNavigationTrail();
});

describe('bouton Retour', () => {
    it('est un lien nommé, en haut de l’écran, vers le parent', () => {
        const wrapper = mountLink();
        const link = wrapper.find('[data-testid="back-link"]');

        expect(wrapper.find('nav').attributes('aria-label')).toBe('Navigation de retour');
        expect(link.attributes('href')).toBe('/clients');
        expect(link.text()).toBe('Retour aux clients');
        expect(link.classes()).toContain('min-h-11');
    });

    it('revient en arrière dans l’historique quand on vient de la liste (filtres et page conservés)', async () => {
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => undefined);
        trackNavigation('/clients?q=ana&page=2');
        trackNavigation('/clients/42');

        await mountLink().find('a').trigger('click');

        expect(back).toHaveBeenCalledOnce();
    });

    it('suit le lien quand on arrive d’ailleurs, ou avec un clic modifié (nouvel onglet)', async () => {
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => undefined);
        trackNavigation('/tableau-de-bord');
        trackNavigation('/clients/42');

        await mountLink().find('a').trigger('click');
        expect(back).not.toHaveBeenCalled();

        resetNavigationTrail();
        trackNavigation('/clients');
        trackNavigation('/clients/42');
        await mountLink().find('a').trigger('click', { ctrlKey: true });
        expect(back).not.toHaveBeenCalled();
    });
});
