import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TrashController from '@/actions/App/Http/Controllers/Engine/TrashController';
import { gridOf, gridRows, rowAction, selectRow } from '@/shared/testing/dataGrid';
import type { TrashPagination, TrashRow } from '../../types/trash';
import Index from './Index.vue';

const { deleteMock, getMock, postMock, putMock, visitMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  getMock: vi.fn(),
  postMock: vi.fn(),
  putMock: vi.fn(),
  visitMock: vi.fn(),
  pageState: {
    url: '/nubos/engine/trash',
    props: {
      auth: {
        user: null,
        can: {} as Record<string, boolean>,
        authority: null,
      },
    },
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: visitMock,
    get: getMock,
    post: postMock,
    put: putMock,
    delete: deleteMock,
  },
  usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

function row(overrides: Partial<TrashRow> = {}): TrashRow {
  return {
    id: '01TRASHRECORD000000000001',
    objectTypeName: 'Angebote',
    objectTypeSlug: 'angebote',
    recordNumber: 'AN-0000000001',
    title: 'Angebot A',
    deletionReason: 'Dublette',
    deletedAt: '2026-08-20T10:00:00.000000Z',
    purgeOn: '2026-09-03',
    canRestore: true,
    canDelete: true,
    ...overrides,
  };
}

const readOnlyRow = row({
  id: '01TRASHRECORD000000000002',
  recordNumber: 'AN-0000000002',
  title: 'Angebot B',
  deletionReason: null,
  canRestore: false,
  canDelete: false,
});

function mountIndex(records: TrashRow[] = [row(), readOnlyRow], pagination?: TrashPagination): Wrapper {
  return mount(Index, {
    props: { records, pagination },
    global: { stubs },
  });
}

beforeEach(() => {
  deleteMock.mockReset();
  getMock.mockReset();
  postMock.mockReset();
  putMock.mockReset();
  visitMock.mockReset();
});

describe('trash/Index — the trash is a read-only grid', () => {
  it('feeds every trashed record to the grid', () => {
    const wrapper = mountIndex();

    expect(gridRows(wrapper)).toHaveLength(2);
  });

  it('shows no grid when the trash is empty', () => {
    expect(gridOf(mountIndex([])).exists()).toBe(false);
  });
});

describe('trash/Index — the row actions follow the rights', () => {
  it('links the view action onto the record page', () => {
    const wrapper = mountIndex();

    expect(rowAction<TrashRow>(wrapper, 'Ansehen').href?.(row())).toBe(
      RecordsController.show.url({ record: 'AN-0000000001' }),
    );
  });

  it('restores a record through the trash route', () => {
    const wrapper = mountIndex();

    rowAction<TrashRow>(wrapper, 'Wiederherstellen').onClick?.(row());

    expect(putMock).toHaveBeenCalledWith(TrashController.restore.url({ id: row().id }), {}, expect.anything());
  });

  it('keeps restore and purge visible but disabled without the right', () => {
    const wrapper = mountIndex();

    expect(rowAction<TrashRow>(wrapper, 'Wiederherstellen').isVisible?.(readOnlyRow)).not.toBe(false);
    expect(rowAction<TrashRow>(wrapper, 'Wiederherstellen').isDisabled?.(readOnlyRow)).toBe(true);
    expect(rowAction<TrashRow>(wrapper, 'Endgültig löschen').isDisabled?.(readOnlyRow)).toBe(true);
    expect(rowAction<TrashRow>(wrapper, 'Endgültig löschen').disabledReason?.(readOnlyRow)).toBeTruthy();
  });

  it('purges a record only after the confirmation', async () => {
    const wrapper = mountIndex();

    rowAction<TrashRow>(wrapper, 'Endgültig löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(deleteMock).not.toHaveBeenCalled();

    wrapper.findAllComponents({ name: 'ConfirmDialog' })[0].vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(deleteMock).toHaveBeenCalledWith(TrashController.destroy.url({ id: row().id }), expect.anything());
  });
});

describe('trash/Index — bulk purge', () => {
  it('posts every selected id to the bulk route', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());
    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(TrashController.bulkDestroy.url(), { ids: [row().id] }, expect.anything());
  });
});

describe('trash/Index — the trash is read page by page', () => {
  const secondOfThree: TrashPagination = {
    currentPage: 2,
    lastPage: 3,
    perPage: 25,
    total: 60,
  };

  it('shows no pager while every trashed record fits on one page', () => {
    expect(mountIndex().find('[data-testid="list-pager"]').exists()).toBe(false);
  });

  it('requests the neighbouring page through the trash route', async () => {
    const wrapper = mountIndex([row()], secondOfThree);

    await wrapper.find('[data-testid="list-pager-next"]').trigger('click');
    await wrapper.find('[data-testid="list-pager-previous"]').trigger('click');

    expect(getMock.mock.calls[0]?.[0]).toContain('page=3');
    expect(getMock).toHaveBeenNthCalledWith(
      1,
      TrashController.index.url(undefined, { query: { page: 3 } }),
      {},
      expect.anything(),
    );
    expect(getMock).toHaveBeenNthCalledWith(
      2,
      TrashController.index.url(undefined, { query: { page: 1 } }),
      {},
      expect.anything(),
    );
  });

  it('disables the step beyond the last page', () => {
    const wrapper = mountIndex([row()], {
      ...secondOfThree,
      currentPage: 3,
    });

    expect(wrapper.find('[data-testid="list-pager-next"]').attributes('disabled')).toBeDefined();
  });
});
