import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TeamMemberDialog from './TeamMemberDialog.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'fr', translations: {}, auth: {}, flash: {}, errors: {} } }),
}));

// jsdom ne fournit pas showModal/close sur <dialog>.
HTMLDialogElement.prototype.showModal = function (this: HTMLDialogElement) {
    this.setAttribute('open', '');
};
HTMLDialogElement.prototype.close = function (this: HTMLDialogElement) {
    this.removeAttribute('open');
};

const catalog = {
    groups: { dashboard: ['dashboard.view'], appointments: ['calendar.view', 'appointments.create'], settings: ['settings.billing', 'settings.team'] },
    protected: ['settings.billing', 'settings.team'],
};
const presets = {
    practitioner: { role: 'practitioner', capabilities: ['dashboard.view', 'calendar.view', 'appointments.create'] },
    assistant: { role: 'receptionist', capabilities: ['dashboard.view', 'calendar.view'] },
};

const dialog = (props: Record<string, unknown> = {}) =>
    mount(TeamMemberDialog, {
        props: {
            open: true,
            mode: 'add',
            member: null,
            catalog,
            presets,
            grantable: ['dashboard.view', 'calendar.view', 'appointments.create', 'settings.billing', 'settings.team'],
            isOwner: true,
            loading: false,
            error: null,
            errorCode: null,
            fieldErrors: {},
            ...props,
        } as never,
        attachTo: document.body,
    });

const fill = async (wrapper: ReturnType<typeof dialog>): Promise<void> => {
    const inputs = wrapper.findAll('input');
    await inputs[0]!.setValue('Élodie Martin');
    await inputs[1]!.setValue('elodie@example.com');
};

describe('TeamMemberDialog', () => {
    it("commence par les coordonnées et n'avance que si le nom et l'adresse sont valides", async () => {
        const wrapper = dialog();

        expect(wrapper.text()).toContain('team.dialog.steps.details');
        const next = wrapper.findAll('button').find((button) => button.text() === 'team.dialog.next')!;
        expect(next.attributes('disabled')).toBeDefined();

        await fill(wrapper);
        expect(next.attributes('disabled')).toBeUndefined();
    });

    it("propose les accès du préréglage choisi et montre un résumé avant l'envoi", async () => {
        const wrapper = dialog();
        await fill(wrapper);
        await wrapper.find('form').trigger('submit');

        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect(boxes.filter((box) => (box.element as HTMLInputElement).checked)).toHaveLength(3);

        await wrapper.find('form').trigger('submit');
        expect(wrapper.text()).toContain('team.summary.granted');
        expect(wrapper.text()).toContain('team.caps.calendar.view.label');
    });

    it('envoie les capacités exactes choisies, avec le rôle de base du serveur', async () => {
        const wrapper = dialog();
        await fill(wrapper);
        await wrapper.findAll('input[type="radio"]')[1]!.setValue(true);
        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');
        await wrapper.find('form').trigger('submit');

        const [payload] = wrapper.emitted('submit')![0] as [Record<string, unknown>];
        expect(payload).toMatchObject({
            name: 'Élodie Martin',
            email: 'elodie@example.com',
            role: 'receptionist',
            capabilities: ['dashboard.view', 'calendar.view'],
        });
    });

    it("avertit d'un accès essentiel retiré et exige une confirmation explicite", async () => {
        const wrapper = dialog();
        await fill(wrapper);
        await wrapper.find('form').trigger('submit');
        await wrapper.find('input[type="checkbox"]').setValue(false); // retire « tableau de bord »
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('team.warnings.no_dashboard');
        const send = wrapper.findAll('button').find((button) => button.text() === 'team.dialog.send')!;
        expect(send.attributes('disabled')).toBeDefined();

        await wrapper.findAll('input[type="checkbox"]').at(-1)!.setValue(true);
        expect(send.attributes('disabled')).toBeUndefined();
    });

    it('exige de confirmer l’octroi d’un accès sensible (facturation, équipe)', async () => {
        const wrapper = dialog();
        await fill(wrapper);
        await wrapper.find('form').trigger('submit');
        const boxes = wrapper.findAll('input[type="checkbox"]');
        await boxes[boxes.length - 2]!.setValue(true); // facturation
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('team.sensitive.title');
        expect(
            wrapper
                .findAll('button')
                .find((button) => button.text() === 'team.dialog.send')!
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('verrouille les accès sensibles pour qui n’est pas propriétaire', async () => {
        const wrapper = dialog({ isOwner: false, grantable: ['dashboard.view', 'calendar.view', 'appointments.create'] });
        await fill(wrapper);
        await wrapper.find('form').trigger('submit');

        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect((boxes[boxes.length - 1]!.element as HTMLInputElement).disabled).toBe(true);
        expect((boxes[boxes.length - 2]!.element as HTMLInputElement).disabled).toBe(true);
        expect(wrapper.text()).toContain('team.picker.owner_only');
    });

    it('en modification, montre ce qui est ajouté et retiré', async () => {
        const member = {
            id: 'm1',
            kind: 'member',
            name: 'Anna Dupont',
            email: 'anna@example.com',
            phone: null,
            role: 'practitioner',
            base_role: 'practitioner',
            preset: 'practitioner',
            status: 'active',
            is_owner: false,
            is_self: false,
            practitioner_id: null,
            photo_version: null,
            last_login_at: null,
            created_at: null,
            invited_at: null,
            capabilities: ['dashboard.view', 'calendar.view', 'appointments.create'],
            level: 'limited',
            notes: null,
            editable: true,
        };
        const wrapper = dialog({ mode: 'edit', member });

        expect(wrapper.text()).toContain('team.dialog.steps.access');
        await wrapper.findAll('input[type="checkbox"]')[2]!.setValue(false); // retire « créer des rendez-vous »
        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('team.summary.removed');
        expect(wrapper.text()).toContain('team.caps.appointments.create.label');
    });

    it("affiche l'erreur du serveur et propose d'ouvrir une personne déjà dans l'équipe", async () => {
        const wrapper = dialog({ error: 'Cette personne fait déjà partie de votre équipe.', errorCode: 'already_member' });
        await fill(wrapper);

        expect(wrapper.text()).toContain('Cette personne fait déjà partie de votre équipe.');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'team.dialog.open_existing')!
            .trigger('click');
        expect(wrapper.emitted('open-member')![0]).toEqual(['elodie@example.com']);
    });
});
