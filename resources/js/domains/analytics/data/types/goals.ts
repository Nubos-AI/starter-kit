import { formatLocalizedDate } from '@/shared/lib/formatDate';
import type { TranslationParameters } from '@/shared/lib/i18n';
import { formatLocalizedNumber } from '@/shared/lib/localizedNumber';
import type { SelectOption } from '@/shared/types/ui';
import { parseAggregateValue } from './reports';

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
  user: 'i18n.types.goals.goal_scope_type_labels.user',
  team: 'i18n.types.goals.goal_scope_type_labels.team',
  tenant: 'i18n.types.goals.goal_scope_type_labels.tenant',
};

export const GOAL_PERIOD_TYPE_LABELS: Record<GoalPeriodType, string> = {
  month: 'i18n.types.goals.goal_period_type_labels.month',
  quarter: 'i18n.types.goals.goal_period_type_labels.quarter',
  year: 'i18n.types.goals.goal_period_type_labels.year',
};

export const GOAL_DIRECTION_LABELS: Record<GoalDirection, string> = {
  at_least: 'i18n.types.goals.goal_direction_labels.at_least',
  at_most: 'i18n.types.goals.goal_direction_labels.at_most',
};

export const GOAL_DIRECTION_HINTS: Record<GoalDirection, string> = {
  at_least: 'i18n.types.goals.goal_direction_hints.at_least',
  at_most: 'i18n.types.goals.goal_direction_hints.at_most',
};

export const GOAL_SCOPE_FIELD_DEFAULTS: Record<'user' | 'team', string> = {
  user: 'owner_id',
  team: 'team_id',
};

export const GOAL_PERIOD_SNAPSHOT_LABEL = 'i18n.types.goals.goal_period_snapshot_label';

export const GOAL_NOT_CALCULATED_LABEL = 'i18n.types.goals.goal_not_calculated_label';

const GOAL_ACTION_REFUSAL_MESSAGES: Record<string, string> = {
  not_owner: 'i18n.types.goals.goal_action_refusal_messages.not_owner',
  not_visible: 'i18n.types.goals.goal_action_refusal_messages.not_visible',
};

const GOAL_ACTION_REFUSAL_FALLBACK = 'i18n.types.goals.goal_action_refusal_fallback';

const GOAL_OPTION_REFUSAL_MESSAGES: Record<string, string> = {
  grouped_report: 'i18n.types.goals.goal_option_refusal_messages.grouped_report',
};

const GOAL_OPTION_REFUSAL_FALLBACK = 'i18n.types.goals.goal_option_refusal_fallback';

const GOAL_MONTHS_PER_QUARTER = 3;

const GOAL_VALUE_FORMAT: Intl.NumberFormatOptions = {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
};

const GOAL_MONTH_FORMAT: Intl.DateTimeFormatOptions = {
  month: 'long',
  year: 'numeric',
  timeZone: 'UTC',
};

interface GoalPeriodBounds {
  start: number;
  end: number;
}

function boundsOf(period: GoalPeriod): GoalPeriodBounds | null {
  const start = Date.parse(period.period_start);
  const end = Date.parse(period.period_end);

  return Number.isNaN(start) || Number.isNaN(end) ? null : { start, end };
}

export function currentGoalPeriod(goal: GoalListRow, now: number = Date.now()): GoalPeriod | null {
  return (
    goal.periods.find((period) => {
      const bounds = boundsOf(period);

      return bounds !== null && bounds.start <= now && now < bounds.end;
    }) ?? null
  );
}

export function pastGoalPeriods(goal: GoalListRow, now: number = Date.now()): GoalPeriod[] {
  return goal.periods.filter((period) => {
    const bounds = boundsOf(period);

    return bounds !== null && bounds.end <= now;
  });
}

export function resolveGoalProgress(goal: GoalListRow, now: number = Date.now()): GoalProgress | null {
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
  return formatLocalizedNumber(value, GOAL_VALUE_FORMAT);
}

export function formatGoalValue(value: string | null, t: (key: string) => string): string {
  const parsed = parseAggregateValue(value);

  return parsed === null ? t(GOAL_NOT_CALCULATED_LABEL) : formatGoalNumber(parsed);
}

export function formatGoalPeriodLabel(
  period: GoalPeriod,
  type: GoalPeriodType,
  t: (key: string, parameters?: TranslationParameters) => string,
): string {
  const start = new Date(period.period_start);

  if (Number.isNaN(start.getTime())) {
    return '—';
  }

  const year = start.getUTCFullYear();

  if (type === 'year') {
    return t('i18n.types.goals.goal_period_year', { value1: year });
  }

  if (type === 'quarter') {
    const quarter = Math.floor(start.getUTCMonth() / GOAL_MONTHS_PER_QUARTER) + 1;

    return t('i18n.types.goals.goal_period_quarter', {
      value1: quarter,
      value2: year,
    });
  }

  return formatLocalizedDate(start, GOAL_MONTH_FORMAT);
}

export function resolveGoalActionRefusal(reason: string | null, t: (key: string) => string): string | undefined {
  if (reason === null) {
    return undefined;
  }

  return t(GOAL_ACTION_REFUSAL_MESSAGES[reason] ?? GOAL_ACTION_REFUSAL_FALLBACK);
}

export function resolveGoalOptionRefusal(
  reason: string | null | undefined,
  t: (key: string) => string,
): string | undefined {
  if (reason === null || reason === undefined) {
    return undefined;
  }

  return t(GOAL_OPTION_REFUSAL_MESSAGES[reason] ?? GOAL_OPTION_REFUSAL_FALLBACK);
}
