import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import FieldTypePicker from '@/components/engine/objectType/FieldTypePicker.vue';
import type { FieldTypeOption } from '@/types/fields';

function option(
    value: string,
    label: string,
    category: string,
    categoryLabel: string,
    categoryPosition: number,
): FieldTypeOption {
    return {
        value,
        label,
        description: `${label} erklärt sich so.`,
        category,
        categoryLabel,
        categoryPosition,
    } as FieldTypeOption;
}

const fieldTypes: FieldTypeOption[] = [
    option('computed', 'Formel', 'calculated', 'Berechnet', 6),
    option('text_short', 'Kurzer Text', 'text', 'Text', 1),
    option('rollup', 'Aggregation', 'calculated', 'Berechnet', 6),
    option('number', 'Ganzzahl', 'number', 'Zahlen', 2),
];

function mountPicker() {
    return mount(FieldTypePicker, { props: { fieldTypes } });
}

describe('FieldTypePicker', () => {
    it('groups the types by category in category order', () => {
        const groups = mountPicker()
            .findAll('[data-field-type-group]')
            .map((group) => group.attributes('data-field-type-group'));

        expect(groups).toEqual(['text', 'number', 'calculated']);
    });

    it('shows both calculated types under one heading', () => {
        const calculated = mountPicker().get(
            '[data-field-type-group="calculated"]',
        );

        expect(calculated.text()).toContain('Berechnet');
        expect(calculated.text()).toContain('Formel');
        expect(calculated.text()).toContain('Aggregation');
    });

    it('explains every type it offers', () => {
        const tile = mountPicker().get('[data-field-type-option="computed"]');

        expect(tile.text()).toContain('Formel erklärt sich so.');
    });

    it('reports the type the user picks', async () => {
        const wrapper = mountPicker();

        await wrapper.get('[data-field-type-option="rollup"]').trigger('click');

        expect(wrapper.emitted('select')).toEqual([['rollup']]);
    });
});
