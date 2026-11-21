import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import {
  activateRow,
  columnParams,
  columnValue,
  gridOf,
  gridRows,
  rowAction,
  rowActions,
  rowActivation,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import type { ReportListRow } from '../../../data/types/reports';
import Index from './Index.vue';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  visitMock: vi.fn(),
  pageState: {
    url: '/nubos/reports',
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

const ENGLISH_UPDATE_REASON = 'Only the owner of this report can change it.';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

function row(overrides: Partial<ReportListRow> = {}): ReportListRow {
  return {
    id: '01REPORT0000000000000001',
    name: 'Pipeline nach Phase',
    description: null,
    object_type_id: '01OBJECTTYPE000000000001',
    object_type: {
      id: '01OBJECTTYPE000000000001',
      slug: 'deals',
      name: 'Deals',
      label: 'Verkaufschancen',
    },
    filter_definition: [],
    aggregation_type: 'count',
    aggregation_field_key: null,
    group_by_field_key: 'stage',
    group_by_bucket: null,
    series_field_key: null,
    chart_type: 'bar',
    execution_mode: 'viewer',
    is_owner: true,
    is_system: false,
    can_update: true,
    can_delete: true,
    update_reason: null,
    delete_reason: null,
    updated_at: '2026-08-10T14:23:45+02:00',
    ...overrides,
  };
}

function mountIndex(reports: ReportListRow[] = [row()]): Wrapper {
  return mount(Index, { props: { reports }, global: { stubs } });
}

beforeEach(() => {
  deleteMock.mockReset();
  postMock.mockReset();
  visitMock.mockReset();
  pageState.props.auth.authority = null;
});

describe('reports/Index — grid', () => {
  it('feeds every report to the grid', () => {
    const wrapper = mountIndex([row(), row({ id: 'second' })]);

    expect(gridRows(wrapper)).toHaveLength(2);
  });

  it('hides the grid entirely while there is not a single report', () => {
    expect(gridOf(mountIndex([])).exists()).toBe(false);
  });

  it('keeps the page heading and the create button in the empty state', () => {
    const wrapper = mountIndex([]);

    expect(wrapper.find('[data-create-button]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Auswertungen');
  });

  it('reads the translated object type label off the nested object instead of stringifying it', () => {
    const wrapper = mountIndex();

    expect(columnValue(wrapper, 'object_type', row())).toBe('Verkaufschancen');

    const empty = String(columnValue(wrapper, 'object_type', row({ object_type: null })) ?? '');

    expect(empty).not.toContain('[object');
    expect(empty).not.toContain('Verkaufschancen');
  });
});

describe('reports/Index — opening a row', () => {
  it('opens the row on click at the very target the pencil points at', async () => {
    const wrapper = mountIndex();

    const pencilHref = rowAction<ReportListRow>(wrapper, 'Bearbeiten').href?.(row());

    activateRow(wrapper, row());
    await wrapper.vm.$nextTick();

    expect(pencilHref).toBe(ReportsController.edit.url({ report: row().id }));
    expect(visitMock.mock.calls[0][0]).toBe(pencilHref);
  });

  it('activates a row only while the actor may edit it', () => {
    const wrapper = mountIndex();
    const isRowActivatable = rowActivation<ReportListRow>(wrapper);

    expect(isRowActivatable(row())).toBe(true);
    expect(isRowActivatable(row({ can_update: false }))).toBe(false);
  });

  it('leaves the name unlinked while the actor may not edit it', () => {
    const wrapper = mountIndex();
    const href = columnParams(wrapper, 'name').href;

    if (typeof href !== 'function') {
      throw new Error('The name column must render a link');
    }

    expect(href(row())).toBe(ReportsController.edit.url({ report: row().id }));
    expect(href(row({ can_update: false }))).toBeUndefined();
  });
});

describe('reports/Index — forbidden actions', () => {
  it('lines the actions up as edit first and destructive delete last', () => {
    const wrapper = mountIndex();
    const actions = rowActions<ReportListRow>(wrapper);

    expect(actions.map((entry) => entry.label)).toEqual(['Bearbeiten', 'Löschen']);
    expect(actions[0].variant).toBe('edit');
    expect(actions[1].variant).toBe('destructive');
  });

  it('disables a forbidden action with a German reason instead of hiding it', () => {
    const blocked = row({
      can_update: false,
      can_delete: false,
      update_reason: 'not_owner',
      delete_reason: 'not_owner',
      is_owner: false,
    });
    const wrapper = mountIndex([blocked]);

    const edit = rowAction<ReportListRow>(wrapper, 'Bearbeiten');

    expect(edit.isVisible).toBeUndefined();
    expect(edit.isDisabled?.(blocked)).toBe(true);

    const reason = edit.disabledReason?.(blocked) ?? '';

    expect(reason.length).toBeGreaterThan(10);
    expect(reason).not.toBe(ENGLISH_UPDATE_REASON);
    expect(reason).not.toBe('Keine Berechtigung');
    expect(rowAction<ReportListRow>(wrapper, 'Löschen').isDisabled?.(blocked)).toBe(true);
  });

  it('carries no reason at all for a row the actor may govern', () => {
    const wrapper = mountIndex();

    expect(rowAction<ReportListRow>(wrapper, 'Bearbeiten').isDisabled?.(row())).toBe(false);
    expect(rowAction<ReportListRow>(wrapper, 'Bearbeiten').disabledReason?.(row())).toBeUndefined();
  });
});

describe('reports/Index — selection and bulk delete', () => {
  it('keeps a report the actor may not delete out of the selection', () => {
    const blocked = row({ can_delete: false });
    const wrapper = mountIndex([blocked]);

    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
    expect(selectionCheckbox(wrapper, row()).exists()).toBe(true);
  });

  it('sends the selected ids to the bulk delete endpoint', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(ReportsController.bulkDestroy.url(), { ids: [row().id] }, expect.anything());
  });
});

describe('reports/Index — deleting', () => {
  it('asks before deleting and never deletes straight away', async () => {
    const wrapper = mountIndex();

    rowAction<ReportListRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('deletes through the wayfinder route once confirmed', async () => {
    const wrapper = mountIndex();

    rowAction<ReportListRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(deleteMock.mock.calls[0][0]).toBe(ReportsController.destroy.url({ report: row().id }));
  });
});
