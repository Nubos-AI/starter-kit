import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GoalProgressBar from '@/components/charts/GoalProgressBar.vue';
import GoalProgressCard from '@/components/goals/GoalProgressCard.vue';
import type { GoalListRow, GoalPeriod } from '@/types/goals';

const NOW = '2026-08-11T12:00:00.000Z';

const GOAL_ID = '01GOAL00000000000000001A';

const REPORT_ID = '01REPORT000000000000001A';

const USER_ID = '01USER00000000000000001A';

const NOT_CALCULATED = 'noch nicht berechnet';

const LONE_NOUGHT = /\b0\b/;

function period(overrides: Partial<GoalPeriod> = {}): GoalPeriod {
    return {
        id: '01PERIOD0000000000000A01',
        goal_id: GOAL_ID,
        period_start: '2026-08-05T00:00:00.000Z',
        period_end: '2026-09-05T00:00:00.000Z',
        current_value: '4200.0000',
        calculated_at: '2026-08-11T10:00:00.000Z',
        ...overrides,
    };
}

function pastPeriod(overrides: Partial<GoalPeriod> = {}): GoalPeriod {
    return period({
        id: '01PERIOD0000000000000A02',
        period_start: '2026-07-05T00:00:00.000Z',
        period_end: '2026-08-05T00:00:00.000Z',
        current_value: '9100.0000',
        calculated_at: '2026-08-05T00:00:00.000Z',
        ...overrides,
    });
}

function futurePeriod(overrides: Partial<GoalPeriod> = {}): GoalPeriod {
    return period({
        id: '01PERIOD0000000000000A03',
        period_start: '2026-09-05T00:00:00.000Z',
        period_end: '2026-10-05T00:00:00.000Z',
        current_value: '7000.0000',
        calculated_at: '2026-09-05T00:00:00.000Z',
        ...overrides,
    });
}

function goalRow(overrides: Partial<GoalListRow> = {}): GoalListRow {
    return {
        id: GOAL_ID,
        name: 'Umsatz im Monat',
        report_id: REPORT_ID,
        report: {
            id: REPORT_ID,
            name: 'Umsatz gesamt',
            object_type_id: '01OBJECTTYPE00000000001A',
        },
        scope_type: 'user',
        target_user_id: USER_ID,
        target_team_id: null,
        target: {
            value: USER_ID,
            label: 'Anna Albers',
            description: 'anna@nubos.de',
            avatar: { name: 'Anna Albers' },
        },
        includes_subteams: false,
        scope_field_key: 'owner_id',
        period_field_key: null,
        period_type: 'month',
        direction: 'at_least',
        target_value: '10000.0000',
        periods: [period()],
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountCard(overrides: Partial<GoalListRow> = {}): VueWrapper {
    return mount(GoalProgressCard, { props: { goal: goalRow(overrides) } });
}

function stateOf(wrapper: VueWrapper): string | undefined {
    return wrapper.find('[data-goal-progress]').attributes('data-goal-state');
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(NOW));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('GoalProgressCard — reusing the shared bar', () => {
    it('hands the shared progress bar numbers parsed from the decimal strings', () => {
        const wrapper = mountCard();
        const bar = wrapper.findComponent(GoalProgressBar);

        expect(bar.exists()).toBe(true);
        expect(bar.props('current')).toBe(4200);
        expect(bar.props('target')).toBe(10000);
        expect(bar.props('direction')).toBe('at_least');
        expect(String(bar.props('label')).length).toBeGreaterThan(0);
    });

    it('builds no colour of its own', () => {
        expect(mountCard().html()).not.toMatch(/#[0-9a-fA-F]{3,8}|rgba?\(/);
    });
});

describe('GoalProgressCard — the two directions', () => {
    it('keeps a goal below its target in progress and calls it reached above', () => {
        const below = mountCard();
        const above = mountCard({
            periods: [period({ current_value: '12000.0000' })],
        });

        expect(stateOf(below)).toBe('in_progress');
        expect(stateOf(above)).toBe('reached');
    });

    it('flips the state once a ceiling is broken and stays calm below it', () => {
        const under = mountCard({
            direction: 'at_most',
            periods: [period({ current_value: '4200.0000' })],
        });
        const over = mountCard({
            direction: 'at_most',
            periods: [period({ current_value: '13000.0000' })],
        });

        expect(under.findComponent(GoalProgressBar).props('direction')).toBe(
            'at_most',
        );
        expect(stateOf(under)).toBe('within');
        expect(stateOf(over)).toBe('exceeded');
        expect(under.text()).not.toBe(over.text());
    });

    it('reads the same numbers differently depending on the direction', () => {
        const atLeast = mountCard({
            periods: [period({ current_value: '13000.0000' })],
        });
        const atMost = mountCard({
            direction: 'at_most',
            periods: [period({ current_value: '13000.0000' })],
        });

        expect(stateOf(atLeast)).not.toBe(stateOf(atMost));
    });
});

describe('GoalProgressCard — nothing calculated yet', () => {
    it('says so instead of showing a nought', () => {
        const withoutPeriod = mountCard({ periods: [] });
        const withoutValue = mountCard({
            periods: [period({ current_value: null })],
        });
        const withoutTimestamp = mountCard({
            periods: [period({ calculated_at: null })],
        });

        [withoutPeriod, withoutValue, withoutTimestamp].forEach((wrapper) => {
            expect(wrapper.text()).toContain(NOT_CALCULATED);
            expect(wrapper.findComponent(GoalProgressBar).exists()).toBe(false);
            expect(wrapper.text()).not.toMatch(LONE_NOUGHT);
        });
    });

    it('treats a value that is not a finite number as no reading at all', () => {
        const wrapper = mountCard({
            periods: [period({ current_value: 'nicht messbar' })],
        });

        expect(wrapper.text()).toContain(NOT_CALCULATED);
        expect(wrapper.findComponent(GoalProgressBar).exists()).toBe(false);
    });

    it('ignores a period that is already over and one that has not begun', () => {
        const wrapper = mountCard({
            periods: [futurePeriod(), pastPeriod()],
        });

        expect(wrapper.text()).toContain(NOT_CALCULATED);
        expect(wrapper.findComponent(GoalProgressBar).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('9100');
        expect(wrapper.text()).not.toContain('7000');
    });

    it('counts a period that ends exactly now as over', () => {
        const wrapper = mountCard({
            periods: [
                period({
                    period_start: '2026-07-11T12:00:00.000Z',
                    period_end: NOW,
                }),
            ],
        });

        expect(wrapper.text()).toContain(NOT_CALCULATED);
        expect(wrapper.findComponent(GoalProgressBar).exists()).toBe(false);
    });

    it('counts a period that starts exactly now as running', () => {
        const wrapper = mountCard({
            periods: [
                period({
                    period_start: NOW,
                    period_end: '2026-09-11T12:00:00.000Z',
                }),
            ],
        });

        expect(wrapper.findComponent(GoalProgressBar).exists()).toBe(true);
        expect(wrapper.text()).not.toContain(NOT_CALCULATED);
    });
});

describe('GoalProgressCard — the age of the reading', () => {
    it('states how old the reading is, taken from the server timestamp', () => {
        expect(mountCard().text()).toContain('vor 2 Stunden');
    });

    it('moves with the timestamp rather than with the moment of rendering', () => {
        const older = mountCard({
            periods: [period({ calculated_at: '2026-08-09T12:00:00.000Z' })],
        });

        expect(older.text()).not.toContain('vor 2 Stunden');
        expect(older.text()).toMatch(/vor 2 Tagen|vorgestern/);
    });
});
