import { sharedModuleOptions } from '@/shared/lib/modules';

export const RELATION_CARDINALITY_LABELS = {
  one_to_many: 'i18n.lib.system_value_labels.relation_cardinality.one_to_many',
  many_to_many: 'i18n.lib.system_value_labels.relation_cardinality.many_to_many',
} as const;

export const STORAGE_STRATEGY_LABELS = {
  native: 'i18n.lib.system_value_labels.storage_strategy.native',
  generic: 'i18n.lib.system_value_labels.storage_strategy.generic',
} as const;

export const SYSTEM_ROLE_LABELS = {
  owner: 'i18n.lib.system_value_labels.system_role.owner',
  admin: 'i18n.lib.system_value_labels.system_role.admin',
  member: 'i18n.lib.system_value_labels.system_role.member',
} as const;

export const SYSTEM_FIELD_LABELS = {
  owner_id: 'i18n.lib.system_value_labels.system_field.owner_id',
  team_id: 'i18n.lib.system_value_labels.system_field.team_id',
  object_type_id: 'i18n.lib.system_value_labels.system_field.object_type_id',
  business_key: 'i18n.lib.system_value_labels.system_field.business_key',
  deleted_at: 'i18n.lib.system_value_labels.system_field.deleted_at',
  created_by_id: 'i18n.lib.system_value_labels.system_field.created_by_id',
  updated_by_id: 'i18n.lib.system_value_labels.system_field.updated_by_id',
  merged_into_id: 'i18n.lib.system_value_labels.system_field.merged_into_id',
  current_visibility: 'i18n.lib.system_value_labels.system_field.current_visibility',
  aging_age: 'i18n.lib.system_value_labels.system_field.aging_age',
  aging_stage: 'i18n.lib.system_value_labels.system_field.aging_stage',
} as const;

function labelOf(map: Record<string, string>, value: string): string {
  return map[value] ?? value;
}

export function relationCardinalityLabel(value: string): string {
  return labelOf(RELATION_CARDINALITY_LABELS, value);
}

export function storageStrategyLabel(value: string): string {
  return labelOf(STORAGE_STRATEGY_LABELS, value);
}

export function systemRoleLabel(name: string, isSystem: boolean): string {
  return isSystem ? labelOf(SYSTEM_ROLE_LABELS, name) : name;
}

export function systemFieldLabel(key: string): string | null {
  return SYSTEM_FIELD_LABELS[key as keyof typeof SYSTEM_FIELD_LABELS] ?? null;
}

export function moduleSystemFieldLabel(key: string): string | null {
  return sharedModuleOptions('records.system-fields').find((option) => option.value === key)?.label ?? null;
}
