import type { FilterGroupNode } from '@/composables/useFilterTree';

export const AGING_CLOCKS = ['updated_at', 'field'] as const;

export type AgingClock = (typeof AGING_CLOCKS)[number] | (string & {});

export const AGING_COLUMN_IDS = {
    age: 'aging_age',
    stage: 'aging_stage',
} as const;

export const AGING_THRESHOLD_COLORS = ['amber', 'red'] as const;

export type AgingThresholdColor = (typeof AGING_THRESHOLD_COLORS)[number];

export type AgingRuleRefusalReason = 'not_permitted';

export interface AgingThreshold {
    after_days: number;
    color: AgingThresholdColor;
}

export interface AgingRuleRow {
    id: string;
    object_type_id: string;
    name: string;
    clock: AgingClock;
    clock_field_key: string | null;
    condition: unknown;
    thresholds: AgingThreshold[];
    is_active: boolean;
    triggers_automation: boolean;
    can_update: boolean;
    can_delete: boolean;
    update_reason: AgingRuleRefusalReason | null;
    delete_reason: AgingRuleRefusalReason | null;
    updated_at: string | null;
}

export interface AgingRulePayload {
    name: string;
    clock: AgingClock;
    clock_field_key: string | null;
    condition: FilterGroupNode | null;
    thresholds: AgingThreshold[];
    is_active: boolean;
    triggers_automation: boolean;
}

export const AGING_CLOCK_LABELS: Record<AgingClock, string> = {
    updated_at: 'Letzte Änderung',
    field: 'Freies Datumsfeld',
};

export const AGING_RULE_REFUSAL_LABELS: Record<AgingRuleRefusalReason, string> =
    {
        not_permitted: 'Keine Berechtigung',
    };

export function sortThresholds(thresholds: AgingThreshold[]): AgingThreshold[] {
    return [...thresholds].sort(
        (left, right) => left.after_days - right.after_days,
    );
}

export function hasDuplicateDurations(thresholds: AgingThreshold[]): boolean {
    return (
        new Set(thresholds.map((threshold) => threshold.after_days)).size !==
        thresholds.length
    );
}

export function formatDurationInDays(days: number): string {
    if (!Number.isFinite(days) || days < 1) {
        return '< 1 Tag';
    }

    if (days < 2) {
        return '1 Tag';
    }

    return `${Math.floor(days)} Tage`;
}
