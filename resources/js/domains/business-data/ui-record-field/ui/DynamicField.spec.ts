import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { FieldDefinition } from '@/shared/types/fields';
import DynamicField from './DynamicField.vue';

const cost: FieldDefinition = {
  key: 'cost',
  field_group_id: 'fg-pricing',
  field_type: 'money',
  label: 'Kosten',
  description: null,
  is_required: false,
  config: { currency: 'EUR' },
};

describe('DynamicField', () => {
  it('renders the widget of the field type under the label of the field definition with its currency', () => {
    const wrapper = mount(DynamicField, {
      props: { field: cost, modelValue: 12 },
    });

    expect(wrapper.get('label').text()).toBe('Kosten (EUR)');
    expect(wrapper.get('input').element.value).toBe('12,00');
  });

  it('lets an app name the field in its own words while keeping the currency', () => {
    const wrapper = mount(DynamicField, {
      props: { field: cost, modelValue: null, label: 'Einkaufspreis' },
    });

    expect(wrapper.get('label').text()).toBe('Einkaufspreis (EUR)');
  });

  it('reports a changed value and shows the error the server bound to the field', async () => {
    const wrapper = mount(DynamicField, {
      props: {
        field: { ...cost, field_type: 'text_short', config: {} },
        modelValue: '',
        error: 'Der Wert muss mindestens 0 sein.',
      },
    });

    await wrapper.get('input').setValue('7');

    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['7']);
    expect(wrapper.text()).toContain('Der Wert muss mindestens 0 sein.');
  });
});
