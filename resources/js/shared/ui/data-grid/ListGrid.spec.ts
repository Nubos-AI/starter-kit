import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import type { PropType } from 'vue';
import { gridColumns, gridProp, rowActions } from '@/shared/testing/dataGrid';
import { t } from '@/shared/testing/i18n';
import { itBehavesLikeADeletableList } from '@/shared/testing/listBehaviour';
import type { ListGridCopy, ListGridRow, ListGridRowOpen } from '@/shared/types/listGrid';
import type { RowAction } from '@/shared/types/rowAction';
import AlertError from '@/shared/ui/AlertError.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import { SELECTION_COL_ID } from '@/shared/ui/data-grid/selectionColumn';

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: { visit: vi.fn(), delete: vi.fn(), post: vi.fn() },
  InfiniteScroll: { template: '<div data-infinite-scroll><slot /></div>' },
}));

interface GoalRow extends ListGridRow {
  name: string;
  update_reason: string | null;
  delete_reason: string | null;
}

function goal(overrides: Partial<GoalRow> = {}): GoalRow {
  return {
    id: 'goal-1',
    name: 'Umsatz',
    can_update: true,
    update_reason: null,
    can_delete: true,
    delete_reason: null,
    ...overrides,
  };
}

const columns: ColDef<GoalRow>[] = [{ colId: 'name', field: 'name', headerName: 'Name' }];

const copy: ListGridCopy<GoalRow> = {
  deleteSelected: 'Ziele löschen',
  emptyTitle: 'Noch keine Ziele',
  emptyDescription: 'Legen Sie Ihr erstes Ziel an.',
  create: 'Ziel anlegen',
  deleteTitle: 'Ziel löschen',
  deleteDescription: (row) => `Das Ziel ${row?.name ?? ''} wird gelöscht.`,
};

const Host = defineComponent({
  props: {
    rows: { type: Array as PropType<GoalRow[]>, required: true },
    canCreate: { type: Boolean, default: true },
    isReadOnly: { type: Boolean, default: false },
    rowActions: { type: Array as PropType<RowAction<GoalRow>[]>, default: () => [] },
    rowOpen: { type: Object as PropType<ListGridRowOpen<GoalRow>>, default: undefined },
  },
  setup(props) {
    return () =>
      h(ListGrid<GoalRow>, {
        rows: props.rows,
        columns,
        gridLabel: 'Ziele',
        canCreate: props.canCreate,
        copy,
        isReadOnly: props.isReadOnly,
        rowActions: props.rowActions,
        rowOpen: props.rowOpen,
        destroyUrl: (row: GoalRow) => `/goals/${row.id}`,
        bulkDestroyUrl: () => '/goals/bulk-destroy',
        onCreate: () => router.visit('/goals/create'),
        onOpen: (row: GoalRow) => router.visit(`/goals/${row.id}/edit`),
      });
  },
});

const stubs = { DataGrid: true, UiExtensionPoint: true };

function mountList(rows: GoalRow[], options: Record<string, unknown> = {}) {
  return mount(Host, { props: { rows, ...options }, global: { stubs } });
}

describe('ListGrid', () => {
  itBehavesLikeADeletableList<GoalRow>({
    mountList: (rows) => mountList(rows),
    mountWithoutCreate: (rows) => mountList(rows, { canCreate: false }),
    row: (overrides) => goal(overrides),
    rowsProp: (rows) => ({ rows }),
    editUrl: (row) => `/goals/${row.id}/edit`,
    createUrl: '/goals/create',
    destroyUrl: (row) => `/goals/${row.id}`,
    bulkDestroyUrl: '/goals/bulk-destroy',
  });

  it('labels the grid and the default row actions through the shared catalogue', () => {
    const wrapper = mountList([goal()]);

    expect(gridProp(wrapper, 'ariaLabel')).toBe('Ziele');
    expect(rowActions<GoalRow>(wrapper).map((action) => action.label)).toEqual([
      t('i18n.components.data_grid.list_grid.edit'),
      t('i18n.components.data_grid.list_grid.delete'),
    ]);
  });

  it('puts the special actions of the page between edit and delete', () => {
    const special: RowAction<GoalRow> = { icon: { render: () => null }, label: 'Neu berechnen' };
    const wrapper = mountList([goal()], { rowActions: [special] });

    expect(rowActions<GoalRow>(wrapper).map((action) => action.variant)).toEqual(['edit', undefined, 'destructive']);
  });

  it('lets the pencil link to the row when the page names a target', () => {
    const wrapper = mountList([goal()], {
      rowOpen: {
        label: 'Bearbeiten',
        isAllowed: (row: GoalRow) => row.can_update,
        reason: () => '',
        href: (row: GoalRow) => `/goals/${row.id}/edit`,
      },
    });
    const pencil = rowActions<GoalRow>(wrapper)[0];

    expect(pencil.href?.(goal())).toBe('/goals/goal-1/edit');
    expect(pencil.onClick).toBeUndefined();
  });

  it('offers neither selection nor deleting when the list is read-only', () => {
    const wrapper = mountList([goal()], { isReadOnly: true });

    expect(gridColumns(wrapper).map((column) => column.colId)).not.toContain(SELECTION_COL_ID);
    expect(rowActions<GoalRow>(wrapper).map((action) => action.variant)).toEqual(['edit']);
    expect(wrapper.findComponent(ConfirmDialog).exists()).toBe(false);
  });

  it('shows the refusal of the server when a delete fails', async () => {
    vi.mocked(router.delete).mockImplementationOnce((_url, options) => {
      options?.onError?.({ goal: 'Das Ziel wird noch verwendet.' });
    });

    const wrapper = mountList([goal()]);

    rowActions<GoalRow>(wrapper).at(-1)?.onClick?.(goal());
    await wrapper.vm.$nextTick();
    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(AlertError).props('message')).toBe('Das Ziel wird noch verwendet.');
  });
});
