import type { Component } from 'vue';
import { fieldInputType, filterOperatorsFor } from '@/shared/record-format/utils/fieldTypeCatalog';
import type { FieldType } from '@/shared/types/fields';
import BooleanField from '../ui/BooleanField.vue';
import ComputedField from '../ui/ComputedField.vue';
import MultiSelectField from '../ui/MultiSelectField.vue';
import NumberField from '../ui/NumberField.vue';
import SelectField from '../ui/SelectField.vue';
import TextareaField from '../ui/TextareaField.vue';
import TextField from '../ui/TextField.vue';

export interface FieldWidget {
  component: Component;
  inputType?: string;
}

const widgetComponents: Partial<Record<FieldType, Component>> = {
  text_short: TextField,
  text_long: TextareaField,
  number: NumberField,
  decimal: NumberField,
  money: NumberField,
  date: TextField,
  datetime: TextField,
  boolean: BooleanField,
  single_select: SelectField,
  multi_select: MultiSelectField,
  email: TextField,
  phone: TextField,
  url: TextField,
  computed: ComputedField,
  rollup: ComputedField,
};

const typelessWidgets: ReadonlyArray<FieldType> = [
  'number',
  'decimal',
  'money',
  'computed',
  'rollup',
  'text_long',
  'boolean',
  'single_select',
  'multi_select',
];

export function useFieldTypeRegistry() {
  function resolveWidget(type: FieldType): FieldWidget | null {
    const component = widgetComponents[type];

    if (component === undefined) {
      return null;
    }

    if (typelessWidgets.includes(type)) {
      return { component };
    }

    return { component, inputType: fieldInputType(type) };
  }

  return { resolveWidget, filterOperatorsFor };
}
