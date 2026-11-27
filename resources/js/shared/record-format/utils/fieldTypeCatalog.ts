import type { FieldDefinition, FieldOption, FieldType, FieldValidationHints } from '@/shared/types/fields';

export type OperatorArity = 'none' | 'single' | 'range';

export interface FilterOperator {
  label: string;
  value: string;
  arity: OperatorArity;
}

const operatorDefinitions: Record<string, FilterOperator> = {
  equals: {
    label: 'i18n.composables.use_field_type_registry.operator_equals',
    value: 'equals',
    arity: 'single',
  },
  notEqual: {
    label: 'i18n.composables.use_field_type_registry.operator_not_equal',
    value: 'notEqual',
    arity: 'single',
  },
  contains: {
    label: 'i18n.composables.use_field_type_registry.operator_contains',
    value: 'contains',
    arity: 'single',
  },
  notContains: {
    label: 'i18n.composables.use_field_type_registry.operator_not_contains',
    value: 'notContains',
    arity: 'single',
  },
  startsWith: {
    label: 'i18n.composables.use_field_type_registry.operator_starts_with',
    value: 'startsWith',
    arity: 'single',
  },
  endsWith: {
    label: 'i18n.composables.use_field_type_registry.operator_ends_with',
    value: 'endsWith',
    arity: 'single',
  },
  greaterThan: {
    label: 'i18n.composables.use_field_type_registry.operator_greater_than',
    value: 'greaterThan',
    arity: 'single',
  },
  greaterThanOrEqual: {
    label: 'i18n.composables.use_field_type_registry.operator_greater_than_or_equal',
    value: 'greaterThanOrEqual',
    arity: 'single',
  },
  lessThan: {
    label: 'i18n.composables.use_field_type_registry.operator_less_than',
    value: 'lessThan',
    arity: 'single',
  },
  lessThanOrEqual: {
    label: 'i18n.composables.use_field_type_registry.operator_less_than_or_equal',
    value: 'lessThanOrEqual',
    arity: 'single',
  },
  inRange: {
    label: 'i18n.composables.use_field_type_registry.operator_in_range',
    value: 'inRange',
    arity: 'range',
  },
  blank: {
    label: 'i18n.composables.use_field_type_registry.operator_blank',
    value: 'blank',
    arity: 'none',
  },
  notBlank: {
    label: 'i18n.composables.use_field_type_registry.operator_not_blank',
    value: 'notBlank',
    arity: 'none',
  },
  in: {
    label: 'i18n.composables.use_field_type_registry.operator_in',
    value: 'in',
    arity: 'single',
  },
  notIn: {
    label: 'i18n.composables.use_field_type_registry.operator_not_in',
    value: 'notIn',
    arity: 'single',
  },
  has: {
    label: 'i18n.composables.use_field_type_registry.operator_has',
    value: 'has',
    arity: 'single',
  },
  hasNot: {
    label: 'i18n.composables.use_field_type_registry.operator_has_not',
    value: 'hasNot',
    arity: 'single',
  },
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
  date: 'date',
  datetime: 'datetime-local',
  email: 'email',
  phone: 'tel',
  url: 'url',
};

export function fieldInputType(type: FieldType): string {
  return inputTypes[type] ?? 'text';
}

function numericBound(bound: number | string | null | undefined): number | null {
  if (bound === null || bound === undefined || bound === '') {
    return null;
  }

  const value = Number(bound);

  return Number.isNaN(value) ? null : value;
}

export function deriveValidationHints(field: FieldDefinition): FieldValidationHints {
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

export function normalizeFieldOptions(options: FieldConfigOptions): FieldOption[] {
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
