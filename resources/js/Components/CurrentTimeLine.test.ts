import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CurrentTimeLine from './CurrentTimeLine.vue';

/**
 * Ligne « maintenant » : seulement sur le jour d'aujourd'hui (fuseau du site), dans la plage affichée, et elle avance toute seule.
 */
const range = { from: 420, to: 1200 };
const line = (wrapper: ReturnType<typeof mount>) => wrapper.find('[data-testid="current-time-line"]');

describe('CurrentTimeLine', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-09T08:07:30Z')); // 10:07 à Bruxelles
    });

    afterEach(() => vi.useRealTimers());

    it("se place à l'heure exacte sur la colonne d'aujourd'hui", () => {
        const wrapper = mount(CurrentTimeLine, { props: { date: '2026-10-09', timezone: 'Europe/Brussels', range } });

        expect(line(wrapper).exists()).toBe(true);
        expect(line(wrapper).attributes('style')).toContain('top: 187px');
        expect(line(wrapper).classes()).toContain('pointer-events-none');
        wrapper.unmount();
    });

    it("n'apparaît pas sur un autre jour", () => {
        const past = mount(CurrentTimeLine, { props: { date: '2026-10-08', timezone: 'Europe/Brussels', range } });
        const future = mount(CurrentTimeLine, { props: { date: '2026-10-10', timezone: 'Europe/Brussels', range } });

        expect(line(past).exists()).toBe(false);
        expect(line(future).exists()).toBe(false);
        past.unmount();
        future.unmount();
    });

    it("utilise le fuseau du site, pas celui de l'ordinateur", () => {
        vi.setSystemTime(new Date('2026-10-09T23:30:00Z')); // déjà le 10 à Bruxelles
        const wrapper = mount(CurrentTimeLine, { props: { date: '2026-10-09', timezone: 'Europe/Brussels', range: { from: 0, to: 1440 } } });
        const next = mount(CurrentTimeLine, { props: { date: '2026-10-10', timezone: 'Europe/Brussels', range: { from: 0, to: 1440 } } });

        expect(line(wrapper).exists()).toBe(false);
        expect(line(next).exists()).toBe(true);
        wrapper.unmount();
        next.unmount();
    });

    it("disparaît quand l'heure est hors de la plage affichée", () => {
        const wrapper = mount(CurrentTimeLine, { props: { date: '2026-10-09', timezone: 'Europe/Brussels', range: { from: 360, to: 540 } } });

        expect(line(wrapper).exists()).toBe(false);
        wrapper.unmount();
    });

    it("avance avec l'horloge, sans action de l'utilisateur", async () => {
        const wrapper = mount(CurrentTimeLine, { props: { date: '2026-10-09', timezone: 'Europe/Brussels', range } });
        expect(line(wrapper).attributes('style')).toContain('top: 187px');

        vi.setSystemTime(new Date('2026-10-09T08:12:10Z')); // 10:12
        await vi.advanceTimersByTimeAsync(30_000);

        expect(line(wrapper).attributes('style')).toContain('top: 192px');
        wrapper.unmount();
    });

    it("arrête sa minuterie quand plus aucune ligne n'est affichée", async () => {
        const wrapper = mount(CurrentTimeLine, { props: { date: '2026-10-09', timezone: 'Europe/Brussels', range } });
        const before = vi.getTimerCount();
        wrapper.unmount();

        expect(vi.getTimerCount()).toBeLessThan(before);
    });
});
