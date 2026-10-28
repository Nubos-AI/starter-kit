<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ICellRendererParams,
    RowClassRules,
    ValueGetterParams,
} from 'ag-grid-community';
import { defineComponent, h, watch } from 'vue';
import type { PropType } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { formatDueAt, isOverdue } from '@/composables/useReminders';
import type { ReminderItem } from '@/composables/useReminders';

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

const RecordCellRenderer = defineComponent({
    name: 'ReminderRecordCell',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<ReminderItem>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const record = cellProps.params.data?.record ?? null;

            if (record === null) {
                return h('span', { class: 'text-muted-foreground' }, '—');
            }

            const label =
                record.label ??
                t('i18n.components.engine.reminders.reminder_grid.record');

            return h(
                Link,
                {
                    href: RecordsController.show.url({ record: record.id }),
                    'data-reminder-record-link': '',
                    class: 'text-primary underline-offset-4 hover:underline',
                },
                () =>
                    record.deleted
                        ? t(
                              'i18n.components.engine.reminders.reminder_grid.deleted',
                              { value1: label },
                          )
                        : label,
            );
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
        headerName: t(
            'i18n.components.engine.reminders.reminder_grid.due_date',
        ),
        flex: 1,
        valueGetter: (params: ValueGetterParams<ReminderItem>): string =>
            formatDueAt(params.data?.dueAt ?? null),
        cellClassRules: {
            'table-cell-danger table-cell-emphasis': (params) =>
                params.data ? isOverdue(params.data) : false,
        },
    },
    {
        colId: 'type',
        headerName: t('i18n.components.engine.reminders.reminder_grid.type'),
        flex: 1,
        valueGetter: (params: ValueGetterParams<ReminderItem>): string =>
            params.data?.type?.label ?? '—',
    },
    {
        colId: 'record',
        headerName: t('i18n.components.engine.reminders.reminder_grid.record'),
        flex: 1,
        sortable: false,
        cellRenderer: RecordCellRenderer,
    },
    actionsColumn<ReminderItem>([
        {
            icon: Check,
            label: t(
                'i18n.components.engine.reminders.reminder_grid.completed',
            ),
            testId: 'reminder-complete',
            onClick: (item) => emit('complete', item.id),
        },
        {
            icon: Pencil,
            label: t('i18n.components.engine.reminders.reminder_grid.edit'),
            variant: 'edit',
            testId: 'reminder-edit',
            onClick: (item) => emit('edit', item),
        },
        {
            icon: Trash2,
            label: t('i18n.components.engine.reminders.reminder_grid.delete'),
            variant: 'destructive',
            testId: 'reminder-delete',
            onClick: (item) => emit('delete', item.id),
        },
    ]),
];

const rowClassRules: RowClassRules<ReminderItem> = {
    'table-row-overdue': (params): boolean =>
        params.data ? isOverdue(params.data) : false,
};

const getRowId = (params: { data: ReminderItem }): string => params.data.id;
</script>

<template>
    <div class="flex h-full w-full flex-col gap-2">
        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Erinnerungen löschen"
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
    </div>
</template>
