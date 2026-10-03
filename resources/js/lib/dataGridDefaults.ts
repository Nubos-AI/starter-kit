import type { ColDef, GetRowIdParams } from 'ag-grid-community';

export const defaultGridColDef = {
    sortable: true,
    resizable: true,
    filter: true,
} satisfies ColDef;

export function resolveRowId(params: GetRowIdParams): string {
    const data = params.data as { id?: string | number } | undefined;

    return String(data?.id ?? '');
}

export function actionsWidth(count: number): number {
    return count * 44 + 24;
}
