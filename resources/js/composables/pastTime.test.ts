import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, reactive } from 'vue';
import { nowInZone } from './clock';
import { isPastStart, minimumMinutes, usePastTimeGuard, WHOLE_DAY } from './pastTime';
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }) }));

const brussels = (iso: string) => nowInZone('Europe/Brussels', new Date(iso));

describe('pastTime', () => {
    it('ne limite rien un jour à venir, tout un jour passé, et l’heure actuelle aujourd’hui', () => {
        const now = brussels('2026-10-12T10:00:30Z'); // 12:00 à Bruxelles

        expect(minimumMinutes('2026-10-13', now)).toBeNull();
        expect(minimumMinutes('2026-10-11', now)).toBe(WHOLE_DAY);
        expect(minimumMinutes('2026-10-12', now)).toBe(12 * 60);
        expect(minimumMinutes('', now)).toBeNull();
    });

    it('juge le début à la minute près', () => {
        const now = brussels('2026-10-12T10:00:30Z');

        expect(isPastStart('2026-10-12', '11:59', now)).toBe(true);
        expect(isPastStart('2026-10-12', '12:00', now)).toBe(false);
        expect(isPastStart('2026-10-12', '12:01', now)).toBe(false);
        expect(isPastStart('2026-10-13', '00:00', now)).toBe(false);
        expect(isPastStart('2026-10-11', '23:59', now)).toBe(true);
    });

    it('reste faux tant que la date ou l’heure n’est pas complète', () => {
        const now = brussels('2026-10-12T10:00:30Z');

        expect(isPastStart('2026-10-12', '', now)).toBe(false);
        expect(isPastStart('', '08:00', now)).toBe(false);
    });

    it('suit le fuseau du site au changement de jour', () => {
        const now = nowInZone('Europe/Brussels', new Date('2026-10-12T23:30:00Z')); // 01:30 le 13

        expect(now.date).toBe('2026-10-13');
        expect(isPastStart('2026-10-13', '01:00', now)).toBe(true);
        expect(isPastStart('2026-10-13', '02:00', now)).toBe(false);
        expect(isPastStart('2026-10-12', '23:45', now)).toBe(true);
    });

    it('suit un autre fuseau que celui du navigateur', () => {
        const now = nowInZone('Pacific/Auckland', new Date('2026-10-12T10:00:00Z')); // 23:00 le 12 (UTC+13)

        expect(now).toEqual({ date: '2026-10-12', minutes: 23 * 60 });
        expect(isPastStart('2026-10-12', '22:00', now)).toBe(true);
        expect(isPastStart('2026-10-12', '23:30', now)).toBe(false);
    });
});

describe('usePastTimeGuard', () => {
    const guard = (date: string, time: string, original: { date: string; time: string } | null) => {
        let result!: ReturnType<typeof usePastTimeGuard>;
        const state = reactive({ date, time });
        const wrapper = mount(
            defineComponent({
                setup() {
                    result = usePastTimeGuard(
                        'Europe/Brussels',
                        () => state.date,
                        () => state.time,
                        () => original,
                    );

                    return () => null;
                },
            }),
        );

        return { result, state, wrapper };
    };

    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
        vi.setSystemTime(new Date('2026-10-12T10:00:30Z')); // 12:00 à Bruxelles
    });

    afterEach(() => vi.useRealTimers());

    it('refuse un horaire passé quand on crée', () => {
        const { result, wrapper } = guard('2026-10-12', '09:00', null);

        expect(result.timeError.value).toBe('planning.errors.past_time');
        expect(result.validNow()).toBe(false);
        wrapper.unmount();
    });

    it('laisse en paix l’horaire passé d’un rendez-vous modifié tant qu’on n’y touche pas', () => {
        const { result, state, wrapper } = guard('2026-10-12', '09:00', { date: '2026-10-12', time: '09:00' });

        expect(result.timeError.value).toBeUndefined();
        expect(result.validNow()).toBe(true);

        state.time = '09:15';
        expect(result.timeError.value).toBe('planning.errors.past_time');
        expect(result.validNow()).toBe(false);

        state.date = '2026-10-11';
        state.time = '09:00';
        expect(result.dateError.value).toBe('planning.errors.past_time');
        wrapper.unmount();
    });

    it('accepte à nouveau l’horaire d’origine après l’avoir changé puis remis', () => {
        const { result, state, wrapper } = guard('2026-10-12', '09:00', { date: '2026-10-12', time: '09:00' });

        state.time = '09:15';
        state.time = '09:00';
        expect(result.validNow()).toBe(true);
        wrapper.unmount();
    });
});
