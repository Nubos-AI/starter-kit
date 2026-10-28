import { Eye, Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import { actionsColumn } from '@/components/data-grid/actions';
import { recordRouteKey } from '@/lib/recordRouteKey';
import type { RecordPayload } from '@/types/records';

const DELETE_DENIED_REASON = 'Keine Berechtigung zum Löschen.';

export interface RecordActionsOptions {
    canUpdate: boolean;
    canDelete: boolean;
    onDelete: (record: RecordPayload) => void;
}

export function recordActionsColumn(
    options: RecordActionsOptions,
): ColDef<RecordPayload> {
    return actionsColumn<RecordPayload>(
        [
            {
                icon: options.canUpdate ? Pencil : Eye,
                label: options.canUpdate ? 'Bearbeiten' : 'Ansehen',
                variant: options.canUpdate ? 'edit' : 'info',
                testId: options.canUpdate ? 'record-edit' : 'record-view',
                href: (row) =>
                    RecordsController.show.url({ record: recordRouteKey(row) }),
                isDisabled: () => false,
            },
            {
                icon: Trash2,
                label: 'Löschen',
                variant: 'destructive',
                testId: 'record-delete',
                onClick: (row) => options.onDelete(row),
                isDisabled: () => !options.canDelete,
                disabledReason: () => DELETE_DENIED_REASON,
            },
        ],
        { pinned: 'right' },
    );
}
