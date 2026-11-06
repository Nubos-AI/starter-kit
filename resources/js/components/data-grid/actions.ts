import type { ColDef } from 'ag-grid-community';
import { actionsWidth } from '@/lib/dataGridDefaults';
import type { RowAction } from '@/types/rowAction';
import { ActionsCellRenderer } from './ActionsCellRenderer';

export const ACTIONS_COL_ID = 'actions';

export function actionsColumn<TRow>(
    actions: RowAction<TRow>[],
    options?: { width?: number; pinned?: 'left' | 'right' },
): ColDef<TRow> {
    return {
        colId: ACTIONS_COL_ID,
        headerName: '',
        sortable: false,
        filter: false,
        resizable: false,
        cellRenderer: ActionsCellRenderer,
        cellRendererParams: { actions },
        type: 'actions',
        width: options?.width ?? actionsWidth(actions.length),
        pinned: options?.pinned ?? 'right',
    };
}
