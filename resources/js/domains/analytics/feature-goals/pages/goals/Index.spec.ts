import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import { recordOf } from '@/shared/lib/guards';
import {
  activateRow,
  gridColumnByHeader,
  gridOf,
  gridRows,
  rowAction,
  rowActivation,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import type { GoalListRow, GoalPeriod } from '../../../data/types/goals';
import GoalProgressBar from '../../../ui-goals/ui/GoalProgressBar.vue';
import Index from './Index.vue';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  visitMock: vi.fn(),
  pageState: {
    url: '/nubos/goals',
    props: {
      auth: {
        user: null,
        can: {} as Record<string, boolean>,
        authority: null as string | null,
      },
    },
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: visitMock,
    post: postMock,
    delete: deleteMock,
  },
  usePage: () => pageState,
}));

type Wrapper = VueWrapper;

type CellFn = (params: { data?: GoalListRow; value?: unknown }) => unknown;

const NOW = '2026-08-11T12:00:00.000Z';

const GOAL_ID = '01GOAL00000000000000001A';

const REPORT_ID = '01REPORT000000000000001A';

const USER_ID = '01USER00000000000000001A';

const NOT_CALCULATED = 'noch nicht berechnet';

const ENGLISH_UPDATE_REASON = 'Only the creator of this goal may change it.';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

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

function row(overrides: Partial<GoalListRow> = {}): GoalListRow {
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

function mountIndex(goals: GoalListRow[] = [row()]): Wrapper {
  return mount(Index, { props: { goals }, global: { stubs } });
}

function cellText(wrapper: Wrapper, header: string, data: GoalListRow): string {
  const definition = gridColumnByHeader(wrapper, header);
  const getter = definition.valueGetter as unknown as CellFn | undefined;
  const field = definition.field as keyof GoalListRow | undefined;

  const value = typeof getter === 'function' ? getter({ data }) : field === undefined ? null : data[field];

  const formatter = definition.valueFormatter as unknown as CellFn | undefined;

  const rendered = typeof formatter === 'function' ? formatter({ data, value }) : value;

  return rendered === null || rendered === undefined ? '' : String(rendered);
}

function renderCell(wrapper: Wrapper, header: string, data: GoalListRow): VueWrapper {
  const definition = gridColumnByHeader(wrapper, header);
  const renderer = definition.cellRenderer;

  if (renderer === undefined || renderer === null) {
    throw new Error(`Column "${header}" renders no cell component`);
  }

  return mount(renderer as Component, {
    props: {
      params: {
        data,
        value: null,
        ...recordOf(definition.cellRendererParams),
      },
    },
  });
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(NOW));
  deleteMock.mockReset();
  postMock.mockReset();
  visitMock.mockReset();
  pageState.props.auth.authority = null;
});

afterEach(() => {
  vi.useRealTimers();
});

describe('goals/Index — the grid', () => {
  it('feeds every goal the server sent to the grid', () => {
    const wrapper = mountIndex([row(), row({ id: 'second' })]);

    expect(gridRows(wrapper)).toHaveLength(2);
  });

  it('never filters by visibility on its own', () => {
    const invisible = row({
      id: 'locked',
      can_update: false,
      can_delete: false,
      update_reason: 'not_owner',
      delete_reason: 'not_owner',
    });
    const wrapper = mountIndex([row(), invisible]);

    expect(gridRows(wrapper)).toHaveLength(2);
    expect(gridRows(wrapper).map((entry) => recordOf(entry).id)).toContain('locked');
  });

  it('hides the grid, its header and the filter bar while there is no goal', () => {
    const wrapper = mountIndex([]);

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(wrapper.find('[data-create-button]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Ziele');
  });
});

describe('goals/Index — the columns speak German', () => {
  it('names the person a goal belongs to instead of the raw scope', () => {
    const wrapper = mountIndex();

    expect(cellText(wrapper, 'Zuordnung', row())).toBe('Anna Albers');
  });

  it('falls back to the scope for a goal that carries no target', () => {
    const wrapper = mountIndex();
    const tenantGoal = row({
      scope_type: 'tenant',
      target: null,
      target_user_id: null,
      scope_field_key: null,
    });

    const text = cellText(wrapper, 'Zuordnung', tenantGoal);

    expect(text).toContain('Mandant');
    expect(text).not.toContain('tenant');
  });

  it('spells the period type and the direction out in German', () => {
    const wrapper = mountIndex();

    const periodType = cellText(wrapper, 'Periodenart', row());
    const direction = cellText(wrapper, 'Richtung', row());

    expect(periodType).toBe('Monat');
    expect(direction.toLowerCase()).toContain('mindestens');
    expect(direction).not.toContain('at_least');
  });

  it('formats the target value instead of printing the raw decimal string', () => {
    const wrapper = mountIndex();

    const text = cellText(wrapper, 'Zielwert', row());

    expect(text).not.toContain('10000.0000');
    expect(text).toMatch(/10[.,]?000/);
  });
});

describe('goals/Index — the current reading', () => {
  it('mounts the shared progress bar for a running, calculated period', () => {
    const wrapper = mountIndex();
    const cell = renderCell(wrapper, 'Ist-Stand', row());
    const bar = cell.findComponent(GoalProgressBar);

    expect(bar.exists()).toBe(true);
    expect(bar.props('current')).toBe(4200);
    expect(bar.props('target')).toBe(10000);
    expect(bar.props('direction')).toBe('at_least');
  });

  it('states that nothing was calculated yet rather than showing a nought', () => {
    const wrapper = mountIndex();

    const withoutValue = renderCell(wrapper, 'Ist-Stand', row({ periods: [period({ current_value: null })] }));
    const withoutPeriod = renderCell(wrapper, 'Ist-Stand', row({ periods: [] }));

    [withoutValue, withoutPeriod].forEach((cell) => {
      expect(cell.text()).toContain(NOT_CALCULATED);
      expect(cell.findComponent(GoalProgressBar).exists()).toBe(false);
      expect(cell.text()).not.toMatch(/\d/);
    });
  });

  it('reads the age of the reading off the server timestamp', () => {
    const wrapper = mountIndex();

    expect(cellText(wrapper, 'Stand der letzten Berechnung', row())).toBe('vor 2 Stunden');
  });

  it('says so plainly when there is no reading to date', () => {
    const wrapper = mountIndex();

    const text = cellText(wrapper, 'Stand der letzten Berechnung', row({ periods: [period({ calculated_at: null })] }));

    expect(text).toContain(NOT_CALCULATED);
    expect(text).not.toMatch(/\d/);
  });
});

describe('goals/Index — opening a row', () => {
  it('opens the row on click at the very target the pencil points at', async () => {
    const wrapper = mountIndex();

    const pencilHref = rowAction<GoalListRow>(wrapper, 'Bearbeiten').href?.(row());

    activateRow(wrapper, row());
    await wrapper.vm.$nextTick();

    expect(pencilHref).toBe(GoalsController.edit.url({ goal: GOAL_ID }));
    expect(visitMock.mock.calls[0][0]).toBe(pencilHref);
  });

  it('activates a row only while the actor may edit it', () => {
    const wrapper = mountIndex();
    const isRowActivatable = rowActivation<GoalListRow>(wrapper);

    expect(isRowActivatable(row())).toBe(true);
    expect(isRowActivatable(row({ can_update: false }))).toBe(false);
  });

  it('leaves the name unlinked while the actor may not edit it', () => {
    const wrapper = mountIndex();
    const href = recordOf(gridColumnByHeader(wrapper, 'Name').cellRendererParams).href;

    if (typeof href !== 'function') {
      throw new Error('The name column must render a link');
    }

    expect(href(row())).toBe(GoalsController.edit.url({ goal: GOAL_ID }));
    expect(href(row({ can_update: false }))).toBeUndefined();
  });
});

describe('goals/Index — forbidden actions', () => {
  it('disables a forbidden action with a German reason instead of hiding it', () => {
    const blocked = row({
      can_update: false,
      can_delete: false,
      update_reason: 'not_owner',
      delete_reason: 'not_owner',
    });
    const wrapper = mountIndex([blocked]);
    const edit = rowAction<GoalListRow>(wrapper, 'Bearbeiten');

    expect(edit.isVisible).toBeUndefined();
    expect(edit.isDisabled?.(blocked)).toBe(true);

    const reason = edit.disabledReason?.(blocked) ?? '';

    expect(reason.length).toBeGreaterThan(10);
    expect(reason).not.toBe(ENGLISH_UPDATE_REASON);
    expect(reason).not.toBe('not_owner');
    expect(rowAction<GoalListRow>(wrapper, 'Löschen').isDisabled?.(blocked)).toBe(true);
  });

  it('tells the two refusal reasons apart', () => {
    const notOwner = row({ can_update: false, update_reason: 'not_owner' });
    const notVisible = row({
      can_update: false,
      update_reason: 'not_visible',
    });
    const wrapper = mountIndex([notOwner]);
    const edit = rowAction<GoalListRow>(wrapper, 'Bearbeiten');

    expect(edit.disabledReason?.(notOwner)).not.toBe(edit.disabledReason?.(notVisible));
  });

  it('answers a reason it does not know with a fallback rather than an empty tooltip', () => {
    const unknown = row({
      can_update: false,
      update_reason: 'something_new_from_the_server',
    });
    const wrapper = mountIndex([unknown]);

    const reason = rowAction<GoalListRow>(wrapper, 'Bearbeiten').disabledReason?.(unknown);

    expect(reason).toBeDefined();
    expect(String(reason).length).toBeGreaterThan(10);
  });

  it('carries no reason at all for a row the actor may govern', () => {
    const wrapper = mountIndex();

    expect(rowAction<GoalListRow>(wrapper, 'Bearbeiten').isDisabled?.(row())).toBe(false);
    expect(rowAction<GoalListRow>(wrapper, 'Bearbeiten').disabledReason?.(row())).toBeUndefined();
  });
});

describe('goals/Index — selection and bulk delete', () => {
  it('keeps a goal the actor may not delete out of the selection', () => {
    const blocked = row({ can_delete: false });
    const wrapper = mountIndex([blocked]);

    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
    expect(selectionCheckbox(wrapper, row()).exists()).toBe(true);
  });

  it('offers the bulk bar only once something is selected', async () => {
    const wrapper = mountIndex();

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);

    await selectRow(wrapper, row());

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);
  });

  it('sends the selected ids to the bulk delete endpoint', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(GoalsController.bulkDestroy.url(), { ids: [GOAL_ID] }, expect.anything());
  });
});

describe('goals/Index — deleting', () => {
  it('asks before deleting and never deletes straight away', async () => {
    const wrapper = mountIndex();

    rowAction<GoalListRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('deletes through the wayfinder route once confirmed', async () => {
    const wrapper = mountIndex();

    rowAction<GoalListRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(deleteMock.mock.calls[0][0]).toBe(GoalsController.destroy.url({ goal: GOAL_ID }));
  });
});
