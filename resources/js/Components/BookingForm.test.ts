import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick, reactive } from 'vue';
import BookingForm from './BookingForm.vue';

/**
 * Liste des praticiens du formulaire « nouveau rendez-vous » : elle ne doit jamais s'ouvrir « à blanc » sans expliquer pourquoi.
 * (Les praticiens arrivent avec la page : il n'y a pas d'appel réseau, donc pas d'état de chargement ni d'erreur d'API.)
 */
const post = vi.fn();
const shared = reactive({
    locale: 'fr',
    translations: {} as Record<string, unknown>,
    auth: { user: { id: 'u1', email: 'a@b.c', locale: 'fr' }, organization: { id: 'o1', name: 'Salon', status: 'active', role: 'owner' as string } },
    flash: { status: null, warning: null, first_login: false, suggestions: [] },
    errors: {} as Record<string, string>,
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: shared }),
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
    useForm: (data: Record<string, unknown>) => {
        const state: Record<string, unknown> = reactive({
            ...data,
            errors: {} as Record<string, string>,
            processing: false,
            post,
            reset: vi.fn(),
            clearErrors: vi.fn(),
            setError(key: string, message: string) {
                (state.errors as Record<string, string>)[key] = message;
            },
        });

        return state;
    },
}));

const anna = { id: 'p1', name: 'Anna', color: '#111111', schedule: {} };
const ben = { id: 'p2', name: 'Ben', color: '#222222', schedule: {} };
const services = [
    { id: 's1', name: 'Coupe', duration_minutes: 30, practitioner_ids: ['p1', 'p2'] },
    { id: 's2', name: 'Couleur', duration_minutes: 60, practitioner_ids: ['p2'] },
    { id: 's3', name: 'Brushing', duration_minutes: 30, practitioner_ids: [] },
];

function form(practitioners: unknown[]) {
    return mount(BookingForm, {
        props: { locationId: 'l1', date: '2026-10-08', practitioners, services, seesNotes: false, timezone: 'Europe/Brussels' } as never,
    });
}

const practitionerBox = (wrapper: ReturnType<typeof form>) => wrapper.find('[role="combobox"][aria-haspopup="listbox"]');
/** Ouvre la liste des praticiens et renvoie les noms proposés (la liste ne s'ouvre jamais quand elle est vide). */
const optionLabels = async (wrapper: ReturnType<typeof form>): Promise<string[]> => {
    await practitionerBox(wrapper).trigger('click');

    return wrapper
        .find('[role="listbox"]')
        .findAll('[role="option"]')
        .map((option) => option.find('span.truncate').text());
};

describe('liste des praticiens du nouveau rendez-vous', () => {
    beforeEach(() => {
        shared.auth.organization.role = 'owner';
    });

    it("propose tous les praticiens tant qu'aucun service n'est choisi", async () => {
        const wrapper = form([anna, ben]);

        expect(practitionerBox(wrapper).text()).toContain('planning.form.choose');
        expect(await optionLabels(wrapper)).toEqual(['Anna', 'Ben']);
        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it('ne propose que les praticiens qui réalisent le service choisi', async () => {
        const wrapper = form([anna, ben]);

        await wrapper.findAll('select')[0]!.setValue('s2');

        expect(await optionLabels(wrapper)).toEqual(['Ben']);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it("explique quand personne ne propose le service choisi, au lieu d'une liste vide muette", async () => {
        const wrapper = form([anna, ben]);

        await wrapper.findAll('select')[0]!.setValue('s3');

        expect(practitionerBox(wrapper).text()).toContain('planning.form.practitioner_empty');
        expect(await optionLabels(wrapper)).toEqual([]);
        const notice = wrapper.find('[data-testid="nobody-offers"]');
        expect(notice.exists()).toBe(true);
        expect(notice.find('a').attributes('href')).toBe('/configuration/assistant/praticiens');
    });

    it("explique quand il n'y a aucun praticien actif", async () => {
        const wrapper = form([]);

        expect(practitionerBox(wrapper).text()).toContain('planning.form.practitioner_empty');
        expect(await optionLabels(wrapper)).toEqual([]);
        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="nobody-offers"]').exists()).toBe(false);
    });

    it("ne propose le lien de configuration qu'au propriétaire", () => {
        shared.auth.organization.role = 'receptionist';
        const wrapper = form([]);

        expect(wrapper.find('[data-testid="no-practitioners"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="no-practitioners"] a').exists()).toBe(false);
    });

    it("choisit d'office l'unique praticien", () => {
        const wrapper = form([anna]);

        expect(practitionerBox(wrapper).text()).toContain('Anna');
    });

    it('montre la photo du praticien dans la liste et dans le champ une fois choisi', async () => {
        const wrapper = form([{ ...anna, photo_version: 1760000000 }, ben]);
        await practitionerBox(wrapper).trigger('click');

        const rows = wrapper.findAll('[role="option"]');
        expect(rows[0]!.find('img').attributes('src')).toBe('/praticiens/p1/photo/64?v=1760000000');
        expect(rows[1]!.find('[data-testid="practitioner-initials"]').exists()).toBe(true);

        await rows[0]!.trigger('mousedown');
        expect(practitionerBox(wrapper).find('img').attributes('src')).toBe('/praticiens/p1/photo/64?v=1760000000');
    });
});

// Pas de rendez-vous dans le passé : lundi 12 octobre 2026, 10:00 UTC = 12:00 à Bruxelles (heure d'été).
describe('heure déjà passée', () => {
    const opened: { unmount: () => void }[] = [];
    const open = () => {
        const wrapper = mount(BookingForm, {
            props: { locationId: 'l1', date: '2026-10-12', practitioners: [anna], services, seesNotes: false, timezone: 'Europe/Brussels' } as never,
        });

        opened.push(wrapper);

        return wrapper;
    };
    const hours = (wrapper: ReturnType<typeof open>) => wrapper.findAll('fieldset select')[0]!;
    const minutes = (wrapper: ReturnType<typeof open>) => wrapper.findAll('fieldset select')[1]!;
    const enabled = (select: ReturnType<typeof hours>) =>
        select
            .findAll('option')
            .filter((option) => option.element.value !== '' && !option.element.disabled)
            .map((option) => option.element.value);
    const pickTime = async (wrapper: ReturnType<typeof open>, hour: string, minute: string) => {
        await hours(wrapper).setValue(hour);
        await minutes(wrapper).setValue(minute);
    };
    const fill = async (wrapper: ReturnType<typeof open>) => {
        await wrapper.findAll('select')[0]!.setValue('s1');
        await wrapper.find('input[type="tel"]').setValue('+32470123456');
    };

    beforeEach(() => {
        post.mockReset();
        vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
        vi.setSystemTime(new Date('2026-10-12T10:00:30Z'));
    });

    afterEach(() => {
        // Démonter arrête la minuterie partagée de l'horloge : chaque test repart d'une horloge neuve.
        opened.splice(0).forEach((wrapper) => wrapper.unmount());
        vi.useRealTimers();
    });

    it("grise les heures passées aujourd'hui, selon l'heure du site", () => {
        const wrapper = open();

        expect(enabled(hours(wrapper))[0]).toBe('12');
        expect(enabled(hours(wrapper))).not.toContain('11');
        expect(enabled(hours(wrapper)).at(-1)).toBe('23');
    });

    it('grise les minutes passées dans l’heure en cours seulement', async () => {
        const wrapper = open();
        await hours(wrapper).setValue('12');

        expect(enabled(minutes(wrapper))[0]).toBe('00');

        vi.setSystemTime(new Date('2026-10-12T10:20:00Z'));
        // Le retour sur l'onglet relit l'horloge sans attendre le prochain tick.
        document.dispatchEvent(new Event('visibilitychange'));
        await nextTick();

        expect(enabled(minutes(wrapper))[0]).toBe('20');
        await hours(wrapper).setValue('13');
        expect(enabled(minutes(wrapper))[0]).toBe('00');
    });

    it('propose toutes les heures un jour à venir, aucune un jour passé', async () => {
        const wrapper = open();
        await wrapper.find('input[type="date"]').setValue('2026-10-13');
        expect(enabled(hours(wrapper))).toHaveLength(24);

        await wrapper.find('input[type="date"]').setValue('2026-10-11');
        expect(enabled(hours(wrapper))).toHaveLength(0);
        expect(wrapper.text()).toContain('planning.errors.past_time');
    });

    it('affiche l’erreur en ligne et n’envoie rien pour une heure passée', async () => {
        const wrapper = open();
        await fill(wrapper);
        await pickTime(wrapper, '11', '30');

        expect(wrapper.find('[role="alert"]').text()).toBe('planning.errors.past_time');

        await wrapper.find('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
    });

    it('envoie une heure à venir, et l’erreur disparaît quand on la corrige', async () => {
        const wrapper = open();
        await fill(wrapper);
        await pickTime(wrapper, '11', '30');
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);

        await pickTime(wrapper, '12', '30');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        await wrapper.find('form').trigger('submit');
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('invalide une heure qui passe pendant que le formulaire reste ouvert', async () => {
        const wrapper = open();
        await fill(wrapper);
        await pickTime(wrapper, '12', '30');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        // 12:31 à Bruxelles : l'horloge n'a pas encore tourné, mais l'envoi relit l'heure.
        vi.setSystemTime(new Date('2026-10-12T10:31:00Z'));
        await wrapper.find('form').trigger('submit');
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.find('[role="alert"]').text()).toBe('planning.errors.past_time');
    });

    it('se met à jour seul avec l’horloge du site', async () => {
        const wrapper = open();
        await pickTime(wrapper, '12', '30');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);

        vi.setSystemTime(new Date('2026-10-12T10:45:00Z'));
        // Le retour sur l'onglet relit l'horloge sans attendre le prochain tick.
        document.dispatchEvent(new Event('visibilitychange'));
        await nextTick();

        expect(wrapper.find('[role="alert"]').text()).toBe('planning.errors.past_time');
    });

    it('suit le jour du site, pas celui du navigateur', async () => {
        // 23:30 UTC le 12 = 01:30 le 13 à Bruxelles : le 12 est fini pour le site, le 13 à 02:00 est à venir.
        vi.setSystemTime(new Date('2026-10-12T23:30:00Z'));
        const wrapper = open();
        expect(wrapper.text()).toContain('planning.errors.past_time');

        await wrapper.find('input[type="date"]').setValue('2026-10-13');
        await pickTime(wrapper, '02', '00');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(enabled(hours(wrapper))[0]).toBe('01');
    });
});
