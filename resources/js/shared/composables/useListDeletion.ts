import { router } from '@inertiajs/vue3';
import type { ComputedRef, Ref, WritableComputedRef } from 'vue';
import { computed, watch } from 'vue';
import type { ListSelection } from '@/shared/composables/useListSelection';
import { useListSelection } from '@/shared/composables/useListSelection';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';

export interface DeletableRow {
  id: string;
  can_delete: boolean;
}

export interface UseListDeletionOptions<Row extends DeletableRow> {
  rows: Readonly<Ref<Row[]>>;
  destroyUrl: (row: Row) => string;
  bulkDestroyUrl: () => string;
}

export interface UseListDeletionReturn<Row extends DeletableRow> {
  selection: ListSelection;
  pendingDelete: ComputedRef<Row | null>;
  isDeletePending: Ref<boolean>;
  isDeleteDialogOpen: WritableComputedRef<boolean>;
  requestDelete: (row: Row) => void;
  confirmDelete: () => void;
  bulkDelete: () => void;
}

export function useListDeletion<Row extends DeletableRow>(
  options: UseListDeletionOptions<Row>,
): UseListDeletionReturn<Row> {
  const selection = useListSelection();
  const deletion = useRowDeletion<Row>({ url: options.destroyUrl });

  watch(options.rows, (rows) => selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)));

  function bulkDelete(): void {
    router.post(
      options.bulkDestroyUrl(),
      { ids: selection.ids.value },
      { preserveScroll: true, onSuccess: () => selection.clear() },
    );
  }

  return {
    selection,
    pendingDelete: computed<Row | null>(() => deletion.pendingDelete.value),
    isDeletePending: deletion.deletePending,
    isDeleteDialogOpen: deletion.deleteDialogOpen,
    requestDelete: deletion.requestDelete,
    confirmDelete: deletion.confirmDelete,
    bulkDelete,
  };
}
