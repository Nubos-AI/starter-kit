import { router } from '@inertiajs/vue3';
import type { VueWrapper } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import {
  activateRow,
  gridColumns,
  gridOf,
  rowActions,
  rowActivation,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import type { RowAction } from '@/shared/types/rowAction';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { ACTIONS_COL_ID } from '@/shared/ui/data-grid/actions';
import { SELECTION_COL_ID } from '@/shared/ui/data-grid/selectionColumn';
import ViewStates from '@/shared/ui/ViewStates.vue';

export interface ListRow {
  id: string;
  can_update: boolean;
  update_reason: string | null;
  can_delete: boolean;
  delete_reason: string | null;
}

export interface ListActionIds {
  edit: string;
  delete: string;
  special?: string[];
}

export interface DeletableListCase<Row extends ListRow> {
  mountList: (rows: Row[]) => VueWrapper;
  mountWithoutCreate?: (rows: Row[]) => VueWrapper;
  row: (overrides?: Partial<ListRow>) => Row;
  rowsProp?: (rows: Row[]) => Record<string, unknown>;
  editUrl: (row: Row) => string;
  createUrl: string;
  destroyUrl: (row: Row) => string;
  bulkDestroyUrl: string;
  actionIds?: ListActionIds;
  specialActions?: number;
  opensForReaders?: boolean;
  readOnlyUrl?: (row: Row) => string;
}

export interface ListWithoutSelectionCase<Row extends ListRow> {
  mountList: (rows: Row[]) => VueWrapper;
  mountWithoutCreate: (rows: Row[]) => VueWrapper;
  row: (overrides?: Partial<ListRow>) => Row;
  openUrl: (row: Row) => string;
  expectCreateStarted: (wrapper: VueWrapper) => void;
}

function editAndDeleteActions<Row extends ListRow>(
  listed: RowAction<Row>[],
  actionIds: ListActionIds | undefined,
): RowAction<Row>[] {
  if (actionIds === undefined) {
    return [listed[0], listed[listed.length - 1]];
  }

  return listed.filter((action) => action.testId === actionIds.edit || action.testId === actionIds.delete);
}

function itOffersCreatingOnlyWithTheRight(
  mountList: (rows: never[]) => VueWrapper,
  mountWithoutCreate: (rows: never[]) => VueWrapper,
): void {
  it('offers creating only to users who may create entries of the list', () => {
    expect(mountList([]).findComponent(CreateButton).exists()).toBe(true);
    expect(mountWithoutCreate([]).findComponent(CreateButton).exists()).toBe(false);
  });
}

export function itBehavesLikeADeletableList<Row extends ListRow>(subject: DeletableListCase<Row>): void {
  const opensForReaders = subject.opensForReaders === true;

  beforeEach(() => {
    vi.mocked(router.visit).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
  });

  it('leads with a selection column that offers only rows the server lets the user delete', () => {
    const wrapper = subject.mountList([subject.row()]);
    const blocked = subject.row({
      can_delete: false,
      delete_reason: 'Gesperrt.',
    });

    expect(gridColumns(wrapper)[0].colId).toBe(SELECTION_COL_ID);
    expect(selectionCheckbox(wrapper, subject.row()).exists()).toBe(true);
    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
  });

  it('orders the row actions edit before delete and refuses them with the reason of the server', () => {
    const refused = subject.row({
      can_update: false,
      update_reason: 'Bearbeiten ist nicht erlaubt.',
      can_delete: false,
      delete_reason: 'Löschen ist nicht erlaubt.',
    });
    const listed = rowActions<Row>(subject.mountList([refused]));
    const actions = editAndDeleteActions(listed, subject.actionIds);

    if (subject.actionIds === undefined) {
      expect(listed.map((action) => action.variant)).toEqual([
        'edit',
        ...Array.from({ length: subject.specialActions ?? 0 }, () => 'default'),
        'destructive',
      ]);
    } else {
      expect(listed.map((action) => action.testId)).toEqual([
        subject.actionIds.edit,
        ...(subject.actionIds.special ?? []),
        subject.actionIds.delete,
      ]);
    }

    expect(actions.map((action) => action.variant)).toEqual(['edit', 'destructive']);
    expect(actions.map((action) => action.isDisabled?.(refused))).toEqual([!opensForReaders, true]);

    if (!opensForReaders) {
      expect(actions[0].disabledReason?.(refused)).toBe('Bearbeiten ist nicht erlaubt.');
    }

    expect(actions[1].disabledReason?.(refused)).toBe('Löschen ist nicht erlaubt.');
    expect(actions.map((action) => action.isDisabled?.(subject.row()))).toEqual([false, false]);
  });

  it('asks before deleting a single row and deletes it after confirming', async () => {
    const wrapper = subject.mountList([subject.row()]);
    const remove = editAndDeleteActions(rowActions<Row>(wrapper), subject.actionIds)[1];

    remove.onClick?.(subject.row());
    await nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(wrapper.findComponent(ConfirmDialog).props('variant')).toBe('destructive');
    expect(router.delete).not.toHaveBeenCalled();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');

    expect(vi.mocked(router.delete).mock.calls[0][0]).toBe(subject.destroyUrl(subject.row()));
  });

  it('opens a row on click with the editor, or for a reader where the list allows it', () => {
    const wrapper = subject.mountList([subject.row()]);
    const isActivatable = rowActivation<Row>(wrapper);
    const locked = subject.row({ can_update: false });

    expect(isActivatable(subject.row())).toBe(true);
    expect(isActivatable(locked)).toBe(opensForReaders || subject.readOnlyUrl !== undefined);

    activateRow(wrapper, subject.row());

    expect(router.visit).toHaveBeenLastCalledWith(subject.editUrl(subject.row()));

    if (subject.readOnlyUrl !== undefined) {
      activateRow(wrapper, locked);

      expect(router.visit).toHaveBeenLastCalledWith(subject.readOnlyUrl(locked));
    }
  });

  it('shows the bulk bar for a selection and sends the selected rows after confirming', async () => {
    const wrapper = subject.mountList([subject.row()]);

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);

    await selectRow(wrapper, subject.row());

    await wrapper.get('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.get('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(router.post).toHaveBeenCalledWith(subject.bulkDestroyUrl, { ids: [subject.row().id] }, expect.anything());
  });

  if (subject.rowsProp !== undefined) {
    const rowsProp = subject.rowsProp;

    it('drops a selected row from the selection once the row is gone or blocked', async () => {
      const wrapper = subject.mountList([subject.row()]);

      await selectRow(wrapper, subject.row());

      expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);

      await wrapper.setProps(rowsProp([]));

      expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);
    });
  }

  it('hides the grid of an empty list and leads to creating the first entry', () => {
    const wrapper = subject.mountList([]);
    const states = wrapper.findComponent(ViewStates);

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(states.props('state')).toBe('empty');

    states.vm.$emit('create');

    expect(router.visit).toHaveBeenCalledWith(subject.createUrl);
  });

  if (subject.mountWithoutCreate !== undefined) {
    itOffersCreatingOnlyWithTheRight(subject.mountList, subject.mountWithoutCreate);
  }
}

export function itBehavesLikeAListWithoutSelection<Row extends ListRow>(subject: ListWithoutSelectionCase<Row>): void {
  beforeEach(() => {
    vi.mocked(router.visit).mockClear();
  });

  it('offers neither a selection nor a delete action on a row', () => {
    const wrapper = subject.mountList([subject.row()]);
    const colIds = gridColumns(wrapper).map((column) => column.colId);
    const actions = colIds.includes(ACTIONS_COL_ID) ? rowActions<Row>(wrapper) : [];

    expect(colIds).not.toContain(SELECTION_COL_ID);
    expect(actions.map((action) => action.variant)).not.toContain('destructive');
    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);
  });

  it('opens any row on click, also for a reader', () => {
    const reader = subject.row({ can_update: false });
    const wrapper = subject.mountList([reader]);

    expect(rowActivation<Row>(wrapper)(reader)).toBe(true);

    activateRow(wrapper, reader);

    expect(router.visit).toHaveBeenLastCalledWith(subject.openUrl(reader));
  });

  it('hides the grid of an empty list and leads to the first entry', async () => {
    const wrapper = subject.mountList([]);
    const states = wrapper.findComponent(ViewStates);

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(states.props('state')).toBe('empty');

    states.vm.$emit('create');
    await nextTick();

    subject.expectCreateStarted(wrapper);
  });

  itOffersCreatingOnlyWithTheRight(subject.mountList, subject.mountWithoutCreate);
}
