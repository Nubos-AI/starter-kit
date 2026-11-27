import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';

export const MERGE_RULE_MODES = ['allow', 'deny'] as const;

export type MergeRuleMode = (typeof MERGE_RULE_MODES)[number];

export const MERGE_FIELD_STRATEGIES = [
  'prefer_non_empty',
  'prefer_target',
  'prefer_source',
  'prefer_newest',
  'prefer_oldest',
  'concatenate',
  'union',
  'sum',
  'max',
  'min',
  'manual',
] as const;

export type MergeFieldStrategy = (typeof MERGE_FIELD_STRATEGIES)[number];

export const MERGE_TRANSFER_POLICIES = ['move', 'keep', 'discard'] as const;

export type MergeTransferPolicy = (typeof MERGE_TRANSFER_POLICIES)[number];

export const MERGE_TRANSFER_CATEGORIES = [
  'links',
  'attachments',
  'notes',
  'watchers',
  'reminders',
  'timeline',
  'audit',
] as const;

export type CoreMergeTransferCategory = (typeof MERGE_TRANSFER_CATEGORIES)[number];

export type MergeTransferCategory = CoreMergeTransferCategory | (string & {});

export const MERGE_RULE_OPTIONS = ['requires_reason', 'requires_dedup_match', 'inherits_external_reference'] as const;

export type MergeRuleOption = string;

export type MergeRuleRefusalReason = 'not_permitted';

export interface MergeFieldStrategyOption {
  key: string;
  label: string;
  field_type: string;
  strategies: MergeFieldStrategy[];
}

export interface MergeTransferCategoryOption {
  value: MergeTransferCategory;
  default_policy: MergeTransferPolicy;
  policies: MergeTransferPolicy[];
}

export interface MergeRuleRow {
  id: string;
  object_type_id: string;
  name: string;
  mode: MergeRuleMode;
  position: number;
  is_active: boolean;
  deny_reason: string | null;
  condition: unknown;
  field_strategies: Record<string, MergeFieldStrategy>;
  transfer_policy: Record<string, MergeTransferPolicy>;
  options: Partial<Record<MergeRuleOption, boolean>>;
  can_update: boolean;
  can_delete: boolean;
  update_reason: MergeRuleRefusalReason | null;
  delete_reason: MergeRuleRefusalReason | null;
  updated_at: string | null;
}

export interface MergeRulePayload {
  name: string;
  mode: MergeRuleMode;
  position: number;
  is_active: boolean;
  deny_reason: string | null;
  condition: FilterGroupNode | null;
  field_strategies: Record<string, MergeFieldStrategy>;
  transfer_policy: Record<string, MergeTransferPolicy>;
  options: Partial<Record<MergeRuleOption, boolean>>;
}

export const MERGE_RULE_MODE_LABELS: Record<MergeRuleMode, string> = {
  allow: 'i18n.types.merge.merge_rule_mode.allow',
  deny: 'i18n.types.merge.merge_rule_mode.deny',
};

export const MERGE_FIELD_STRATEGY_LABELS: Record<MergeFieldStrategy, string> = {
  prefer_non_empty: 'i18n.types.merge.merge_field_strategy.prefer_non_empty',
  prefer_target: 'i18n.types.merge.merge_field_strategy.prefer_target',
  prefer_source: 'i18n.types.merge.merge_field_strategy.prefer_source',
  prefer_newest: 'i18n.types.merge.merge_field_strategy.prefer_newest',
  prefer_oldest: 'i18n.types.merge.merge_field_strategy.prefer_oldest',
  concatenate: 'i18n.types.merge.merge_field_strategy.concatenate',
  union: 'i18n.types.merge.merge_field_strategy.union',
  sum: 'i18n.types.merge.merge_field_strategy.sum',
  max: 'i18n.types.merge.merge_field_strategy.max',
  min: 'i18n.types.merge.merge_field_strategy.min',
  manual: 'i18n.types.merge.merge_field_strategy.manual',
};

export const MERGE_TRANSFER_POLICY_LABELS: Record<MergeTransferPolicy, string> = {
  move: 'i18n.types.merge.merge_transfer_policy.move',
  keep: 'i18n.types.merge.merge_transfer_policy.keep',
  discard: 'i18n.types.merge.merge_transfer_policy.discard',
};

export const MERGE_TRANSFER_CATEGORY_LABELS: Record<CoreMergeTransferCategory, string> = {
  links: 'i18n.types.merge.merge_transfer_category.links',
  attachments: 'i18n.types.merge.merge_transfer_category.attachments',
  notes: 'i18n.types.merge.merge_transfer_category.notes',
  watchers: 'i18n.types.merge.merge_transfer_category.watchers',
  reminders: 'i18n.types.merge.merge_transfer_category.reminders',
  timeline: 'i18n.types.merge.merge_transfer_category.timeline',
  audit: 'i18n.types.merge.merge_transfer_category.audit',
};

export const MERGE_RULE_OPTION_LABELS: Record<MergeRuleOption, string> = {
  requires_reason: 'i18n.types.merge.merge_rule_option.requires_reason',
  requires_dedup_match: 'i18n.types.merge.merge_rule_option.requires_dedup_match',
  inherits_external_reference: 'i18n.types.merge.merge_rule_option.inherits_external_reference',
};

export const MERGE_RULE_REFUSAL_LABELS: Record<MergeRuleRefusalReason, string> = {
  not_permitted: 'i18n.types.merge.merge_rule_refusal.not_permitted',
};

export const MERGE_VALUE_ORIGINS = ['target', 'source', 'combined', 'undecided'] as const;

export type MergeValueOrigin = (typeof MERGE_VALUE_ORIGINS)[number];

export type MergeBlockReason =
  | 'same_record'
  | 'different_object_type'
  | 'different_tenant'
  | 'trashed'
  | 'already_merged'
  | 'system_object_type'
  | 'hierarchy_cycle'
  | 'cardinality_conflict'
  | 'stale_version'
  | 'rule_forbids'
  | 'dedup_mismatch'
  | 'reason_required'
  | 'decision_missing'
  | 'unique_field_conflict';

export interface MergeBlocker {
  reason: MergeBlockReason;
  detail: string | null;
}

export interface MergeFieldPlan {
  key: string;
  label: string;
  field_type: string;
  strategy: MergeFieldStrategy;
  target_value: unknown;
  source_value: unknown;
  result_value: unknown;
  origin: MergeValueOrigin;
  is_conflict: boolean;
  requires_decision: boolean;
  is_overridden: boolean;
}

export interface MergeTransferPlan {
  category: MergeTransferCategory;
  policy: MergeTransferPolicy;
  count: number;
}

export interface MergePlan {
  target_id: string;
  source_id: string;
  target_version: number;
  source_version: number;
  mode: MergeRuleMode;
  rule_id: string | null;
  rule_name: string | null;
  deny_reason: string | null;
  requires_reason: boolean;
  is_mergeable: boolean;
  fields: MergeFieldPlan[];
  transfers: MergeTransferPlan[];
  blockers: MergeBlocker[];
}

export interface MergeRequestPayload {
  targetId: string;
  sourceId: string;
  targetVersion?: number;
  sourceVersion?: number;
  reason?: string | null;
  overrides?: Record<string, 'target' | 'source'>;
}

export interface MergeCandidates {
  suggested_ids: string[];
  options: SelectOption[];
  is_truncated: boolean;
}

export const MERGE_BLOCK_REASON_LABELS: Record<MergeBlockReason, string> = {
  same_record: 'i18n.types.merge.merge_block_reason.same_record',
  different_object_type: 'i18n.types.merge.merge_block_reason.different_object_type',
  different_tenant: 'i18n.types.merge.merge_block_reason.different_tenant',
  trashed: 'i18n.types.merge.merge_block_reason.trashed',
  already_merged: 'i18n.types.merge.merge_block_reason.already_merged',
  system_object_type: 'i18n.types.merge.merge_block_reason.system_object_type',
  hierarchy_cycle: 'i18n.types.merge.merge_block_reason.hierarchy_cycle',
  cardinality_conflict: 'i18n.types.merge.merge_block_reason.cardinality_conflict',
  stale_version: 'i18n.types.merge.merge_block_reason.stale_version',
  rule_forbids: 'i18n.types.merge.merge_block_reason.rule_forbids',
  dedup_mismatch: 'i18n.types.merge.merge_block_reason.dedup_mismatch',
  reason_required: 'i18n.types.merge.merge_block_reason.reason_required',
  decision_missing: 'i18n.types.merge.merge_block_reason.decision_missing',
  unique_field_conflict: 'i18n.types.merge.merge_block_reason.unique_field_conflict',
};

export const MERGE_VALUE_ORIGIN_LABELS: Record<MergeValueOrigin, string> = {
  target: 'i18n.types.merge.merge_value_origin.target',
  source: 'i18n.types.merge.merge_value_origin.source',
  combined: 'i18n.types.merge.merge_value_origin.combined',
  undecided: 'i18n.types.merge.merge_value_origin.undecided',
};

export interface UndoableMerge {
  id: string;
  sourceId: string;
  mergedAt: string | null;
  undoableUntil: string | null;
}

export interface MergeRuleObjectType {
  slug: string;
  name: string;
  label: string;
}
