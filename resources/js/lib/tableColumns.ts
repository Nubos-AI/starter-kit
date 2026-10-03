import type { ColTypeDef } from 'ag-grid-community';

export const tableColumnTypes: Record<string, ColTypeDef> = {
    number: {
        cellClass: 'table-cell-number',
        headerClass: 'table-header-number',
    },
    emphasis: { cellClass: 'table-cell-emphasis' },
    danger: { cellClass: 'table-cell-danger' },
    control: { cellClass: 'table-cell-control' },
    selection: {
        cellClass: 'table-cell-selection',
        headerClass: 'table-cell-selection',
    },
    actions: {
        cellClass: 'table-cell-actions',
        headerClass: 'table-cell-actions',
    },
};

export const tableOwnedOptions = new Set([
    'theme',
    'columnTypes',
    'rowHeight',
    'headerHeight',
    'getRowHeight',
    'rowStyle',
    'getRowStyle',
    'headerStyle',
    'getHeaderHeight',
    'groupHeaderHeight',
    'floatingFiltersHeight',
]);

export function isTableOwnedOption(key: string): boolean {
    return tableOwnedOptions.has(
        key.replace(/-([a-z])/g, (_, letter: string) => letter.toUpperCase()),
    );
}
