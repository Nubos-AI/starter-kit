import type { FieldDefaultValue, FieldValidationRules } from '@/shared/types/fieldEditor';
import type { FieldType } from '@/shared/types/fields';

export type FormulaResultType = 'number' | 'text' | 'date' | 'boolean';

export type FormulaErrorCode =
  | 'division_by_zero'
  | 'unknown_field_reference'
  | 'type_mismatch'
  | 'invalid_argument_count'
  | 'not_a_number'
  | 'invalid_date'
  | 'evaluation_limit_exceeded'
  | 'invalid_configuration';

export type BackfillStatus = 'pending' | 'running' | 'completed' | 'cancelled' | 'failed';

export interface FormulaErrorValue {
  code: FormulaErrorCode;
  field_key: string | null;
}

export interface FormulaFieldConfig {
  formula?: string | null;
  result_type?: FormulaResultType | null;
  [key: string]: unknown;
}

export interface ObjectTypeFieldRow {
  id: string;
  field_group_id: string | null;
  key: string;
  field_type: FieldType;
  label: string;
  description: string | null;
  is_required: boolean;
  is_unique: boolean;
  is_searchable: boolean;
  is_translatable: boolean;
  is_encrypted: boolean;
  is_sortable: boolean;
  is_filterable: boolean;
  is_default_column: boolean;
  is_card_field: boolean;
  is_reserved: boolean;
  is_type_changeable: boolean;
  list_position: number | null;
  config: FormulaFieldConfig | null;
  validation_rules: FieldValidationRules | null;
  default_value: FieldDefaultValue | null;
}

export const formulaErrorLabels = {
  division_by_zero: 'i18n.types.formulas.formula_error_labels.division_by_zero',
  unknown_field_reference: 'i18n.types.formulas.formula_error_labels.unknown_field_reference',
  type_mismatch: 'i18n.types.formulas.formula_error_labels.type_mismatch',
  invalid_argument_count: 'i18n.types.formulas.formula_error_labels.invalid_argument_count',
  not_a_number: 'i18n.types.formulas.formula_error_labels.not_a_number',
  invalid_date: 'i18n.types.formulas.formula_error_labels.invalid_date',
  evaluation_limit_exceeded: 'i18n.types.formulas.formula_error_labels.evaluation_limit_exceeded',
  invalid_configuration: 'i18n.types.formulas.formula_error_labels.invalid_configuration',
} as const satisfies Record<FormulaErrorCode, string>;

export const formulaResultTypeOptions: ReadonlyArray<{
  value: FormulaResultType;
  label: string;
}> = [
  {
    value: 'number',
    label: 'i18n.types.formulas.formula_result_type_options.number',
  },
  {
    value: 'text',
    label: 'i18n.types.formulas.formula_result_type_options.text',
  },
  {
    value: 'date',
    label: 'i18n.types.formulas.formula_result_type_options.date',
  },
  {
    value: 'boolean',
    label: 'i18n.types.formulas.formula_result_type_options.boolean',
  },
];

export const referenceableFieldTypes: ReadonlyArray<FieldType> = [
  'number',
  'decimal',
  'money',
  'rollup',
  'text_short',
  'text_long',
  'email',
  'phone',
  'url',
  'single_select',
  'date',
  'datetime',
  'boolean',
  'computed',
];

export function isFormulaErrorValue(value: unknown): value is FormulaErrorValue {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  if (!('code' in value) || !('field_key' in value)) {
    return false;
  }

  const { code, field_key: fieldKey } = value;

  return (
    typeof code === 'string' &&
    Object.hasOwn(formulaErrorLabels, code) &&
    (fieldKey === null || typeof fieldKey === 'string')
  );
}

export function referenceableFields(fields: ObjectTypeFieldRow[], currentKey: string | null): ObjectTypeFieldRow[] {
  return fields.filter((field) => {
    if (field.key === currentKey || field.is_encrypted) {
      return false;
    }

    if (!referenceableFieldTypes.includes(field.field_type)) {
      return false;
    }

    return field.field_type !== 'computed' || (field.config?.result_type ?? null) !== null;
  });
}
