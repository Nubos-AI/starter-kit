import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import type { MultiSelectOption } from '@/components/ui/multi-select';
import MultiSelect from '@/components/ui/multi-select/MultiSelect.vue';
import {
    comboboxPrimitiveStubs,
    makeScrollable,
} from '@/tests/comboboxPrimitiveStubs';

const options: MultiSelectOption[] = [
    { value: 'role-1', label: 'Vertrieb', avatar: { name: 'Rita Vertrieb' } },
    { value: 'role-2', label: 'Support', description: 'support@nubos.de' },
    {
        value: 'role-3',
        label: 'Super-Admin',
        badge: 'Eskaliert',
        disabled: true,
        disabledReason: 'Keine Berechtigung',
    },
];

type Wrapper = ReturnType<typeof mount>;

function mountSelect(modelValue: string[] | null = []): Wrapper {
    return mount(MultiSelect, {
        props: { options, modelValue: modelValue as string[] },
        global: { stubs: comboboxPrimitiveStubs },
    });
}

function emittedModel(wrapper: Wrapper): string[] {
    const events = wrapper.emitted('update:modelValue') as
        | [string[]][]
        | undefined;

    if (events === undefined) {
        throw new Error('no update:modelValue emitted');
    }

    return events[events.length - 1][0];
}

function optionAt(wrapper: Wrapper, index: number) {
    return wrapper.findAll('[data-multi-select-item]')[index];
}

describe('MultiSelect', () => {
    it('keeps a German accessible name with a custom trigger and real primitives', () => {
        const wrapper = mount(MultiSelect, {
            props: { options, modelValue: [], ariaLabel: 'Mitwirkende' },
            slots: { trigger: '<button data-custom-trigger>Personen wählen</button>' },
        });

        expect(wrapper.get('[data-custom-trigger]').attributes('aria-label')).toBe('Mitwirkende');
    });

    it('shows the placeholder while nothing is selected', () => {
        const wrapper = mountSelect();

        expect(wrapper.text()).toContain('Bitte wählen');
    });

    it('renders a token per selected option', () => {
        const wrapper = mountSelect(['role-1', 'role-2']);

        expect(wrapper.text()).toContain('Vertrieb');
        expect(wrapper.text()).toContain('Support');
        expect(wrapper.findAll('[aria-label$="entfernen"]')).toHaveLength(2);
    });

    it('selects an option', async () => {
        const wrapper = mountSelect([]);

        await optionAt(wrapper, 0).trigger('click');

        expect(emittedModel(wrapper)).toEqual(['role-1']);
    });

    it('deselects an option', async () => {
        const wrapper = mountSelect(['role-1', 'role-2']);

        await optionAt(wrapper, 1).trigger('click');

        expect(emittedModel(wrapper)).toEqual(['role-1']);
    });

    it('removes a selection through its token', async () => {
        const wrapper = mountSelect(['role-1', 'role-2']);

        await wrapper.find('[aria-label="Vertrieb entfernen"]').trigger('click');

        expect(emittedModel(wrapper)).toEqual(['role-2']);
    });

    it('ignores a selection attempt on a disabled option', async () => {
        const wrapper = mountSelect([]);
        const item = optionAt(wrapper, 2);

        expect(item.attributes('title')).toBe('Keine Berechtigung');

        await item.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('offers no remove control for a disabled selection', () => {
        const wrapper = mountSelect(['role-3']);

        expect(wrapper.text()).toContain('Super-Admin');
        expect(
            wrapper.find('[aria-label="Super-Admin entfernen"]').exists(),
        ).toBe(false);
    });

    it('filters the options by label and description', async () => {
        const wrapper = mountSelect([]);

        await wrapper
            .find('[data-multi-select-search]')
            .setValue('support@nubos');

        const visible = wrapper.findAll('[data-multi-select-item]');

        expect(visible).toHaveLength(1);
        expect(visible[0].text()).toContain('Support');
    });

    it('keeps the selection while the search filters it out', async () => {
        const wrapper = mountSelect(['role-1']);

        await wrapper.find('[data-multi-select-search]').setValue('Support');

        expect(wrapper.findAll('[data-multi-select-item]')).toHaveLength(1);
        expect(wrapper.find('[aria-label="Vertrieb entfernen"]').exists()).toBe(
            true,
        );
    });

    it('reports an empty search result', async () => {
        const wrapper = mountSelect([]);

        await wrapper.find('[data-multi-select-search]').setValue('Buchhaltung');

        expect(wrapper.find('[data-multi-select-empty]').text()).toBe(
            'Kein Treffer',
        );
    });

    it('renders an avatar for an option that carries one', () => {
        const wrapper = mountSelect([]);

        expect(optionAt(wrapper, 0).find('[data-user-avatar]').exists()).toBe(
            true,
        );
        expect(optionAt(wrapper, 1).find('[data-user-avatar]').exists()).toBe(
            false,
        );
    });

    it('hides the search when it is switched off', () => {
        const wrapper = mount(MultiSelect, {
            props: { options, modelValue: [], searchable: false },
            global: { stubs: comboboxPrimitiveStubs },
        });

        expect(wrapper.find('[data-multi-select-search]').exists()).toBe(false);
    });

    it('renders an empty selection when the model is null', () => {
        const wrapper = mountSelect(null);

        expect(wrapper.text()).toContain('Bitte wählen');
        expect(wrapper.findAll('[aria-label$="entfernen"]')).toHaveLength(0);
    });

    it('selects an option although the model started out null', async () => {
        const wrapper = mountSelect(null);

        await optionAt(wrapper, 0).trigger('click');

        expect(emittedModel(wrapper)).toEqual(['role-1']);
    });
});

describe('MultiSelect on the real combobox primitives', () => {
    beforeAll(() => {
        Element.prototype.scrollIntoView = () => undefined;
    });

    beforeEach(() => {
        document.body.replaceChildren();
    });

    async function openSelect(modelValue: string[] | null): Promise<void> {
        const wrapper = mount(MultiSelect, {
            props: { options, modelValue: modelValue as string[] },
            attachTo: document.body,
        });

        await wrapper.find('[data-multi-select-trigger]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
    }

    function selectionStates(): Array<string | null> {
        return Array.from(
            document.querySelectorAll('[data-multi-select-item]'),
        ).map((item) => item.getAttribute('aria-selected'));
    }

    it('reports the selection state on every option', async () => {
        await openSelect(['role-1', 'role-3']);

        expect(selectionStates()).toEqual(['true', 'false', 'true']);
    });

    it('survives a null model without tearing down the page', async () => {
        await openSelect(null);

        expect(selectionStates()).toEqual(['false', 'false', 'false']);
    });
});

describe('MultiSelect — a list the server keeps', () => {
    it('leaves the filtering to the server instead of narrowing the options itself', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: [],
                serverSearch: true,
                options: [
                    { value: 'a', label: 'Hafenprojekt' },
                    { value: 'b', label: 'Landprojekt' },
                ],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await wrapper.get('[data-multi-select-trigger]').trigger('click');
        await wrapper.get('[data-multi-select-search]').setValue('Hafen');

        expect(wrapper.findAll('[data-multi-select-item]')).toHaveLength(2);
    });

    it('names the term it was typed so the server can answer it', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: [],
                serverSearch: true,
                options: [{ value: 'a', label: 'Hafenprojekt' }],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await wrapper.get('[data-multi-select-trigger]').trigger('click');
        await wrapper.get('[data-multi-select-search]').setValue('Hafen');

        expect(wrapper.emitted('search')?.at(-1)).toEqual(['Hafen']);
    });

    it('asks for more options once the list is scrolled to its end', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: [],
                serverSearch: true,
                options: [{ value: 'a', label: 'Hafenprojekt' }],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await wrapper.get('[data-multi-select-trigger]').trigger('click');

        const viewport = wrapper.get('[data-multi-select-viewport]');

        makeScrollable(viewport.element, {
            scrollTop: 800,
            clientHeight: 240,
            scrollHeight: 1000,
        });

        await viewport.trigger('scroll');

        expect(wrapper.emitted('loadMore')).toHaveLength(1);
    });

    it('leaves the selection it handed the primitive alone while the list is open', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: ['a'],
                serverSearch: true,
                options: [{ value: 'a', label: 'Hafenprojekt' }],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        const root = wrapper.findComponent(comboboxPrimitiveStubs.ComboboxRoot);
        root.vm.$emit('update:open', true);
        await nextTick();

        await wrapper.setProps({
            modelValue: ['a', 'b'],
            options: [
                { value: 'a', label: 'Hafenprojekt' },
                { value: 'b', label: 'Landprojekt' },
            ],
        });

        expect(root.props('modelValue')).toEqual(['a']);
    });

    it('takes the grown selection over once the list is closed again', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: ['a'],
                serverSearch: true,
                options: [{ value: 'a', label: 'Hafenprojekt' }],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        const root = wrapper.findComponent(comboboxPrimitiveStubs.ComboboxRoot);
        root.vm.$emit('update:open', true);
        await nextTick();

        await wrapper.setProps({
            modelValue: ['a', 'b'],
            options: [
                { value: 'a', label: 'Hafenprojekt' },
                { value: 'b', label: 'Landprojekt' },
            ],
        });

        root.vm.$emit('update:open', false);
        await nextTick();

        expect(root.props('modelValue')).toEqual(['a', 'b']);
    });

    it('narrows the options itself while no server answers for it', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: [],
                options: [
                    { value: 'a', label: 'Hafenprojekt' },
                    { value: 'b', label: 'Landprojekt' },
                ],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await wrapper.get('[data-multi-select-trigger]').trigger('click');
        await wrapper.get('[data-multi-select-search]').setValue('Hafen');

        expect(wrapper.findAll('[data-multi-select-item]')).toHaveLength(1);
    });
});


describe('MultiSelect — a term with a space in it', () => {
    it('searches for the leading space instead of throwing it away', async () => {
        const wrapper = mount(MultiSelect, {
            props: {
                modelValue: [],
                options: [
                    { value: 'a', label: 'Projekt 40' },
                    { value: 'b', label: 'Projekt 140' },
                ],
            },
            global: { stubs: comboboxPrimitiveStubs },
        });

        await wrapper.get('[data-multi-select-trigger]').trigger('click');
        await wrapper.get('[data-multi-select-search]').setValue(' 40');

        expect(
            wrapper
                .findAll('[data-multi-select-item]')
                .map((item) => item.text()),
        ).toEqual(['Projekt 40']);
    });
});
