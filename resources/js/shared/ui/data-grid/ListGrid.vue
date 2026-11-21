<script setup lang="ts" generic="Row extends ListGridRow">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { useListSelection } from '@/shared/composables/useListSelection';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import type { ListGridCopy, ListGridRow, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import type { ConfigurationGrid } from '@/shared/types/preferences';
import type { RowAction } from '@/shared/types/rowAction';
import AlertError from '@/shared/ui/AlertError.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import { actionsColumn } from '@/shared/ui/data-grid/actions';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import LoadMoreRows from '@/shared/ui/data-grid/LoadMoreRows.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/shared/ui/data-grid/selectionColumn';
import ViewStates from '@/shared/ui/ViewStates.vue';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    rows: Row[];
    columns: ColDef<Row>[];
    gridLabel: string;
    copy: ListGridCopy<Row>;
    canCreate?: boolean;
    destroyUrl?: (row: Row) => string;
    bulkDestroyUrl?: () => string;
    rowOpen?: ListGridRowOpen<Row>;
    rowDelete?: ListGridRowDelete<Row>;
    isReadOnly?: boolean;
    rowActions?: RowAction<Row>[];
    scrollData?: string;
    preferenceKey?: ConfigurationGrid;
    defaultColDef?: ColDef<Row>;
  }>(),
  {
    canCreate: true,
    destroyUrl: undefined,
    bulkDestroyUrl: undefined,
    rowOpen: undefined,
    rowDelete: undefined,
    isReadOnly: false,
    rowActions: () => [],
    scrollData: undefined,
    preferenceKey: undefined,
    defaultColDef: undefined,
  },
);

const emit = defineEmits<{
  create: [];
  open: [row: Row];
}>();

const selection = useListSelection();
const deleteFailure = ref<string | null>(null);

const deletion = useRowDeletion<Row>({
  url: (row) => props.destroyUrl?.(row) ?? '',
  preserveState: true,
  onError: (errors) => {
    deleteFailure.value = Object.values(errors)[0] ?? null;
  },
});

const { deleteDialogOpen, pendingDelete, deletePending } = deletion;

const state = computed<'empty' | 'ready'>(() => (props.rows.length === 0 ? 'empty' : 'ready'));

watch(
  () => props.rows,
  (rows) => selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const opening = computed<ListGridRowOpen<Row>>(
  () =>
    props.rowOpen ?? {
      label: t('i18n.components.data_grid.list_grid.edit'),
      isAllowed: (row) => row.can_update,
      reason: (row) => row.update_reason ?? '',
    },
);

function isRowActivatable(row: Row): boolean {
  return opening.value.isAllowed(row);
}

function confirmDelete(): void {
  deleteFailure.value = null;
  deletion.confirmDelete();
}

function bulkDelete(): void {
  if (props.bulkDestroyUrl === undefined) {
    return;
  }

  router.post(
    props.bulkDestroyUrl(),
    { ids: selection.ids.value },
    { preserveScroll: true, onSuccess: () => selection.clear() },
  );
}

const openAction = computed<RowAction<Row>>(() => ({
  icon: Pencil,
  label: opening.value.label,
  variant: 'edit',
  testId: opening.value.testId,
  ...(opening.value.href === undefined ? { onClick: (row: Row) => emit('open', row) } : { href: opening.value.href }),
  isDisabled: (row) => !opening.value.isAllowed(row),
  disabledReason: (row) => opening.value.reason(row),
}));

const deleteAction = computed<RowAction<Row>>(() => ({
  icon: Trash2,
  label: props.rowDelete?.label ?? t('i18n.components.data_grid.list_grid.delete'),
  variant: 'destructive',
  testId: props.rowDelete?.testId,
  onClick: deletion.requestDelete,
  isDisabled: (row) => !row.can_delete,
  disabledReason: (row) =>
    props.rowDelete?.reason === undefined ? (row.delete_reason ?? '') : props.rowDelete.reason(row),
}));

const columnDefs = computed<ColDef<Row>[]>(() => {
  if (props.isReadOnly) {
    return [...props.columns, actionsColumn<Row>([openAction.value, ...props.rowActions])];
  }

  return [
    selectionColumn<Row>(selection, (row) => row.can_delete),
    ...props.columns,
    actionsColumn<Row>([openAction.value, ...props.rowActions, deleteAction.value]),
  ];
});
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-4" data-list-grid>
    <AlertError v-if="deleteFailure !== null" data-list-delete-failure :message="deleteFailure" />

    <SelectionBulkBar
      v-if="!isReadOnly"
      :count="selection.count.value"
      :delete-label="copy.deleteSelected"
      @delete="bulkDelete"
      @clear="selection.clear"
    />

    <div class="relative flex min-h-40 flex-1 flex-col">
      <ViewStates
        :state="state"
        :empty-kind="canCreate ? 'no-records' : 'empty-column'"
        skeleton-variant="list"
        :empty-title="copy.emptyTitle"
        :empty-description="copy.emptyDescription"
        :create-label="copy.create"
        :can-create="canCreate"
        @create="emit('create')"
      />

      <LoadMoreRows v-if="state === 'ready'" :data="scrollData">
        <DataGrid
          class="h-full min-h-0 w-full flex-1"
          :preference-key="preferenceKey"
          :default-col-def="defaultColDef"
          :column-defs="columnDefs"
          :row-data="rows"
          :is-row-activatable="isRowActivatable"
          :aria-label="gridLabel"
          @row-activate="emit('open', $event)"
        />
      </LoadMoreRows>
    </div>

    <ConfirmDialog
      v-if="!isReadOnly"
      v-model:open="deleteDialogOpen"
      :title="copy.deleteTitle"
      :description="copy.deleteDescription(pendingDelete)"
      :confirm-label="rowDelete?.label ?? t('i18n.components.data_grid.list_grid.delete')"
      variant="destructive"
      :pending="deletePending"
      @confirm="confirmDelete"
    />
  </div>
</template>
