import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldOptionsEditor from '@/components/engine/objectType/FieldOptionsEditor.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldType } from '@/types/fields';

interface EditorProps {
    fieldType: FieldType;
    initialOptions: string[];
    initialLookupObjectType: string;
    objectTypeOptions: Array<{ value: string; label: string }>;
    errorMessage?: string;
}

const regionSelector = '[data-testid="field-options-editor"]';
const optionInputSelector = '[data-testid^="option-input-"]';
const submittedSelector = 'input[name^="config\\[options\\]"]';
const lookupSelector = 'select[name="config[lookup_object_type]"]';

const InputStub = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
        '<input class="ui-input" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const ButtonStub = {
    props: ['disabled'],
    emits: ['click'],
    template:
        '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const stubs = {
    ...selectStubs,
    Input: InputStub,
    Button: ButtonStub,
};

const objectTypeOptions = [
    { value: 'companies', label: 'Companies' },
    { value: 'contacts', label: 'Contacts' },
];

function mountEditor(overrides: Partial<EditorProps> = {}) {
    return mount(FieldOptionsEditor, {
        props: {
            fieldType: 'single_select',
            initialOptions: ['niedrig', 'mittel', 'hoch'],
            initialLookupObjectType: '',
            objectTypeOptions,
            ...overrides,
        } satisfies EditorProps,
        global: { stubs },
    });
}

function submittedValues(wrapper: ReturnType<typeof mountEditor>): string[] {
    return wrapper
        .findAll(submittedSelector)
        .map((input) => input.attributes('value') ?? '');
}

describe('FieldOptionsEditor', () => {
    it('stays hidden for a field type without options', () => {
        const wrapper = mountEditor({
            fieldType: 'text_short',
            initialOptions: [],
        });

        expect(wrapper.find(regionSelector).exists()).toBe(false);
    });

    it('renders for a multi select field', () => {
        const wrapper = mountEditor({ fieldType: 'multi_select' });

        expect(wrapper.find(regionSelector).exists()).toBe(true);
    });

    it('submits the initial options in order', () => {
        expect(submittedValues(mountEditor())).toEqual([
            'niedrig',
            'mittel',
            'hoch',
        ]);
    });

    it('adds an option', async () => {
        const wrapper = mountEditor();

        await wrapper.find('[data-testid="option-add"]').trigger('click');
        await wrapper.findAll(optionInputSelector)[3].setValue('dringend');

        expect(submittedValues(wrapper)).toEqual([
            'niedrig',
            'mittel',
            'hoch',
            'dringend',
        ]);
    });

    it('removes an option', async () => {
        const wrapper = mountEditor();

        await wrapper.find('[data-testid="option-remove-1"]').trigger('click');

        expect(submittedValues(wrapper)).toEqual(['niedrig', 'hoch']);
    });

    it('renames an option', async () => {
        const wrapper = mountEditor();

        await wrapper.findAll(optionInputSelector)[0].setValue('gering');

        expect(submittedValues(wrapper)).toEqual(['gering', 'mittel', 'hoch']);
    });

    it('moves an option up', async () => {
        const wrapper = mountEditor();

        await wrapper
            .find('[aria-label="Option 2 nach oben"]')
            .trigger('click');

        expect(submittedValues(wrapper)).toEqual(['mittel', 'niedrig', 'hoch']);
    });

    it('drops blank options from the payload', async () => {
        const wrapper = mountEditor();

        await wrapper.findAll(optionInputSelector)[1].setValue('   ');

        expect(submittedValues(wrapper)).toEqual(['niedrig', 'hoch']);
    });

    it('submits a lookup object type instead of options', () => {
        const wrapper = mountEditor({
            initialOptions: [],
            initialLookupObjectType: 'companies',
        });

        expect(wrapper.find(lookupSelector).exists()).toBe(true);
        expect(wrapper.findAll(submittedSelector)).toHaveLength(0);
    });

    it('never submits options and a lookup at the same time', async () => {
        const wrapper = mountEditor();

        await wrapper.findAll('select')[0].setValue('lookup');

        expect(wrapper.findAll(submittedSelector)).toHaveLength(0);
        expect(wrapper.find(lookupSelector).exists()).toBe(true);
    });

    it('shows the validation error', () => {
        const wrapper = mountEditor({
            errorMessage: 'Die Optionen müssen eindeutig sein.',
        });

        expect(wrapper.text()).toContain('Die Optionen müssen eindeutig sein.');
    });
});
