import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import Combobox from '@/shared/ui/primitives/combobox/Combobox.vue';
import {
    comboboxPrimitiveStubs,
    makeScrollable,
} from '@/shared/testing/comboboxPrimitiveStubs';
import type { SelectOption } from '@/shared/types/ui';

const options: SelectOption[] = [
    {
        value: 'user-1',
        label: 'Rita Vertrieb',
        description: 'rita@nubos.de',
        avatar: { name: 'Rita Vertrieb' },
    },
    {
        value: 'user-2',
        label: 'Sven Support',
        description: 'sven@nubos.de',
        avatar: { name: 'Sven Support' },
    },
    {
        value: 'user-3',
        label: 'Gesperrt',
        disabled: true,
        disabledReason: 'Keine Berechtigung',
    },
];

type Wrapper = ReturnType<typeof mount>;

function mountCombobox(
    modelValue: string | null = null,
    extraProps: Record<string, unknown> = {},
): Wrapper {
    return mount(Combobox, {
        props: { options, modelValue, ...extraProps },
        global: { stubs: comboboxPrimitiveStubs },
    });
}

function itemAt(wrapper: Wrapper, index: number) {
    return wrapper.findAll('[data-combobox-item]')[index];
}

describe('Combobox', () => {
    it('keeps a German accessible name with a custom trigger and real primitives', () => {
        const wrapper = mount(Combobox, {
            props: { options, modelValue: null, ariaLabel: 'Besitzer' },
            slots: { trigger: '<button data-custom-trigger>Person wählen</button>' },
        });

        expect(wrapper.get('[data-custom-trigger]').attributes('aria-label')).toBe('Besitzer');
    });

    it('shows the placeholder while nothing is selected', () => {
        const wrapper = mountCombobox();

        expect(wrapper.find('[data-combobox-trigger]').text()).toContain(
            'Bitte wählen',
        );
    });

    it('shows the label of the selected option', () => {
        const wrapper = mountCombobox('user-2');

        expect(wrapper.find('[data-combobox-trigger]').text()).toContain(
            'Sven Support',
        );
    });

    it('selects an option', async () => {
        const wrapper = mountCombobox();

        await itemAt(wrapper, 0).trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['user-1']]);
    });

    it('offers and selects an option whose value is empty', async () => {
        const wrapper = mount(Combobox, {
            props: {
                options: [{ value: '', label: 'Keine Auswahl' }, ...options],
                modelValue: 'user-1',
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await itemAt(wrapper, 0).trigger('click');

        expect(itemAt(wrapper, 0).text()).toContain('Keine Auswahl');
        expect(wrapper.emitted('update:modelValue')).toEqual([['']]);
    });

    it('marks the empty option as selected and shows its label', () => {
        const wrapper = mount(Combobox, {
            props: {
                options: [{ value: '', label: 'Keine Auswahl' }, ...options],
                modelValue: '',
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        expect(wrapper.find('[data-combobox-trigger]').text()).toContain(
            'Keine Auswahl',
        );
    });

    it('ignores a selection attempt on a disabled option', async () => {
        const wrapper = mountCombobox();

        expect(itemAt(wrapper, 2).attributes('title')).toBe(
            'Keine Berechtigung',
        );

        await itemAt(wrapper, 2).trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('filters the options by label', async () => {
        const wrapper = mountCombobox();

        await wrapper.find('[data-combobox-search]').setValue('rita');

        const visible = wrapper.findAll('[data-combobox-item]');

        expect(visible).toHaveLength(1);
        expect(visible[0].text()).toContain('Rita Vertrieb');
    });

    it('filters the options by description', async () => {
        const wrapper = mountCombobox();

        await wrapper.find('[data-combobox-search]').setValue('sven@nubos');

        expect(wrapper.findAll('[data-combobox-item]')).toHaveLength(1);
    });

    it('reports an empty search result', async () => {
        const wrapper = mountCombobox();

        await wrapper.find('[data-combobox-search]').setValue('niemand');

        expect(wrapper.find('[data-combobox-empty]').text()).toBe(
            'Kein Treffer',
        );
    });

    it('reports an empty option list', () => {
        const wrapper = mount(Combobox, {
            props: { options: [], modelValue: null },
            global: { stubs: comboboxPrimitiveStubs },
        });

        expect(wrapper.find('[data-combobox-empty]').text()).toBe(
            'Keine Optionen vorhanden',
        );
    });

    it('renders an avatar in the option list and in the trigger', () => {
        const wrapper = mountCombobox('user-1');

        expect(
            wrapper.find('[data-combobox-trigger] [data-user-avatar]').exists(),
        ).toBe(true);
        expect(itemAt(wrapper, 0).find('[data-user-avatar]').exists()).toBe(
            true,
        );
    });

    it('hides the search when it is switched off', () => {
        const wrapper = mountCombobox(null, { searchable: false });

        expect(wrapper.find('[data-combobox-search]').exists()).toBe(false);
    });
});

describe('Combobox — a list the server keeps', () => {
    it('leaves the filtering to the server instead of narrowing the options itself', async () => {
        const wrapper = mountCombobox(null, { serverSearch: true });

        await wrapper.get('[data-combobox-search]').setValue('Rita');

        expect(wrapper.findAll('[data-combobox-item]')).toHaveLength(3);
    });

    it('names the term it was typed so the server can answer it', async () => {
        const wrapper = mountCombobox(null, { serverSearch: true });

        await wrapper.get('[data-combobox-search]').setValue('Rita');

        expect(wrapper.emitted('search')?.at(-1)).toEqual(['Rita']);
    });

    it('asks for more options once the list is scrolled to its end', async () => {
        const wrapper = mountCombobox(null, { serverSearch: true });

        const viewport = wrapper.get('[data-combobox-viewport]');

        makeScrollable(viewport.element, {
            scrollTop: 800,
            clientHeight: 240,
            scrollHeight: 1000,
        });

        await viewport.trigger('scroll');

        expect(wrapper.emitted('loadMore')).toHaveLength(1);
    });

});

describe('Combobox — keyboard use on the real primitives', () => {
    beforeAll(() => {
        Element.prototype.scrollIntoView = () => undefined;
    });

    beforeEach(() => {
        document.body.replaceChildren();
    });

    function mountAttached(slots: Record<string, string> = {}): Wrapper {
        return mount(Combobox, {
            props: { options, modelValue: null, id: 'country' },
            slots,
            attachTo: document.body,
        });
    }

    async function settle(): Promise<void> {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    it('keeps the trigger in the tab order', () => {
        const wrapper = mountAttached();

        expect(wrapper.get('[data-combobox-trigger]').attributes('tabindex')).toBe('0');
    });

    it('keeps a custom trigger in the tab order', () => {
        const wrapper = mountAttached({ trigger: '<button data-custom-trigger>Land wählen</button>' });

        expect(wrapper.get('[data-custom-trigger]').attributes('tabindex')).toBe('0');
    });

    it('opens on arrow down and moves the focus into the search', async () => {
        const wrapper = mountAttached();

        await wrapper.get('[data-combobox-trigger]').trigger('keydown', { key: 'ArrowDown' });
        await settle();

        expect(document.querySelectorAll('[data-combobox-item]')).toHaveLength(3);
        expect(document.activeElement?.hasAttribute('data-combobox-search')).toBe(true);
    });

    it('closes on escape and hands the focus back to the trigger', async () => {
        const wrapper = mountAttached();
        const trigger = wrapper.get('[data-combobox-trigger]');

        await trigger.trigger('keydown', { key: 'ArrowDown' });
        await settle();

        document.activeElement?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();
        await settle();

        expect(document.querySelectorAll('[data-combobox-item]')).toHaveLength(0);
        expect(document.activeElement).toBe(trigger.element);
    });
});
