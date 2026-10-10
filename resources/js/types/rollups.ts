import type { FilterGroupNode } from '@/composables/useFilterTree';
import type { FieldDefinition } from '@/types/fields';

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
    { value: 'direct_children', label: 'Direct children only' },
    { value: 'subtree', label: 'Entire subtree' },
] as const satisfies ReadonlyArray<{ value: RollupScope; label: string }>;

export const rollupAggregateOptions = [
    { value: 'sum', label: 'Sum' },
    { value: 'count', label: 'Count' },
    { value: 'avg', label: 'Average' },
    { value: 'min', label: 'Minimum' },
    { value: 'max', label: 'Maximum' },
] as const;

export function isRollupScope(value: unknown): value is RollupScope {
    if (typeof value !== 'string') {
        return false;
    }

    return rollupScopeOptions.some((option) => option.value === value);
}

function isFilterGroupNode(value: unknown): value is FilterGroupNode {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    if (!('combinator' in value) || !('conditions' in value)) {
        return false;
    }

    const { combinator, conditions } = value;

    return (
        (combinator === 'and' || combinator === 'or') &&
        Array.isArray(conditions)
    );
}

export function toRollupFieldConfig(
    config: Record<string, unknown> | null | undefined,
): RollupFieldConfig | null {
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
