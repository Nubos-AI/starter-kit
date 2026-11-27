import type { ColDef } from 'ag-grid-community';
import { actionsWidth } from '@/shared/lib/dataGridDefaults';
import type { RowAction } from '@/shared/types/rowAction';
import { ActionsCellRenderer } from './ActionsCellRenderer';

export const ACTIONS_COL_ID = 'actions';

export function actionsColumn<TRow>(
  actions: RowAction<TRow>[],
  options?: { width?: number; pinned?: 'left' | 'right'; extensionPoint?: string },
): ColDef<TRow> {
  return {
    colId: ACTIONS_COL_ID,
    headerName: '',
    sortable: false,
    filter: false,
    resizable: false,
    cellRenderer: ActionsCellRenderer,
    cellRendererParams: { actions, extensionPoint: options?.extensionPoint },
    type: 'actions',
    width: options?.width ?? actionsWidth(actions.length + (options?.extensionPoint === undefined ? 0 : 1)),
    pinned: options?.pinned ?? 'right',
  };
}
