import type { DOMWrapper, VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GoalPeriodHistory from '@/components/goals/GoalPeriodHistory.vue';
import type { GoalListRow, GoalPeriod } from '@/types/goals';

const NOW = '2026-08-11T12:00:00.000Z';

const GOAL_ID = '01GOAL00000000000000001A';

const REPORT_ID = '01REPORT000000000000001A';

const USER_ID = '01USER00000000000000001A';

const NOT_CALCULATED = 'noch nicht berechnet';

const LONE_NOUGHT = /\b0\b/;

const DEFERRED_COMPARISON_WORDS = /vorperiode|vormonat|vorquartal|delta/i;

const NINE_THOUSAND_ONE_HUNDRED = /9[.,\s ]?100/;

const EIGHT_THOUSAND_EIGHT_HUNDRED = /8[.,\s ]?800/;

const FOUR_THOUSAND_TWO_HUNDRED = /4[.,\s ]?200/;

const RUNNING: GoalPeriod = {
    id: '01PERIOD0000000000000A00',
    goal_id: GOAL_ID,
    period_start: '2026-08-05T00:00:00.000Z',
    period_end: '2026-09-05T00:00:00.000Z',
    current_value: '4200.0000',
    calculated_at: '2026-08-11T10:00:00.000Z',
};

const JULY: GoalPeriod = {
    id: '01PERIOD0000000000000A01',
    goal_id: GOAL_ID,
    period_start: '2026-07-05T00:00:00.000Z',
    period_end: '2026-08-05T00:00:00.000Z',
    current_value: '9100.0000',
    calculated_at: '2026-08-05T00:00:00.000Z',
};

const JUNE: GoalPeriod = {
    id: '01PERIOD0000000000000A02',
    goal_id: GOAL_ID,
    period_start: '2026-06-05T00:00:00.000Z',
    period_end: '2026-07-05T00:00:00.000Z',
    current_value: '8800.0000',
    calculated_at: '2026-07-05T00:00:00.000Z',
};

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
        periods: [RUNNING, JULY, JUNE],
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountHistory(overrides: Partial<GoalListRow> = {}): VueWrapper {
    return mount(GoalPeriodHistory, { props: { goal: goalRow(overrides) } });
}

function rows(wrapper: VueWrapper): DOMWrapper<Element>[] {
    return wrapper.findAll('[data-goal-period-row]');
}

function labelOf(wrapper: VueWrapper, index: number): string {
    return rows(wrapper)[index].get('[data-goal-period-label]').text();
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(NOW));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('GoalPeriodHistory — which periods it shows', () => {
    it('leaves the running period out of the history', () => {
        const wrapper = mountHistory();

        expect(rows(wrapper)).toHaveLength(2);
        expect(wrapper.text()).not.toMatch(FOUR_THOUSAND_TWO_HUNDRED);
    });

    it('renders the past periods in the order the server delivered them', () => {
        const inServerOrder = mountHistory({
            periods: [RUNNING, JULY, JUNE],
        });
        const reversedByTheServer = mountHistory({
            periods: [RUNNING, JUNE, JULY],
        });

        expect(rows(inServerOrder)[0].text()).toMatch(
            NINE_THOUSAND_ONE_HUNDRED,
        );
        expect(rows(inServerOrder)[1].text()).toMatch(
            EIGHT_THOUSAND_EIGHT_HUNDRED,
        );
        expect(rows(reversedByTheServer)[0].text()).toMatch(
            EIGHT_THOUSAND_EIGHT_HUNDRED,
        );
        expect(rows(reversedByTheServer)[1].text()).toMatch(
            NINE_THOUSAND_ONE_HUNDRED,
        );
    });

    it('counts a period that ends exactly now as past', () => {
        const wrapper = mountHistory({
            periods: [
                {
                    ...JULY,
                    period_start: '2026-07-11T12:00:00.000Z',
                    period_end: NOW,
                },
            ],
        });

        expect(rows(wrapper)).toHaveLength(1);
    });

    it('shows an explanatory empty state instead of a table without history', () => {
        const wrapper = mountHistory({ periods: [RUNNING] });

        expect(rows(wrapper)).toHaveLength(0);
        expect(
            wrapper.get('[data-goal-period-empty]').text().length,
        ).toBeGreaterThan(20);
        expect(wrapper.text()).not.toMatch(FOUR_THOUSAND_TWO_HUNDRED);
    });
});

describe('GoalPeriodHistory — how a row reads', () => {
    it('labels a period according to the period type of the goal', () => {
        const monthly = labelOf(mountHistory({ period_type: 'month' }), 0);
        const quarterly = labelOf(mountHistory({ period_type: 'quarter' }), 0);
        const yearly = labelOf(mountHistory({ period_type: 'year' }), 0);

        expect(monthly).toContain('2026');
        expect(monthly).not.toContain('2026-07-05');
        expect(monthly).not.toContain('T00:00');
        expect(monthly).toMatch(/Juli|Jul|07|7/);
        expect(new Set([monthly, quarterly, yearly]).size).toBe(3);
    });

    it('formats the reading instead of printing the raw decimal string', () => {
        const wrapper = mountHistory();

        expect(rows(wrapper)[0].text()).not.toContain('9100.0000');
        expect(rows(wrapper)[0].text()).toMatch(NINE_THOUSAND_ONE_HUNDRED);
    });

    it('states when the reading of that period was taken', () => {
        const wrapper = mountHistory();

        expect(rows(wrapper)[0].text()).toContain('vor 6 Tagen');
    });

    it('says a period without a reading was never calculated rather than nought', () => {
        const wrapper = mountHistory({
            periods: [
                RUNNING,
                { ...JULY, current_value: null, calculated_at: null },
            ],
        });

        expect(rows(wrapper)).toHaveLength(1);
        expect(rows(wrapper)[0].text()).toContain(NOT_CALCULATED);
        expect(rows(wrapper)[0].text()).not.toMatch(LONE_NOUGHT);
    });
});

describe('GoalPeriodHistory — what it deliberately does not do', () => {
    it('compares nothing with the period before it', () => {
        const wrapper = mountHistory();

        expect(wrapper.text()).not.toMatch(DEFERRED_COMPARISON_WORDS);
        expect(wrapper.find('canvas').exists()).toBe(false);
    });
});
