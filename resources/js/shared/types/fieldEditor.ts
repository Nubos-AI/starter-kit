import type { FieldType } from '@/shared/types/fields';

export type FieldDefaultKind = 'none' | 'static' | 'dynamic';

export type FieldDefaultSource = 'today' | 'now' | 'current_user' | 'sequence';

export type FieldCrossOperator =
  | 'after'
  | 'after_or_equal'
  | 'before'
  | 'before_or_equal'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'same'
  | 'different';

export interface FieldDefaultValue {
  kind?: FieldDefaultKind | null;
  value?: unknown;
  source?: FieldDefaultSource | null;
}

export interface FieldValidationRules {
  regex?: string | null;
  min?: number | string | null;
  max?: number | string | null;
  in?: string[] | null;
  cross?: Partial<Record<FieldCrossOperator, string>> | null;
}

export const selectFieldTypes: ReadonlyArray<FieldType> = ['single_select', 'multi_select'];

export const relationFieldTypes: ReadonlyArray<FieldType> = ['relation_has_many', 'relation_many_to_many'];

export const textualFieldTypes: ReadonlyArray<FieldType> = ['text_short', 'text_long', 'email', 'phone', 'url'];

export const numericFieldTypes: ReadonlyArray<FieldType> = ['number', 'decimal', 'money'];

export const temporalFieldTypes: ReadonlyArray<FieldType> = ['date', 'datetime'];

const defaultlessFieldTypes: ReadonlyArray<FieldType> = [
  'computed',
  'rollup',
  'file',
  'geo_address',
  ...relationFieldTypes,
];

export const fieldDefaultKindOptions: ReadonlyArray<{
  value: FieldDefaultKind;
  label: string;
}> = [
  {
    value: 'none',
    label: 'i18n.types.field_editor.field_default_kind_options.none',
  },
  {
    value: 'static',
    label: 'i18n.types.field_editor.field_default_kind_options.static',
  },
  {
    value: 'dynamic',
    label: 'i18n.types.field_editor.field_default_kind_options.dynamic',
  },
];

export const fieldDefaultSourceOptions: ReadonlyArray<{
  value: FieldDefaultSource;
  label: string;
}> = [
  {
    value: 'today',
    label: 'i18n.types.field_editor.field_default_source_options.today',
  },
  {
    value: 'now',
    label: 'i18n.types.field_editor.field_default_source_options.now',
  },
  {
    value: 'current_user',
    label: 'i18n.types.field_editor.field_default_source_options.current_user',
  },
  {
    value: 'sequence',
    label: 'i18n.types.field_editor.field_default_source_options.sequence',
  },
];

export const fieldCrossOperatorOptions: ReadonlyArray<{
  value: FieldCrossOperator;
  label: string;
}> = [
  {
    value: 'after',
    label: 'i18n.types.field_editor.field_cross_operator_options.after',
  },
  {
    value: 'after_or_equal',
    label: 'i18n.types.field_editor.field_cross_operator_options.after_or_equal',
  },
  {
    value: 'before',
    label: 'i18n.types.field_editor.field_cross_operator_options.before',
  },
  {
    value: 'before_or_equal',
    label: 'i18n.types.field_editor.field_cross_operator_options.before_or_equal',
  },
  {
    value: 'gt',
    label: 'i18n.types.field_editor.field_cross_operator_options.gt',
  },
  {
    value: 'gte',
    label: 'i18n.types.field_editor.field_cross_operator_options.gte',
  },
  {
    value: 'lt',
    label: 'i18n.types.field_editor.field_cross_operator_options.lt',
  },
  {
    value: 'lte',
    label: 'i18n.types.field_editor.field_cross_operator_options.lte',
  },
  {
    value: 'same',
    label: 'i18n.types.field_editor.field_cross_operator_options.same',
  },
  {
    value: 'different',
    label: 'i18n.types.field_editor.field_cross_operator_options.different',
  },
];

export function supportsOptions(fieldType: FieldType): boolean {
  return selectFieldTypes.includes(fieldType);
}

export function supportsRelationTarget(fieldType: FieldType): boolean {
  return relationFieldTypes.includes(fieldType);
}

export function supportsDefaultValue(fieldType: FieldType): boolean {
  return !defaultlessFieldTypes.includes(fieldType);
}

export function supportsValidationRules(fieldType: FieldType): boolean {
  return (
    textualFieldTypes.includes(fieldType) ||
    numericFieldTypes.includes(fieldType) ||
    temporalFieldTypes.includes(fieldType)
  );
}

export function isTemporalField(fieldType: FieldType): boolean {
  return temporalFieldTypes.includes(fieldType);
}

export function isNumericField(fieldType: FieldType): boolean {
  return numericFieldTypes.includes(fieldType);
}

export function toFieldOptions(config: unknown): string[] {
  if (typeof config !== 'object' || config === null) {
    return [];
  }

  const raw = (config as { options?: unknown }).options;

  if (!Array.isArray(raw)) {
    return [];
  }

  return raw.filter((option): option is string => typeof option === 'string');
}

export function toRelationshipTypeId(config: unknown): string {
  if (typeof config !== 'object' || config === null) {
    return '';
  }

  const raw = (config as { relationship_type_id?: unknown }).relationship_type_id;

  return typeof raw === 'string' ? raw : '';
}

export function toLookupObjectType(config: unknown): string {
  if (typeof config !== 'object' || config === null) {
    return '';
  }

  const raw = (config as { lookup_object_type?: unknown }).lookup_object_type;

  return typeof raw === 'string' ? raw : '';
}
