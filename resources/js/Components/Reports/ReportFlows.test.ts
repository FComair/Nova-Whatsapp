import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import ExportDialog from './ExportDialog.vue';
import ReportToolbar from './ReportToolbar.vue';

const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { locale: 'en', translations: {}, auth: {}, flash: {}, errors: {} } }),
    router: { get: (...args: unknown[]) => get(...args), on: vi.fn(() => vi.fn()) },
    Link: {
        props: { href: { type: String, default: '' } },
        render(this: { href: string; $slots: { default?: () => unknown } }) {
            return h('a', { href: this.href }, this.$slots.default?.() as never);
        },
    },
}));

const filters = {
    preset: 'this_month',
    from: '2026-06-01',
    to: '2026-06-30',
    location: null,
    practitioner: null,
    service: null,
    status: [] as string[],
    source: null,
    payment: null,
};
const period = {
    from: '2026-06-01',
    to: '2026-06-30',
    days: 30,
    previous: { from: '2026-05-01', to: '2026-05-31' },
    in_progress: false,
    timezone: 'Europe/Brussels',
};
const options = {
    locations: [
        { id: 'l1', name: 'Main' },
        { id: 'l2', name: 'Second' },
    ],
    practitioners: [{ id: 'p1', name: 'Anna' }],
    services: [{ id: 's1', name: 'Cut' }],
    statuses: ['confirmed', 'completed', 'no_show', 'cancelled'],
    sources: ['dashboard', 'whatsapp'],
    payment_statuses: ['unpaid', 'paid_onsite'],
    presets: ['today', 'this_week', 'this_month', 'last_month', 'this_quarter', 'custom'],
};
const access = {
    categories: ['appointments', 'customers', 'financial', 'practitioners'],
    financial: true,
    export: true,
    staff: true,
    own_only: false,
    can_configure: true,
    managers_financial: false,
    role: 'owner',
};

describe('ReportToolbar', () => {
    const toolbar = (props: Record<string, unknown> = {}) =>
        mount(ReportToolbar, { props: { category: 'appointments', filters, period, options, access, statusApplies: true, ...props } as never });

    beforeEach(() => get.mockReset());

    it('propose les périodes prédéfinies et marque celle qui est active', () => {
        const wrapper = toolbar();

        expect(wrapper.findAll('[data-testid^="preset-"]')).toHaveLength(6);
        expect(wrapper.find('[data-testid="preset-this_month"]').attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('[data-testid="preset-last_month"]').attributes('aria-pressed')).toBe('false');
    });

    it('recharge le rapport en gardant la catégorie et les filtres quand on change de période', async () => {
        const wrapper = toolbar({ filters: { ...filters, practitioner: 'p1', status: ['completed'] } });
        await wrapper.find('[data-testid="preset-last_month"]').trigger('click');

        expect(get).toHaveBeenCalledWith(
            '/rapports',
            { preset: 'last_month', practitioner: 'p1', status: ['completed'], category: 'appointments' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('n’envoie pas de catégorie depuis la vue d’ensemble', async () => {
        await toolbar({ category: 'overview' }).find('[data-testid="preset-today"]').trigger('click');

        expect(get.mock.calls[0]![1]).toEqual({ preset: 'today' });
    });

    it('valide la période personnalisée avant de l’appliquer', async () => {
        const wrapper = toolbar();
        await wrapper.find('[data-testid="preset-custom"]').trigger('click');
        const form = wrapper.find('[data-testid="custom-range"]');
        const [from, to] = form.findAll('input[type="date"]');

        await from!.setValue('2026-06-20');
        await to!.setValue('2026-06-10');
        expect(form.find('button[type="submit"]').attributes('disabled')).toBeDefined();

        await to!.setValue('2026-06-25');
        expect(form.find('button[type="submit"]').attributes('disabled')).toBeUndefined();
        await form.trigger('submit');
        expect(get).toHaveBeenCalledWith('/rapports', expect.objectContaining({ preset: 'custom', from: '2026-06-20', to: '2026-06-25' }), expect.anything());
    });

    it('applique un filtre de site, de service ou de source', async () => {
        const wrapper = toolbar();
        const selects = wrapper.findAll('select');

        await selects[0]!.setValue('l2');
        expect(get.mock.calls[0]![1]).toMatchObject({ location: 'l2' });
        await selects[2]!.setValue('s1');
        expect(get.mock.calls[1]![1]).toMatchObject({ service: 's1' });
    });

    it('cache le choix du site quand il n’y en a qu’un, et celui du praticien pour un praticien', () => {
        const single = toolbar({ options: { ...options, locations: [{ id: 'l1', name: 'Main' }] } });
        const own = toolbar({ access: { ...access, own_only: true } });

        expect(single.text()).not.toContain('reports.filter.location');
        expect(own.text()).not.toContain('reports.filter.practitioner');
        expect(own.text()).toContain('reports.filter.own_only');
    });

    it('résume la période, la comparaison et les filtres actifs', () => {
        const wrapper = toolbar({ filters: { ...filters, practitioner: 'p1' } });
        const summary = wrapper.find('[data-testid="report-summary"]').text();

        expect(summary).toContain('reports.period.range');
        expect(summary).toContain('reports.period.compared');
        expect(summary).toContain('reports.filter.active');
        expect(toolbar().find('[data-testid="report-summary"]').text()).toContain('reports.filter.none');
    });

    it('ne propose l’export qu’à qui y a droit', () => {
        expect(toolbar().find('[data-testid="export-open"]').exists()).toBe(true);
        expect(
            toolbar({ access: { ...access, export: false } })
                .find('[data-testid="export-open"]')
                .exists(),
        ).toBe(false);
        expect(
            toolbar({ access: { ...access, export: false } })
                .find('[data-testid="print-open"]')
                .exists(),
        ).toBe(true);
    });

    it('explique que le statut ne s’applique pas aux clients', () => {
        const wrapper = toolbar({ category: 'customers', statusApplies: false });

        expect(wrapper.find('fieldset').exists()).toBe(false);
        expect(wrapper.text()).toContain('reports.filter.not_applied');
    });
});

describe('ExportDialog', () => {
    const fetchMock = vi.fn();
    const preview = {
        filename: 'Appointments_Report_2026-06-01_to_2026-06-30.xlsx',
        tables: [
            { title: 'Key figures', rows: 11 },
            { title: 'Appointment details', rows: 42 },
        ],
    };

    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock.mockReset();
        vi.stubGlobal('fetch', fetchMock);
        HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
            this.setAttribute('open', '');
        });
        HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
            this.removeAttribute('open');
        });
        URL.createObjectURL = vi.fn(() => 'blob:x');
        URL.revokeObjectURL = vi.fn();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    async function opened(props: Record<string, unknown> = {}) {
        const wrapper = mount(ExportDialog, {
            props: { open: false, category: 'appointments', filters, period, access, ...props } as never,
            attachTo: document.body,
        });
        await wrapper.setProps({ open: true });
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        return wrapper;
    }

    it('présélectionne le rapport affiché et résume ce qui sera exporté avant de le produire', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => preview });
        const wrapper = await opened();

        expect(fetchMock.mock.calls[0]![0]).toContain('/rapports/export/apercu?');
        expect(fetchMock.mock.calls[0]![0]).toContain('reports%5B%5D=appointments');
        const text = wrapper.find('[data-testid="export-preview"]').text();
        // Sans traductions chargées, `t` rend la clé : on vérifie la présence des lignes du résumé (fichier + un tableau par ligne).
        expect(text).toContain('reports.export.file');
        expect(wrapper.findAll('[data-testid="export-preview"] li')).toHaveLength(2);
        wrapper.unmount();
    });

    it('exporte tous les rapports permis depuis la vue d’ensemble', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => preview });
        const wrapper = await opened({ category: 'overview' });

        expect(fetchMock.mock.calls[0]![0]).toContain('reports%5B%5D=practitioners');
        wrapper.unmount();
    });

    it('n’offre le détail par praticien qu’à qui y a droit', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => preview });
        const withStaff = await opened();
        const without = await opened({ access: { ...access, staff: false } });

        expect(withStaff.text()).toContain('reports.export.staff');
        expect(without.text()).not.toContain('reports.export.staff');
        withStaff.unmount();
        without.unmount();
    });

    it('télécharge le fichier et ferme la fenêtre', async () => {
        fetchMock.mockImplementation(async (url: string) =>
            url.includes('/apercu')
                ? { ok: true, json: async () => preview }
                : {
                      ok: true,
                      blob: async () => new Blob(['x']),
                      headers: new Headers({ 'Content-Disposition': 'attachment; filename="Appointments_Report.xlsx"' }),
                  },
        );
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
        const wrapper = await opened();

        await wrapper.find('[data-testid="export-form"]').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.at(-1)![0]).toContain('/rapports/export?');
        expect(click).toHaveBeenCalled();
        expect(wrapper.emitted('close')).toBeTruthy();
        click.mockRestore();
        wrapper.unmount();
    });

    it('affiche une erreur claire si l’export échoue', async () => {
        fetchMock.mockImplementation(async (url: string) => (url.includes('/apercu') ? { ok: true, json: async () => preview } : { ok: false, status: 500 }));
        const wrapper = await opened();

        await wrapper.find('[data-testid="export-form"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').text()).toContain('reports.export.failed');
        expect(wrapper.emitted('close')).toBeFalsy();
        wrapper.unmount();
    });

    it('ouvre la version imprimable pour le PDF, ou signale un blocage de fenêtre', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => preview });
        const open = vi.fn().mockReturnValue({});
        vi.stubGlobal('open', open);
        const wrapper = await opened();

        await wrapper.find('[data-testid="format-pdf"]').setValue(true);
        expect(wrapper.text()).toContain('reports.export.pdf_hint');
        await wrapper.find('[data-testid="export-form"]').trigger('submit');
        expect(open.mock.calls[0]![0]).toContain('/rapports/imprimer?');
        expect(open.mock.calls[0]![0]).toContain('auto=1');
        expect(wrapper.emitted('close')).toBeTruthy();

        open.mockReturnValue(null);
        await wrapper.find('[data-testid="export-form"]').trigger('submit');
        expect(wrapper.text()).toContain('reports.print.failed');
        wrapper.unmount();
    });

    it('bloque l’export sans rapport choisi', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => preview });
        const wrapper = await opened();

        await wrapper.find('input[type="checkbox"]').setValue(false);
        await vi.advanceTimersByTimeAsync(300);

        expect(wrapper.text()).toContain('reports.export.no_reports');
        expect(wrapper.find('[data-testid="export-submit"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });
});
