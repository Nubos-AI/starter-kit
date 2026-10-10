import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldValidationRulesEditor from '@/components/engine/objectType/FieldValidationRulesEditor.vue';
import { selectStubs } from '@/tests/selectStubs';
import type { FieldValidationRules } from '@/types/fieldEditor';
import type { FieldType } from '@/types/fields';
import type { ObjectTypeFieldRow } from '@/types/formulas';

interface EditorProps {
    fieldType: FieldType;
    fields: ObjectTypeFieldRow[];
    currentKey: string | null;
    initialRules: FieldValidationRules | null;
    errorMessage?: string;
}

const regionSelector = '[data-testid="field-validation-editor"]';
const regexSelector = '[data-testid="validation-regex"]';
const minSelector = '[data-testid="validation-min"]';
const allowedSelector = '[data-testid="validation-in"]';
const allowedHiddenSelector = 'input[name^="validation_rules\\[in\\]"]';
const crossHiddenSelector = 'input[name^="validation_rules\\[cross\\]"]';

const InputStub = {
    props: ['modelValue', 'type'],
    emits: ['update:modelValue'],
    template:
        '<input class="ui-input" :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const stubs = { ...selectStubs, Input: InputStub };

function fieldRow(key: string): ObjectTypeFieldRow {
    return {
        id: `id-${key}`,
        field_group_id: null,
        key,
        field_type: 'date',
        label: key,
        description: null,
        is_required: false,
        is_unique: false,
        is_searchable: false,
        is_translatable: false,
        is_encrypted: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: true,
        is_card_field: false,
        is_reserved: false,
        is_type_changeable: true,
        list_position: null,
        config: null,
        validation_rules: null,
        default_value: null,
    };
}

function mountEditor(overrides: Partial<EditorProps> = {}) {
    return mount(FieldValidationRulesEditor, {
        props: {
            fieldType: 'text_short',
            fields: [fieldRow('start'), fieldRow('ende')],
            currentKey: 'start',
            initialRules: null,
            ...overrides,
        } satisfies EditorProps,
        global: { stubs },
    });
}

describe('FieldValidationRulesEditor', () => {
    it('stays hidden for a boolean field', () => {
        const wrapper = mountEditor({ fieldType: 'boolean' });

        expect(wrapper.find(regionSelector).exists()).toBe(false);
    });

    it('renders for a numeric field', () => {
        const wrapper = mountEditor({ fieldType: 'number' });

        expect(wrapper.find(regionSelector).exists()).toBe(true);
    });

    it('offers a pattern only for textual fields', () => {
        expect(mountEditor().find(regexSelector).exists()).toBe(true);
        expect(
            mountEditor({ fieldType: 'number' }).find(regexSelector).exists(),
        ).toBe(false);
        expect(
            mountEditor({ fieldType: 'date' }).find(regexSelector).exists(),
        ).toBe(false);
    });

    it('prefills existing rules', () => {
        const wrapper = mountEditor({
            initialRules: { regex: '/^A/', min: 2, in: ['rot', 'blau'] },
        });

        expect(
            (wrapper.find(regexSelector).element as HTMLInputElement).value,
        ).toBe('/^A/');
        expect(
            (wrapper.find(minSelector).element as HTMLInputElement).value,
        ).toBe('2');
        expect(
            (wrapper.find(allowedSelector).element as HTMLInputElement).value,
        ).toBe('rot, blau');
    });

    it('splits the allowed values into indexed inputs', async () => {
        const wrapper = mountEditor();

        await wrapper.find(allowedSelector).setValue('rot, grün ,blau');

        expect(
            wrapper
                .findAll(allowedHiddenSelector)
                .map((input) => input.attributes('value')),
        ).toEqual(['rot', 'grün', 'blau']);
    });

    it('submits no cross rule while no operator is chosen', () => {
        const wrapper = mountEditor({ fieldType: 'date' });

        expect(wrapper.findAll(crossHiddenSelector)).toHaveLength(0);
    });

    it('submits the cross rule once operator and target are set', async () => {
        const wrapper = mountEditor({ fieldType: 'date' });
        const selects = wrapper.findAll('select');

        await selects[selects.length - 2].setValue('before');
        await selects[selects.length - 1].setValue('ende');

        const hidden = wrapper.find(crossHiddenSelector);

        expect(hidden.attributes('name')).toBe(
            'validation_rules[cross][before]',
        );
        expect(hidden.attributes('value')).toBe('ende');
    });

    it('excludes the edited field from the comparison targets', () => {
        const wrapper = mountEditor({ fieldType: 'date' });
        const selects = wrapper.findAll('select');
        const targets = selects[selects.length - 1]
            .findAll('option')
            .map((option) => option.attributes('value'));

        expect(targets).toEqual(['ende']);
    });

    it('prefills an existing cross rule', () => {
        const wrapper = mountEditor({
            fieldType: 'date',
            initialRules: { cross: { after: 'ende' } },
        });

        const hidden = wrapper.find(crossHiddenSelector);

        expect(hidden.attributes('name')).toBe(
            'validation_rules[cross][after]',
        );
        expect(hidden.attributes('value')).toBe('ende');
    });

    it('shows the validation error', () => {
        const wrapper = mountEditor({
            errorMessage: 'Die Regel ist nicht lesbar.',
        });

        expect(wrapper.text()).toContain('Die Regel ist nicht lesbar.');
    });
});
