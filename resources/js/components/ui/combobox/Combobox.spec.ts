import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Combobox from '@/components/ui/combobox/Combobox.vue';
import {
    comboboxPrimitiveStubs,
    makeScrollable,
} from '@/tests/comboboxPrimitiveStubs';
import type { SelectOption } from '@/types/ui';

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
