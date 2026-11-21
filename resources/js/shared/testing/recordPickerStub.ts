import type { Component } from 'vue';

export const RecordPickerStub: Component = {
  name: 'RecordPicker',
  props: {
    modelValue: { type: [String, null], default: null },
    objectTypeSlug: { type: [String, null], default: null },
    id: { type: String, default: undefined },
    placeholder: { type: String, default: undefined },
  },
  emits: ['update:modelValue'],
  template:
    '<input data-record-picker :id="id" :data-object-type="objectTypeSlug" :placeholder="placeholder" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

export const recordPickerStubs = { RecordPicker: RecordPickerStub };
