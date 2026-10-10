import type { FilterGroupNode } from '@/composables/useFilterTree';
import type { SelectOption } from '@/types/ui';

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
    'automation_runs',
    'aging_states',
] as const;

export type MergeTransferCategory = (typeof MERGE_TRANSFER_CATEGORIES)[number];

export const MERGE_RULE_OPTIONS = [
    'requires_reason',
    'requires_dedup_match',
    'inherits_external_reference',
    'blocks_on_running_automations',
] as const;

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
    allow: 'Erlaubt',
    deny: 'Verboten',
};

export const MERGE_FIELD_STRATEGY_LABELS: Record<MergeFieldStrategy, string> = {
    prefer_non_empty: 'Gefüllter Wert gewinnt',
    prefer_target: 'Ziel gewinnt',
    prefer_source: 'Quelle gewinnt',
    prefer_newest: 'Neuerer Datensatz gewinnt',
    prefer_oldest: 'Älterer Datensatz gewinnt',
    concatenate: 'Beide Werte aneinanderhängen',
    union: 'Werte vereinigen',
    sum: 'Werte summieren',
    max: 'Größeren Wert übernehmen',
    min: 'Kleineren Wert übernehmen',
    manual: 'Immer nachfragen',
};

export const MERGE_TRANSFER_POLICY_LABELS: Record<MergeTransferPolicy, string> =
    {
        move: 'Zum Ziel umhängen',
        keep: 'Bei der Quelle lassen',
        discard: 'Verwerfen',
    };

export const MERGE_TRANSFER_CATEGORY_LABELS: Record<
    MergeTransferCategory,
    string
> = {
    links: 'Verknüpfungen',
    attachments: 'Anhänge',
    notes: 'Notizen',
    watchers: 'Beobachter',
    reminders: 'Erinnerungen',
    timeline: 'Verlauf',
    audit: 'Protokoll',
    automation_runs: 'Automations-Läufe',
    aging_states: 'Aging-Zustand',
};

export const MERGE_RULE_OPTION_LABELS: Record<MergeRuleOption, string> = {
    requires_reason: 'Begründung ist Pflicht',
    requires_dedup_match: 'Nur bei übereinstimmendem Dublettenschlüssel',
    inherits_external_reference: 'Externe Referenz der Quelle erben',
    blocks_on_running_automations: 'Laufende Automationen blockieren',
};

export const MERGE_RULE_REFUSAL_LABELS: Record<MergeRuleRefusalReason, string> =
    {
        not_permitted: 'Keine Berechtigung',
    };

export const MERGE_VALUE_ORIGINS = [
    'target',
    'source',
    'combined',
    'undecided',
] as const;

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
    | 'running_automation'
    | 'rule_forbids'
    | 'stage_mismatch'
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
    same_record:
        'Ein Datensatz lässt sich nicht mit sich selbst zusammenführen.',
    different_object_type:
        'Die beiden Datensätze gehören zu verschiedenen Objekttypen.',
    different_tenant:
        'Die beiden Datensätze gehören zu verschiedenen Mandanten.',
    trashed: 'Mindestens einer der beiden Datensätze liegt im Papierkorb.',
    already_merged:
        'Mindestens einer der beiden Datensätze wurde bereits zusammengeführt.',
    system_object_type: 'Systemobjekttypen lassen sich nicht zusammenführen.',
    hierarchy_cycle:
        'Die beiden Datensätze stehen in derselben Hierarchie übereinander.',
    cardinality_conflict: 'Eine exklusive Beziehung lässt sich nicht auflösen.',
    stale_version:
        'Einer der Datensätze wurde zwischenzeitlich geändert. Bitte neu laden.',
    running_automation:
        'Für einen der Datensätze läuft gerade eine Automation.',
    rule_forbids: 'Eine Merge-Regel verbietet das Zusammenführen.',
    stage_mismatch: 'Die beiden Datensätze stehen in verschiedenen Stages.',
    dedup_mismatch: 'Die beiden Datensätze teilen keinen Dublettenschlüssel.',
    reason_required: 'Für dieses Zusammenführen wird eine Begründung verlangt.',
    decision_missing: 'Für mindestens ein Feld fehlt noch Ihre Entscheidung.',
    unique_field_conflict:
        'Das Ergebnis würde einen eindeutigen Wert doppelt belegen.',
};

export const MERGE_VALUE_ORIGIN_LABELS: Record<MergeValueOrigin, string> = {
    target: 'Ziel',
    source: 'Quelle',
    combined: 'Zusammengeführt',
    undecided: 'Offen',
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
}
