import { parseAggregateValue } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

export type GoalScopeType = 'user' | 'team' | 'tenant';

export type GoalPeriodType = 'month' | 'quarter' | 'year';

export type GoalDirection = 'at_least' | 'at_most';

export interface GoalReportRef {
    id: string;
    name: string;
    object_type_id: string;
}

export interface GoalPeriod {
    id: string;
    goal_id: string;
    period_start: string;
    period_end: string;
    current_value: string | null;
    calculated_at: string | null;
}

export interface GoalListRow {
    id: string;
    name: string;
    report_id: string;
    report: GoalReportRef | null;
    scope_type: GoalScopeType;
    target_user_id: string | null;
    target_team_id: string | null;
    target: SelectOption | null;
    includes_subteams: boolean;
    scope_field_key: string | null;
    period_field_key: string | null;
    period_type: GoalPeriodType;
    direction: GoalDirection;
    target_value: string;
    periods: GoalPeriod[];
    can_update: boolean;
    can_delete: boolean;
    update_reason: string | null;
    delete_reason: string | null;
    updated_at: string | null;
}

export interface GoalProgress {
    current: number;
    target: number;
    calculatedAt: string;
}

export const GOAL_SCOPE_TYPE_LABELS: Record<GoalScopeType, string> = {
    user: 'Nutzer',
    team: 'Team',
    tenant: 'Mandant',
};

export const GOAL_PERIOD_TYPE_LABELS: Record<GoalPeriodType, string> = {
    month: 'Monat',
    quarter: 'Quartal',
    year: 'Jahr',
};

export const GOAL_DIRECTION_LABELS: Record<GoalDirection, string> = {
    at_least: 'mindestens erreichen',
    at_most: 'höchstens überschreiten',
};

export const GOAL_DIRECTION_HINTS: Record<GoalDirection, string> = {
    at_least:
        'Der Ist-Stand soll den Zielwert erreichen oder übertreffen. Je näher er dem Zielwert kommt, desto besser steht das Ziel da.',
    at_most:
        'Der Ist-Stand soll den Zielwert nicht überschreiten. Oberhalb des Zielwerts schlägt die Anzeige um, weil das Ziel dann verfehlt ist.',
};

export const GOAL_SCOPE_FIELD_DEFAULTS: Record<'user' | 'team', string> = {
    user: 'owner_id',
    team: 'team_id',
};

export const GOAL_PERIOD_SNAPSHOT_LABEL = 'Momentaufnahme (kein Datumsfeld)';

export const GOAL_NOT_CALCULATED_LABEL = 'noch nicht berechnet';

const GOAL_ACTION_REFUSAL_MESSAGES: Record<string, string> = {
    not_owner:
        'Nur die Person, die dieses Ziel angelegt hat, darf es ändern oder löschen.',
    not_visible:
        'Sie dürfen dieses Ziel nicht einsehen und deshalb auch nicht verwalten.',
};

const GOAL_ACTION_REFUSAL_FALLBACK =
    'Dieses Ziel lässt sich mit Ihren Rechten nicht bearbeiten.';

const GOAL_OPTION_REFUSAL_MESSAGES: Record<string, string> = {
    grouped_report:
        'Diese Auswertung ist gruppiert und liefert deshalb keine einzelne Kennzahl, auf die sich ein Ziel beziehen könnte.',
};

const GOAL_OPTION_REFUSAL_FALLBACK =
    'Diese Auswertung eignet sich nicht als Quelle für ein Ziel.';

const GOAL_MONTHS_PER_QUARTER = 3;

const goalValueFormatter = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
});

const goalMonthFormatter = new Intl.DateTimeFormat('de-DE', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});

interface GoalPeriodBounds {
    start: number;
    end: number;
}

function boundsOf(period: GoalPeriod): GoalPeriodBounds | null {
    const start = Date.parse(period.period_start);
    const end = Date.parse(period.period_end);

    return Number.isNaN(start) || Number.isNaN(end) ? null : { start, end };
}

export function currentGoalPeriod(
    goal: GoalListRow,
    now: number = Date.now(),
): GoalPeriod | null {
    return (
        goal.periods.find((period) => {
            const bounds = boundsOf(period);

            return bounds !== null && bounds.start <= now && now < bounds.end;
        }) ?? null
    );
}

export function pastGoalPeriods(
    goal: GoalListRow,
    now: number = Date.now(),
): GoalPeriod[] {
    return goal.periods.filter((period) => {
        const bounds = boundsOf(period);

        return bounds !== null && bounds.end <= now;
    });
}

export function resolveGoalProgress(
    goal: GoalListRow,
    now: number = Date.now(),
): GoalProgress | null {
    const period = currentGoalPeriod(goal, now);

    if (period === null || period.calculated_at === null) {
        return null;
    }

    const current = parseAggregateValue(period.current_value);
    const target = parseAggregateValue(goal.target_value);

    if (current === null || target === null) {
        return null;
    }

    return { current, target, calculatedAt: period.calculated_at };
}

export function formatGoalNumber(value: number): string {
    return goalValueFormatter.format(value);
}

export function formatGoalValue(value: string | null): string {
    const parsed = parseAggregateValue(value);

    return parsed === null
        ? GOAL_NOT_CALCULATED_LABEL
        : formatGoalNumber(parsed);
}

export function formatGoalPeriodLabel(
    period: GoalPeriod,
    type: GoalPeriodType,
): string {
    const start = new Date(period.period_start);

    if (Number.isNaN(start.getTime())) {
        return '—';
    }

    const year = start.getUTCFullYear();

    if (type === 'year') {
        return `Jahr ${year}`;
    }

    if (type === 'quarter') {
        const quarter =
            Math.floor(start.getUTCMonth() / GOAL_MONTHS_PER_QUARTER) + 1;

        return `Q${quarter} ${year}`;
    }

    return goalMonthFormatter.format(start);
}

export function resolveGoalActionRefusal(
    reason: string | null,
): string | undefined {
    if (reason === null) {
        return undefined;
    }

    return GOAL_ACTION_REFUSAL_MESSAGES[reason] ?? GOAL_ACTION_REFUSAL_FALLBACK;
}

export function resolveGoalOptionRefusal(
    reason: string | null | undefined,
): string | undefined {
    if (reason === null || reason === undefined) {
        return undefined;
    }

    return GOAL_OPTION_REFUSAL_MESSAGES[reason] ?? GOAL_OPTION_REFUSAL_FALLBACK;
}
