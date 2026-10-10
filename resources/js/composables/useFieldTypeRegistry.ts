import type { Component } from 'vue';
import BooleanField from '@/components/fields/BooleanField.vue';
import ComputedField from '@/components/fields/ComputedField.vue';
import MultiSelectField from '@/components/fields/MultiSelectField.vue';
import SelectField from '@/components/fields/SelectField.vue';
import TextareaField from '@/components/fields/TextareaField.vue';
import TextField from '@/components/fields/TextField.vue';
import type {
    FieldDefinition,
    FieldOption,
    FieldType,
    FieldValidationHints,
} from '@/types/fields';

export interface FieldWidget {
    component: Component;
    inputType?: string;
    step?: string;
}

export type OperatorArity = 'none' | 'single' | 'range';

export interface FilterOperator {
    label: string;
    value: string;
    arity: OperatorArity;
}

const operatorDefinitions: Record<string, FilterOperator> = {
    equals: { label: 'Ist gleich', value: 'equals', arity: 'single' },
    notEqual: { label: 'Ist ungleich', value: 'notEqual', arity: 'single' },
    contains: { label: 'Enthält', value: 'contains', arity: 'single' },
    notContains: {
        label: 'Enthält nicht',
        value: 'notContains',
        arity: 'single',
    },
    startsWith: { label: 'Beginnt mit', value: 'startsWith', arity: 'single' },
    endsWith: { label: 'Endet mit', value: 'endsWith', arity: 'single' },
    greaterThan: {
        label: 'Größer als',
        value: 'greaterThan',
        arity: 'single',
    },
    greaterThanOrEqual: {
        label: 'Größer oder gleich',
        value: 'greaterThanOrEqual',
        arity: 'single',
    },
    lessThan: { label: 'Kleiner als', value: 'lessThan', arity: 'single' },
    lessThanOrEqual: {
        label: 'Kleiner oder gleich',
        value: 'lessThanOrEqual',
        arity: 'single',
    },
    inRange: { label: 'Im Bereich', value: 'inRange', arity: 'range' },
    blank: { label: 'Ist leer', value: 'blank', arity: 'none' },
    notBlank: { label: 'Ist nicht leer', value: 'notBlank', arity: 'none' },
    in: { label: 'Ist eines von', value: 'in', arity: 'single' },
    notIn: { label: 'Ist keines von', value: 'notIn', arity: 'single' },
    has: { label: 'Hat', value: 'has', arity: 'single' },
    hasNot: { label: 'Hat nicht', value: 'hasNot', arity: 'single' },
};

const textOperators: readonly string[] = [
    'equals',
    'notEqual',
    'contains',
    'notContains',
    'startsWith',
    'endsWith',
    'in',
    'notIn',
    'blank',
    'notBlank',
];

const numericOperators: readonly string[] = [
    'equals',
    'notEqual',
    'greaterThan',
    'greaterThanOrEqual',
    'lessThan',
    'lessThanOrEqual',
    'inRange',
    'blank',
    'notBlank',
];

const dateOperators: readonly string[] = [
    'equals',
    'notEqual',
    'greaterThan',
    'lessThan',
    'inRange',
    'blank',
    'notBlank',
];

const selectOperators: readonly string[] = ['in', 'notIn', 'blank', 'notBlank'];

const relationOperators: readonly string[] = ['has', 'hasNot'];

const presenceOperators: readonly string[] = ['blank', 'notBlank'];

const booleanOperators: readonly string[] = ['equals', 'blank'];

const filterOperatorCatalog: Partial<Record<FieldType, readonly string[]>> = {
    text_short: textOperators,
    text_long: textOperators,
    email: textOperators,
    phone: textOperators,
    url: textOperators,
    number: numericOperators,
    decimal: numericOperators,
    money: numericOperators,
    rollup: numericOperators,
    date: dateOperators,
    datetime: dateOperators,
    boolean: booleanOperators,
    single_select: selectOperators,
    multi_select: selectOperators,
    relation_has_many: relationOperators,
    relation_many_to_many: relationOperators,
    file: presenceOperators,
    geo_address: presenceOperators,
};

export function filterOperatorsFor(type: FieldType): FilterOperator[] {
    const values = filterOperatorCatalog[type] ?? [];

    return values.map((value) => operatorDefinitions[value]);
}

const inputTypes: Partial<Record<FieldType, string>> = {
    number: 'number',
    decimal: 'number',
    money: 'number',
    date: 'date',
    datetime: 'datetime-local',
    email: 'email',
    phone: 'tel',
    url: 'url',
};

const numericSteps: Partial<Record<FieldType, string>> = {
    number: '1',
    decimal: 'any',
    money: '0.01',
};

export function fieldInputType(type: FieldType): string {
    return inputTypes[type] ?? 'text';
}

export function numericInputStep(type: FieldType): string | undefined {
    return numericSteps[type];
}

const widgetComponents: Partial<Record<FieldType, Component>> = {
    text_short: TextField,
    text_long: TextareaField,
    number: TextField,
    decimal: TextField,
    money: TextField,
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

        return {
            component,
            inputType: fieldInputType(type),
            step: numericInputStep(type),
        };
    }

    return { resolveWidget, filterOperatorsFor };
}

function numericBound(
    bound: number | string | null | undefined,
): number | null {
    if (bound === null || bound === undefined || bound === '') {
        return null;
    }

    const value = Number(bound);

    return Number.isNaN(value) ? null : value;
}

export function deriveValidationHints(
    field: FieldDefinition,
): FieldValidationHints {
    const hints: FieldValidationHints = { required: field.is_required };
    const rules = field.validation_rules;

    if (rules === null || rules === undefined) {
        return hints;
    }

    const min = numericBound(rules.min);
    const max = numericBound(rules.max);

    if (min !== null) {
        hints.min = min;
    }

    if (max !== null) {
        hints.max = max;
        hints.maxLength = max;
    }

    return hints;
}

export function normalizeFieldOptions(
    options: FieldConfigOptions,
): FieldOption[] {
    if (!Array.isArray(options)) {
        return [];
    }

    return options.map((option) => {
        if (typeof option === 'string') {
            return { value: option, label: option };
        }

        return option;
    });
}

type FieldConfigOptions = Array<FieldOption | string> | undefined;
