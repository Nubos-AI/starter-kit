import { Eye, Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import { actionsColumn } from '@/components/data-grid/actions';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type { RecordPayload } from '@/types/records';

export interface RecordActionLabels {
    edit: string;
    view: string;
    delete: string;
    deleteDenied: string;
}

export interface RecordActionsOptions {
    canUpdate: boolean;
    canDelete: boolean;
    onDelete: (record: RecordPayload) => void;
    labels: RecordActionLabels;
}

export function recordActionsColumn(
    options: RecordActionsOptions,
): ColDef<RecordPayload> {
    return actionsColumn<RecordPayload>(
        [
            {
                icon: options.canUpdate ? Pencil : Eye,
                label: options.canUpdate
                    ? options.labels.edit
                    : options.labels.view,
                variant: options.canUpdate ? 'edit' : 'info',
                testId: options.canUpdate ? 'record-edit' : 'record-view',
                href: (row) =>
                    RecordsController.show.url({ record: recordRouteKey(row) }),
                isDisabled: () => false,
            },
            {
                icon: Trash2,
                label: options.labels.delete,
                variant: 'destructive',
                testId: 'record-delete',
                onClick: (row) => options.onDelete(row),
                isDisabled: () => !options.canDelete,
                disabledReason: () => options.labels.deleteDenied,
            },
        ],
        { pinned: 'right' },
    );
}
