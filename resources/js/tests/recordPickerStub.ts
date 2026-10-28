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
    template: '<div :id="id" data-record-picker />',
};

export const recordPickerStubs = { RecordPicker: RecordPickerStub };
