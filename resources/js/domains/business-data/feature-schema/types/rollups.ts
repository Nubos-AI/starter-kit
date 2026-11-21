import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import { isFilterGroupNode } from '@/shared/types/filters';

export type RollupScope = 'direct_children' | 'subtree';

export interface RollupFieldConfig {
  aggregate?: string;
  relationship_type_id?: string;
  source_field_key?: string;
  scope?: RollupScope;
  filter?: FilterGroupNode;
}

export interface RollupTargetOption {
  value: string;
  label: string;
  target_object_type_id: string;
  has_hierarchy: boolean;
  fields: FieldDefinition[];
}

export const rollupScopeOptions = [
  {
    value: 'direct_children',
    label: 'i18n.types.rollups.rollup_scope_options.direct_children',
  },
  {
    value: 'subtree',
    label: 'i18n.types.rollups.rollup_scope_options.subtree',
  },
] as const satisfies ReadonlyArray<{ value: RollupScope; label: string }>;

export const rollupAggregateOptions = [
  { value: 'sum', label: 'i18n.types.rollups.rollup_aggregate_options.sum' },
  {
    value: 'count',
    label: 'i18n.types.rollups.rollup_aggregate_options.count',
  },
  { value: 'avg', label: 'i18n.types.rollups.rollup_aggregate_options.avg' },
  { value: 'min', label: 'i18n.types.rollups.rollup_aggregate_options.min' },
  { value: 'max', label: 'i18n.types.rollups.rollup_aggregate_options.max' },
] as const;

export function isRollupScope(value: unknown): value is RollupScope {
  if (typeof value !== 'string') {
    return false;
  }

  return rollupScopeOptions.some((option) => option.value === value);
}

export function toRollupFieldConfig(config: Record<string, unknown> | null | undefined): RollupFieldConfig | null {
  if (!config) {
    return null;
  }

  const rollupConfig: RollupFieldConfig = {};

  if (typeof config.aggregate === 'string') {
    rollupConfig.aggregate = config.aggregate;
  }

  if (typeof config.relationship_type_id === 'string') {
    rollupConfig.relationship_type_id = config.relationship_type_id;
  }

  if (typeof config.source_field_key === 'string') {
    rollupConfig.source_field_key = config.source_field_key;
  }

  if (isRollupScope(config.scope)) {
    rollupConfig.scope = config.scope;
  }

  if (isFilterGroupNode(config.filter)) {
    rollupConfig.filter = config.filter;
  }

  return rollupConfig;
}
