import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { useListDeletion } from './useListDeletion';

interface VisitOptions {
  onSuccess?: () => void;
  onFinish?: () => void;
}

const { visits } = vi.hoisted(() => {
  const recorded: {
    method: string;
    url: string;
    data: unknown;
    options: VisitOptions;
  }[] = [];

  return { visits: recorded };
});

vi.mock('@inertiajs/vue3', () => ({
  router: {
    delete: (url: string, options: VisitOptions) => {
      visits.push({ method: 'delete', url, data: null, options });
    },
    post: (url: string, data: unknown, options: VisitOptions) => {
      visits.push({ method: 'post', url, data, options });
    },
  },
}));

interface Row {
  id: string;
  can_delete: boolean;
}

const first: Row = { id: 'row-1', can_delete: true };
const second: Row = { id: 'row-2', can_delete: true };
const blocked: Row = { id: 'row-3', can_delete: false };

describe('list deletion', () => {
  beforeEach(() => {
    visits.length = 0;
  });

  it('deletes a single row only after confirmation and closes the dialog once the request finished', () => {
    const deletion = useListDeletion<Row>({
      rows: ref<Row[]>([first]),
      destroyUrl: (row) => `/rows/${row.id}`,
      bulkDestroyUrl: () => '/rows/bulk',
    });

    deletion.requestDelete(first);

    expect(deletion.isDeleteDialogOpen.value).toBe(true);
    expect(deletion.pendingDelete.value).toEqual(first);
    expect(visits).toHaveLength(0);

    deletion.confirmDelete();

    expect(visits.map((visit) => [visit.method, visit.url])).toEqual([['delete', '/rows/row-1']]);
    expect(deletion.isDeletePending.value).toBe(true);

    visits[0]?.options.onFinish?.();

    expect(deletion.isDeletePending.value).toBe(false);
    expect(deletion.isDeleteDialogOpen.value).toBe(false);
  });

  it('sends nothing when the dialog was closed without confirmation', () => {
    const deletion = useListDeletion<Row>({
      rows: ref<Row[]>([first]),
      destroyUrl: (row) => `/rows/${row.id}`,
      bulkDestroyUrl: () => '/rows/bulk',
    });

    deletion.requestDelete(first);
    deletion.isDeleteDialogOpen.value = false;
    deletion.confirmDelete();

    expect(visits).toHaveLength(0);
  });

  it('deletes the selection in bulk and clears it once the server accepted', () => {
    const deletion = useListDeletion<Row>({
      rows: ref<Row[]>([first, second]),
      destroyUrl: (row) => `/rows/${row.id}`,
      bulkDestroyUrl: () => '/rows/bulk',
    });

    deletion.selection.toggle(first.id);
    deletion.selection.toggle(second.id);
    deletion.bulkDelete();

    expect(visits[0]?.url).toBe('/rows/bulk');
    expect(visits[0]?.data).toEqual({ ids: ['row-1', 'row-2'] });

    visits[0]?.options.onSuccess?.();

    expect(deletion.selection.count.value).toBe(0);
  });

  it('drops rows from the selection that vanished or may no longer be deleted', async () => {
    const rows = ref<Row[]>([first, second]);
    const deletion = useListDeletion<Row>({
      rows,
      destroyUrl: (row) => `/rows/${row.id}`,
      bulkDestroyUrl: () => '/rows/bulk',
    });

    deletion.selection.toggle(first.id);
    deletion.selection.toggle(second.id);
    rows.value = [first, { ...blocked, id: second.id }];
    await nextTick();

    expect(deletion.selection.ids.value).toEqual(['row-1']);
  });
});
