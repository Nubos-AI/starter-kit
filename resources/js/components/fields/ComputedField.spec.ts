import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ComputedField from '@/components/fields/ComputedField.vue';
import type { FieldDefinition } from '@/types/fields';

const field = {
    key: 'display_name',
    label: 'Name',
    field_type: 'computed',
    is_required: false,
    config: {
        formula: 'CONCAT({first_name}; " "; {last_name})',
        result_type: 'text',
    },
} as unknown as FieldDefinition;

function mountField(modelValue: unknown) {
    return mount(ComputedField, { props: { field, modelValue } });
}

describe('ComputedField', () => {
    it('renders the calculated value without an editable control', () => {
        const wrapper = mountField('Max Mustermann');

        expect(wrapper.text()).toContain('Max Mustermann');
        expect(wrapper.find('input').exists()).toBe(false);
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('renders a formula error instead of the raw error object', () => {
        const wrapper = mountField({
            code: 'not_a_number',
            field_key: 'last_name',
        });

        expect(
            wrapper.find('[data-formula-error="not_a_number"]').exists(),
        ).toBe(true);
        expect(wrapper.text()).not.toContain('not_a_number');
    });

    it('marks the field as empty while no value has been calculated', () => {
        const wrapper = mountField(null);

        expect(wrapper.find('[data-computed-field-empty]').exists()).toBe(true);
    });

    it('writes a numeric result in the notation of the language', () => {
        const wrapper = mount(ComputedField, {
            props: {
                field: {
                    ...field,
                    config: { result_type: 'number', scale: 2 },
                },
                modelValue: '26638.85',
            },
        });

        expect(wrapper.text()).toContain('26.638,85');
    });
});
