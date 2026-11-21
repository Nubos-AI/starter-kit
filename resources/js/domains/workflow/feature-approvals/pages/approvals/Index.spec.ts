import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ApprovalsController from '@/actions/App/Http/Controllers/Approvals/ApprovalsController';
import { activateRow, columnCell, columnParams, gridColumn, gridOf, gridRows } from '@/shared/testing/dataGrid';
import Index from './Index.vue';

const { visitMock, pageState } = vi.hoisted(() => {
  const can: Record<string, boolean> = {};

  return {
    visitMock: vi.fn(),
    pageState: {
      url: '/nubos/engine/approvals',
      props: {
        auth: {
          user: null,
          can,
          authority: null,
        },
      },
    },
  };
});

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: visitMock,
  },
  usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

interface ApprovalWorkloadRow {
  id: string;
  record_id: string | null;
  anchor_label: string;
  anchor_kind_label: string;
  anchor_url: string | null;
  transition_label: string;
  stage_label: string;
  deadline_at: string | null;
  is_overdue: boolean;
  on_behalf_of_id: string | null;
  on_behalf_of_label: string | null;
}

interface AnchorLinkParams {
  href: (row: ApprovalWorkloadRow) => string;
}

function row(overrides: Partial<ApprovalWorkloadRow> = {}): ApprovalWorkloadRow {
  return {
    id: '01APPROVALPROCESS000000001',
    record_id: '01RECORD000000000000000001',
    anchor_label: 'Rahmenvertrag 2026',
    anchor_kind_label: 'Verträge',
    anchor_url: 'https://nubos.test/anchor-target',
    transition_label: 'Offen → Genehmigt',
    stage_label: 'Stufe 1',
    deadline_at: '2026-09-05T12:00:00.000Z',
    is_overdue: false,
    on_behalf_of_id: null,
    on_behalf_of_label: null,
    ...overrides,
  };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
  mounted.push(wrapper);

  return wrapper;
}

function mountIndex(approvals: ApprovalWorkloadRow[] = [row()]): Wrapper {
  return track(mount(Index, { props: { approvals } }));
}

function renderCell(wrapper: Wrapper, colId: string, params: Record<string, unknown>): Wrapper {
  return track(mount(columnCell(wrapper, colId), { props: { params } }));
}

function anchorLinkParams(wrapper: Wrapper): AnchorLinkParams {
  const href = columnParams(wrapper, 'record').href;

  if (typeof href !== 'function') {
    throw new Error('record column has no href resolver');
  }

  return {
    href: (target: ApprovalWorkloadRow): string => String(href(target)),
  };
}

function renderAnchorCell(wrapper: Wrapper, target: ApprovalWorkloadRow): Wrapper {
  return renderCell(wrapper, 'record', {
    data: target,
    value: target.anchor_label,
    href: anchorLinkParams(wrapper).href,
  });
}

beforeEach(() => {
  visitMock.mockReset();
});

afterEach(() => {
  while (mounted.length > 0) {
    mounted.pop()?.unmount();
  }
});

describe('approvals/Index', () => {
  it('shows an explanatory empty state', () => {
    const wrapper = mountIndex([]);

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(wrapper.text()).toContain('Aktuell warten keine Genehmigungsvorgänge auf Ihre Entscheidung.');
  });

  it('feeds every open approval to the grid when there is at least one', () => {
    const wrapper = mountIndex([row(), row({ id: 'second-process' })]);

    expect(gridRows(wrapper)).toHaveLength(2);
  });

  it('reads the anchor label and the anchor kind from the server payload', () => {
    const wrapper = mountIndex();

    const target = row({
      anchor_label: 'Rahmenvertrag 2026',
      anchor_kind_label: 'Verträge',
    });
    const cellValue = (colId: string): unknown => Reflect.get(target, String(gridColumn(wrapper, colId).field));

    expect(gridColumn(wrapper, 'record').headerName).toBe('Vorgang');
    expect(cellValue('record')).toBe('Rahmenvertrag 2026');
    expect(gridColumn(wrapper, 'objectType').headerName).toBe('Art');
    expect(cellValue('objectType')).toBe('Verträge');
  });

  it.each([
    ['an anchor with a target of its own', 'https://nubos.test/record-target'],
    ['an anchor without a target of its own', null],
  ])('links the process label of %s to the decision page like the pencil', (_case, anchorUrl) => {
    const target = row({
      id: 'process-to-decide',
      anchor_label: 'Rahmenvertrag 2026',
      anchor_url: anchorUrl,
    });
    const wrapper = mountIndex([target]);
    const decideUrl = ApprovalsController.show.url({
      approval: target.id,
    });

    expect(anchorLinkParams(wrapper).href(target)).toBe(decideUrl);

    const link = renderAnchorCell(wrapper, target).get('a');

    expect(link.attributes('href')).toBe(decideUrl);
    expect(link.text()).toBe('Rahmenvertrag 2026');
  });

  it('marks an overdue deadline with a badge carrying a data hook', () => {
    const wrapper = mountIndex([row({ is_overdue: true })]);

    const cell = renderCell(wrapper, 'deadline', {
      data: row({ is_overdue: true }),
    });

    const badge = cell.get('[data-approval-overdue]');

    expect(badge.text()).toBe('Überfällig');
  });

  it('renders a deadline that is not overdue without the overdue badge hook', () => {
    const wrapper = mountIndex([row({ is_overdue: false })]);

    const cell = renderCell(wrapper, 'deadline', {
      data: row({ is_overdue: false }),
    });

    expect(cell.find('[data-approval-overdue]').exists()).toBe(false);
  });

  it('navigates to the decision page of the activated row', async () => {
    const target = row({ id: 'process-to-open' });
    const wrapper = mountIndex([target]);

    activateRow(wrapper, target);
    await nextTick();

    expect(visitMock).toHaveBeenCalledTimes(1);
    expect(String(visitMock.mock.calls[0][0])).toContain(target.id);
  });

  it('keeps the row activation on the decision page even when the anchor has its own target', async () => {
    const target = row({
      id: 'process-with-anchor-target',
      anchor_url: 'https://nubos.test/promotion-target',
    });
    const wrapper = mountIndex([target]);

    activateRow(wrapper, target);
    await nextTick();

    expect(visitMock).toHaveBeenCalledTimes(1);
    expect(String(visitMock.mock.calls[0][0])).toBe(ApprovalsController.show.url({ approval: target.id }));
  });
});
