import type { DOMWrapper, VueWrapper } from '@vue/test-utils';

export const selectStubs = {
    Select: {
        props: ['modelValue', 'name'],
        emits: ['update:modelValue'],
        template:
            '<select class="ui-select" :name="name" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
    },
    SelectTrigger: {
        props: ['id'],
        template: '<span :id="id"><slot /></span>',
    },
    SelectValue: { render: () => null },
    SelectContent: { template: '<slot />' },
    SelectGroup: { template: '<slot />' },
    SelectLabel: {
        template: '<span data-select-group-label><slot /></span>',
    },
    SelectItem: {
        props: ['value', 'disabled'],
        template:
            '<option :value="value" :disabled="disabled"><slot /></option>',
    },
};

export const comboboxStubs = {
    Combobox: {
        props: {
            modelValue: null,
            options: null,
            id: null,
            disabled: null,
            serverSearch: Boolean,
        },
        emits: ['update:modelValue', 'search', 'loadMore'],
        inheritAttrs: false,
        template:
            '<span class="ui-combobox-wrapper">' +
            '<input v-if="serverSearch" class="ui-combobox-search" @input="$emit(\'search\', $event.target.value)">' +
            '<select v-bind="$attrs" class="ui-combobox" :id="id" :disabled="disabled" :value="modelValue" @scroll="$emit(\'loadMore\')" @change="$emit(\'update:modelValue\', $event.target.value)">' +
            '<option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>' +
            '</select></span>',
    },
};

export const multiSelectStubs = {
    MultiSelect: {
        props: ['modelValue', 'options', 'id', 'disabled', 'serverSearch'],
        emits: ['update:modelValue', 'search', 'loadMore'],
        template:
            '<span class="ui-multi-select-wrapper"><slot name="trigger" />' +
            '<input v-if="serverSearch" class="ui-multi-select-search" @input="$emit(\'search\', $event.target.value)">' +
            '<select class="ui-multi-select" multiple :id="id" :disabled="disabled" @scroll="$emit(\'loadMore\')" @change="$emit(\'update:modelValue\', Array.from($event.target.selectedOptions).map((option) => option.value))">' +
            '<option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled" :selected="modelValue.includes(option.value)">{{ option.label }}</option>' +
            '</select></span>',
    },
};

export function selectAt(
    wrapper: VueWrapper | ReturnType<VueWrapper['findComponent']>,
    index = 0,
): DOMWrapper<HTMLSelectElement> {
    return wrapper.findAll<HTMLSelectElement>('select.ui-select')[index];
}

export function optionValuesAt(
    wrapper: VueWrapper | ReturnType<VueWrapper['findComponent']>,
    index = 0,
): string[] {
    return selectAt(wrapper, index)
        .findAll('option')
        .map((option) => option.attributes('value') ?? '');
}

export function comboboxAt(
    wrapper: VueWrapper | ReturnType<VueWrapper['findComponent']>,
    index = 0,
): DOMWrapper<HTMLSelectElement> {
    return wrapper.findAll<HTMLSelectElement>('select.ui-combobox')[index];
}
