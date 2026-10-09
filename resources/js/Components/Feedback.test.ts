import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FeedbackPrompt from './FeedbackPrompt.vue';
import ReviewLinks from './ReviewLinks.vue';
import StarRating from './StarRating.vue';

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
    router: { post: (...args: unknown[]) => post(...args) },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

beforeEach(() => post.mockReset());

describe('StarRating', () => {
    const rating = (value: number | null = null, error?: string) =>
        mount(StarRating, { props: { label: 'Note', modelValue: value, error, 'onUpdate:modelValue': () => undefined } as never, attachTo: document.body });

    it('propose cinq étoiles dans un groupe radio nommé, avec un nom lisible par étoile', () => {
        const wrapper = rating();
        const stars = wrapper.findAll('[role="radio"]');

        expect(wrapper.find('[role="radiogroup"]').attributes('aria-labelledby')).toBeTruthy();
        expect(stars).toHaveLength(5);
        expect(stars.map((star) => star.attributes('aria-label'))).toEqual([
            'feedback.star',
            'feedback.star',
            'feedback.star',
            'feedback.star',
            'feedback.star',
        ]);
        expect(stars.every((star) => star.attributes('aria-checked') === 'false')).toBe(true);
    });

    it('ne prend qu’une tabulation : la première étoile sans choix, l’étoile choisie sinon', () => {
        expect(
            rating()
                .findAll('[role="radio"]')
                .map((star) => star.attributes('tabindex')),
        ).toEqual(['0', '-1', '-1', '-1', '-1']);
        expect(
            rating(4)
                .findAll('[role="radio"]')
                .map((star) => star.attributes('tabindex')),
        ).toEqual(['-1', '-1', '-1', '0', '-1']);
    });

    it('émet la note au clic, y compris la plus basse', async () => {
        const wrapper = rating();

        await wrapper.findAll('[role="radio"]')[0]!.trigger('click');
        await wrapper.findAll('[role="radio"]')[4]!.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([[1], [5]]);
    });

    it('se commande au clavier : flèches, début et fin, sans dépasser 1 à 5', async () => {
        const wrapper = rating(3);
        const star = (n: number) => wrapper.findAll('[role="radio"]')[n - 1]!;

        await star(3).trigger('keydown', { key: 'ArrowRight' });
        await star(3).trigger('keydown', { key: 'ArrowLeft' });
        await star(5).trigger('keydown', { key: 'ArrowRight' });
        await star(1).trigger('keydown', { key: 'ArrowUp' });
        await star(3).trigger('keydown', { key: 'Home' });
        await star(3).trigger('keydown', { key: 'End' });
        await star(3).trigger('keydown', { key: 'Tab' });

        expect(wrapper.emitted('update:modelValue')).toEqual([[4], [2], [5], [1], [1], [5]]);
    });

    it('marque l’étoile choisie et annonce l’erreur', () => {
        const wrapper = rating(2, 'Choisissez une note.');

        expect(wrapper.findAll('[role="radio"]').map((star) => star.attributes('aria-checked'))).toEqual(['false', 'true', 'false', 'false', 'false']);
        expect(wrapper.find('[role="alert"]').text()).toBe('Choisissez une note.');
        expect(wrapper.find('[role="radiogroup"]').attributes('aria-invalid')).toBe('true');
    });
});

describe('ReviewLinks', () => {
    const links = (props: Record<string, unknown> = {}) => mount(ReviewLinks, { props: { links: ['google', 'trustpilot'], ...props } as never });

    it('montre les deux options dans le même ordre, ouvertes dans un nouvel onglet sans fuite de contexte', () => {
        const anchors = links().findAll('a');

        expect(anchors.map((a) => a.attributes('data-platform'))).toEqual(['google', 'trustpilot']);
        expect(anchors.map((a) => a.attributes('target'))).toEqual(['_blank', '_blank']);
        expect(anchors.every((a) => a.attributes('rel') === 'noopener noreferrer')).toBe(true);
        expect(anchors.map((a) => a.attributes('href'))).toEqual(['/aide/avis/lien/google', '/aide/avis/lien/trustpilot']);
    });

    it('est identique pour tous : l’avis donné ne change que le rattachement du clic, jamais les options', () => {
        const withFeedback = links({ feedbackId: 'abc' });
        const without = links();

        expect(withFeedback.findAll('a').map((a) => a.attributes('data-platform'))).toEqual(without.findAll('a').map((a) => a.attributes('data-platform')));
        expect(withFeedback.findAll('a')[0]!.attributes('href')).toBe('/aide/avis/lien/google?feedback=abc');
        expect(withFeedback.text()).toBe(without.text());
    });

    it('prévient qu’on quitte Nova, que l’avis public est séparé, identique pour tous et compté sans son contenu', () => {
        const text = links().text();

        for (const key of ['feedback.public.leaving', 'feedback.public.separate', 'feedback.public.neutral', 'feedback.public.tracking']) {
            expect(text).toContain(key);
        }
    });

    it('n’affiche rien sans lien configuré', () => {
        expect(links({ links: [] }).find('[data-testid="review-links"]').exists()).toBe(false);
    });

    it('ne montre que la plateforme configurée quand une seule l’est', () => {
        expect(
            links({ links: ['trustpilot'] })
                .findAll('a')
                .map((a) => a.attributes('data-platform')),
        ).toEqual(['trustpilot']);
    });
});

describe('FeedbackPrompt', () => {
    const prompt = () =>
        mount(FeedbackPrompt, { props: { prompt: { key: 'ticket:abc', context: 'ticket' }, reference: 'NWS-1248' } as never, attachTo: document.body });

    it('propose de donner son avis sur ce ticket, ou de ne pas le faire maintenant', () => {
        const wrapper = prompt();

        expect(wrapper.find('a').attributes('href')).toBe('/aide/avis?ticket=NWS-1248');
        expect(wrapper.text()).toContain('feedback.prompt.ticket');
        expect(wrapper.text()).toContain('feedback.prompt.later');
    });

    it('« Pas maintenant » se referme aussitôt et mémorise le refus de ce moment', async () => {
        const wrapper = prompt();

        await wrapper.find('button').trigger('click');

        expect(wrapper.find('[data-testid="feedback-prompt"]').exists()).toBe(false);
        expect(post).toHaveBeenCalledWith('/aide/avis/plus-tard', { key: 'ticket:abc' }, expect.objectContaining({ preserveScroll: true }));
    });
});
