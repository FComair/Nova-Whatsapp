import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import CategoryCombobox from './CategoryCombobox.vue';

/**
 * Liste des catégories : s'ouvre avec toute la liste de la langue, se filtre sans accents, propose de créer, se pilote au clavier.
 */
const shared = reactive({ locale: 'fr', translations: {} as Record<string, unknown> });

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: shared }) }));

const options = ['Cheveux', 'Barbe', 'Coloration'];

function field(value = '', list: string[] = options) {
    return mount(CategoryCombobox, {
        props: { label: 'Catégorie', options: list, modelValue: value, 'onUpdate:modelValue': (next: string) => wrapper.setProps({ modelValue: next }) },
        attachTo: document.body,
    });
}

let wrapper: ReturnType<typeof field>;
const optionTexts = () => wrapper.findAll('[role="option"]').map((item) => item.text());

describe('CategoryCombobox', () => {
    it("est fermée au départ et expose les rôles ARIA d'une liste déroulante", () => {
        wrapper = field();
        const input = wrapper.find('input');

        expect(input.attributes('role')).toBe('combobox');
        expect(input.attributes('aria-expanded')).toBe('false');
        expect(wrapper.find('[role="listbox"]').isVisible()).toBe(false);
        wrapper.unmount();
    });

    it("s'ouvre au clic avec toute la liste, sans la filtrer par la valeur actuelle", async () => {
        wrapper = field('Barbe');
        await wrapper.find('input').trigger('click');

        expect(wrapper.find('input').attributes('aria-expanded')).toBe('true');
        expect(optionTexts()).toEqual(['Cheveux', 'Barbe', 'Coloration']);
        expect(wrapper.find('[aria-selected="true"]').text()).toBe('Barbe');
        wrapper.unmount();
    });

    it("s'ouvre aussi avec le bouton flèche", async () => {
        wrapper = field();
        await wrapper.find('button').trigger('click');

        expect(wrapper.find('input').attributes('aria-expanded')).toBe('true');
        wrapper.unmount();
    });

    it('filtre à la frappe sans tenir compte des accents ni de la casse, et propose de créer le nom saisi', async () => {
        wrapper = field();
        await wrapper.find('input').setValue('COLORATIÖN'.replace('Ö', 'O'));
        expect(optionTexts()[0]).toBe('Coloration');

        await wrapper.find('input').setValue('Soins');
        expect(optionTexts()).toEqual(['services.fields.category_create']);
        wrapper.unmount();
    });

    it('ne propose pas de créer un nom qui existe déjà', async () => {
        wrapper = field();
        await wrapper.find('input').setValue('barbe');

        expect(optionTexts()).toEqual(['Barbe']);
        wrapper.unmount();
    });

    it('choisit une option au clic et se referme', async () => {
        wrapper = field();
        await wrapper.find('input').trigger('click');
        await wrapper.findAll('[role="option"]')[2]!.trigger('mousedown');

        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('Coloration');
        expect(wrapper.find('input').attributes('aria-expanded')).toBe('false');
        wrapper.unmount();
    });

    it('se pilote au clavier : flèches, Entrée, Échap', async () => {
        wrapper = field();
        const input = wrapper.find('input');

        await input.trigger('keydown', { key: 'ArrowDown' });
        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(input.attributes('aria-activedescendant')).toContain('option-1');

        await input.trigger('keydown', { key: 'Enter' });
        expect((input.element as HTMLInputElement).value).toBe('Barbe');
        expect(input.attributes('aria-expanded')).toBe('false');

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(input.attributes('aria-expanded')).toBe('true');
        await input.trigger('keydown', { key: 'Escape' });
        expect(input.attributes('aria-expanded')).toBe('false');
        wrapper.unmount();
    });

    it("indique clairement l'absence de catégorie dans la langue", async () => {
        wrapper = field('', []);
        await wrapper.find('input').trigger('click');

        expect(wrapper.text()).toContain('services.fields.category_empty');
        expect(wrapper.text()).toContain('services.fields.category_empty_hint');
        wrapper.unmount();
    });

    it('nomme la langue de la liste', async () => {
        wrapper = field();
        await wrapper.find('input').trigger('click');

        expect(wrapper.find('[role="listbox"]').attributes('aria-label')).toBe('services.fields.category_list');
        wrapper.unmount();
    });
});
