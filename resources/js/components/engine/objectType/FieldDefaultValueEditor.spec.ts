import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldDefaultValueEditor from '@/components/engine/objectType/FieldDefaultValueEditor.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldDefaultValue } from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';

interface EditorProps {
    fieldType: FieldType;
    initialDefault: FieldDefaultValue | null;
    errorMessage?: string;
}

const regionSelector = '[data-testid="field-default-editor"]';
const kindSelector = 'input[name="default_value[kind]"]';
const staticSelector = '[data-testid="default-static-value"]';
const sourceSelector = 'select[name="default_value[source]"]';

const InputStub = {
    props: ['modelValue', 'type'],
    emits: ['update:modelValue'],
    template:
        '<input class="ui-input" :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const stubs = { ...selectStubs, Input: InputStub };

function mountEditor(overrides: Partial<EditorProps> = {}) {
    return mount(FieldDefaultValueEditor, {
        props: {
            fieldType: 'text_short',
            initialDefault: null,
            ...overrides,
        } satisfies EditorProps,
        global: { stubs },
    });
}

describe('FieldDefaultValueEditor', () => {
    it('stays hidden for a computed field', () => {
        const wrapper = mountEditor({ fieldType: 'computed' });

        expect(wrapper.find(regionSelector).exists()).toBe(false);
    });

    it('stays hidden for a roll-up field', () => {
        const wrapper = mountEditor({ fieldType: 'rollup' });

        expect(wrapper.find(regionSelector).exists()).toBe(false);
    });

    it('submits nothing while no default is chosen', () => {
        const wrapper = mountEditor();

        expect(wrapper.find(kindSelector).exists()).toBe(false);
        expect(wrapper.find(staticSelector).exists()).toBe(false);
    });

    it('submits a static value', async () => {
        const wrapper = mountEditor();

        await wrapper.findAll('select')[0].setValue('static');
        await wrapper.find(staticSelector).setValue('Neu');

        expect(wrapper.find(kindSelector).attributes('value')).toBe('static');
        expect(
            (wrapper.find(staticSelector).element as HTMLInputElement).value,
        ).toBe('Neu');
    });

    it('submits a dynamic source', async () => {
        const wrapper = mountEditor({ fieldType: 'date' });

        await wrapper.findAll('select')[0].setValue('dynamic');

        expect(wrapper.find(kindSelector).attributes('value')).toBe('dynamic');
        expect(wrapper.find(sourceSelector).exists()).toBe(true);
    });

    it('prefills an existing static default', () => {
        const wrapper = mountEditor({
            fieldType: 'number',
            initialDefault: { kind: 'static', value: 42 },
        });

        expect(
            (wrapper.find(staticSelector).element as HTMLInputElement).value,
        ).toBe('42');
    });

    it('prefills an existing dynamic default', () => {
        const wrapper = mountEditor({
            fieldType: 'date',
            initialDefault: { kind: 'dynamic', source: 'today' },
        });

        expect(
            (wrapper.find(sourceSelector).element as HTMLSelectElement).value,
        ).toBe('today');
    });

    it('uses a number input for a money field', async () => {
        const wrapper = mountEditor({ fieldType: 'money' });

        await wrapper.findAll('select')[0].setValue('static');

        expect(wrapper.find(staticSelector).attributes('type')).toBe('number');
    });

    it('shows the validation error', () => {
        const wrapper = mountEditor({
            errorMessage: 'Der Standardwert passt nicht zum Feldtyp.',
        });

        expect(wrapper.text()).toContain(
            'Der Standardwert passt nicht zum Feldtyp.',
        );
    });
});
