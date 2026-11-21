<script setup lang="ts">
import { Check, Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ICellRendererParams, RowClassRules, ValueGetterParams } from 'ag-grid-community';
import { computed, defineComponent, h, shallowRef, watch } from 'vue';
import type { PropType } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { useListSelection } from '@/shared/composables/useListSelection';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import { actionsColumn } from '@/shared/ui/data-grid/actions';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/shared/ui/data-grid/selectionColumn';
import { formatDueAt, isOverdue } from '@/shared/workflow/data/useReminders';
import type { ReminderItem } from '@/shared/workflow/data/useReminders';
import SourceLinkList from '../../../../shared/links/ui/SourceLinkList.vue';

const { t } = useI18n();

const props = defineProps<{
  items: ReminderItem[];
}>();

const emit = defineEmits<{
  edit: [item: ReminderItem];
  complete: [id: string];
  delete: [id: string];
  bulkDelete: [ids: string[]];
}>();

const selection = useListSelection();

watch(
  () => props.items,
  (items) => selection.prune(items.map((item) => item.id)),
);

function onBulkDelete(): void {
  emit('bulkDelete', selection.ids.value);
  selection.clear();
}

const pendingDelete = shallowRef<ReminderItem | null>(null);

const deleteDialogOpen = computed<boolean>({
  get: () => pendingDelete.value !== null,
  set: (value) => {
    if (!value) {
      pendingDelete.value = null;
    }
  },
});

function requestDelete(item: ReminderItem): void {
  pendingDelete.value = item;
}

function confirmDelete(): void {
  const item = pendingDelete.value;

  if (item === null) {
    return;
  }

  pendingDelete.value = null;
  emit('delete', item.id);
}

const LinksCellRenderer = defineComponent({
  name: 'ReminderLinksCell',
  props: {
    params: {
      type: Object as PropType<ICellRendererParams<ReminderItem>>,
      required: true,
    },
  },
  setup(cellProps) {
    return () => {
      const item = cellProps.params.data;
      const links = item?.links ?? [];

      if (links.length > 0) {
        return h(SourceLinkList, { links });
      }

      const record = item?.record ?? null;

      if (record?.deleted) {
        return h(
          'span',
          {
            class: 'text-muted-foreground',
            'data-reminder-deleted-record': '',
          },
          t('i18n.components.engine.reminders.reminder_grid.deleted', {
            value1: record.label ?? t('i18n.components.engine.reminders.reminder_grid.record'),
          }),
        );
      }

      return h('span', { class: 'text-muted-foreground' }, '—');
    };
  },
});

const columnDefs: ColDef<ReminderItem>[] = [
  selectionColumn<ReminderItem>(selection),
  {
    field: 'subject',
    headerName: t('i18n.components.engine.reminders.reminder_grid.subject'),
    flex: 2,
  },
  {
    colId: 'dueAt',
    headerName: t('i18n.components.engine.reminders.reminder_grid.due_date'),
    flex: 1,
    valueGetter: (params: ValueGetterParams<ReminderItem>): string => formatDueAt(params.data?.dueAt ?? null, t),
    cellClassRules: {
      'table-cell-danger table-cell-emphasis': (params) => (params.data ? isOverdue(params.data) : false),
    },
  },
  {
    colId: 'type',
    headerName: t('i18n.components.engine.reminders.reminder_grid.type'),
    flex: 1,
    valueGetter: (params: ValueGetterParams<ReminderItem>): string => params.data?.type?.label ?? '—',
  },
  {
    colId: 'links',
    headerName: t('i18n.components.engine.reminders.reminder_grid.related_to'),
    flex: 1,
    sortable: false,
    cellRenderer: LinksCellRenderer,
  },
  actionsColumn<ReminderItem>([
    {
      icon: Pencil,
      label: t('i18n.components.engine.reminders.reminder_grid.edit'),
      variant: 'edit',
      testId: 'reminder-edit',
      onClick: (item) => emit('edit', item),
    },
    {
      icon: Check,
      label: t('i18n.components.engine.reminders.reminder_grid.completed'),
      testId: 'reminder-complete',
      onClick: (item) => emit('complete', item.id),
    },
    {
      icon: Trash2,
      label: t('i18n.components.engine.reminders.reminder_grid.delete'),
      variant: 'destructive',
      testId: 'reminder-delete',
      onClick: requestDelete,
    },
  ]),
];

const rowClassRules: RowClassRules<ReminderItem> = {
  'table-row-overdue': (params): boolean => (params.data ? isOverdue(params.data) : false),
};

const getRowId = (params: { data: ReminderItem }): string => params.data.id;
</script>

<template>
  <div class="flex h-full w-full flex-col gap-2">
    <SelectionBulkBar
      :count="selection.count.value"
      :delete-label="t('i18n.components.engine.reminders.reminder_grid.delete_reminders')"
      @delete="onBulkDelete"
      @clear="selection.clear"
    />

    <DataGrid
      class="h-full w-full flex-1"
      :column-defs="columnDefs"
      :row-data="props.items"
      :row-class-rules="rowClassRules"
      :get-row-id="getRowId"
      :animate-rows="true"
      :is-row-activatable="() => true"
      @row-activate="(item: ReminderItem) => emit('edit', item)"
    />

    <ConfirmDialog
      v-model:open="deleteDialogOpen"
      variant="destructive"
      :title="t('i18n.components.engine.reminders.reminder_grid.delete_reminder')"
      :description="
        t('i18n.components.engine.reminders.reminder_grid.the_reminder_will_be_deleted', {
          value1: pendingDelete?.subject ?? '',
        })
      "
      :confirm-label="t('i18n.components.engine.reminders.reminder_grid.delete')"
      @confirm="confirmDelete"
    />
  </div>
</template>
