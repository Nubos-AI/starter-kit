import { describe, expect, it } from 'vitest';
import { t } from '@/shared/testing/i18n';
import type { GoalPeriod } from './goals';
import { formatGoalPeriodLabel, resolveGoalActionRefusal } from './goals';

describe('resolveGoalActionRefusal', () => {
  it.each([
    ['not_owner', 'Nur die Person, die dieses Ziel angelegt hat, darf es ändern oder löschen.'],
    ['not_visible', 'Sie dürfen dieses Ziel nicht einsehen und deshalb auch nicht verwalten.'],
    ['unknown_reason', 'Dieses Ziel lässt sich mit Ihren Rechten nicht bearbeiten.'],
  ])('names the German refusal for the server reason %s', (reason, text) => {
    expect(resolveGoalActionRefusal(reason, t)).toBe(text);
  });

  it('names no refusal while the server sent no reason', () => {
    expect(resolveGoalActionRefusal(null, t)).toBeUndefined();
  });
});

describe('formatGoalPeriodLabel', () => {
  const period = (start: string): GoalPeriod => ({
    id: 'period',
    goal_id: 'goal',
    period_start: start,
    period_end: start,
    current_value: null,
    calculated_at: null,
  });

  it('names a yearly period through the catalogue', () => {
    expect(formatGoalPeriodLabel(period('2026-01-01'), 'year', t)).toBe('Jahr 2026');
  });

  it('takes the yearly wording from the catalogue key', () => {
    const asked: string[] = [];
    const recordingT = (key: string): string => {
      asked.push(key);

      return key;
    };

    formatGoalPeriodLabel(period('2026-01-01'), 'year', recordingT);

    expect(asked).toEqual(['i18n.types.goals.goal_period_year']);
  });

  it('names a quarterly period through the catalogue', () => {
    expect(formatGoalPeriodLabel(period('2026-07-01'), 'quarter', t)).toBe('Q3 2026');
  });

  it('falls back to a dash for an unreadable start', () => {
    expect(formatGoalPeriodLabel(period('nonsense'), 'year', t)).toBe('—');
  });
});
