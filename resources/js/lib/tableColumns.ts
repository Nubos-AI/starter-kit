import type { ColTypeDef, ValueFormatterParams } from 'ag-grid-community';
import { formatLocalizedNumber } from '@/lib/localizedNumber';

function formatNumberCell(params: ValueFormatterParams): string {
    return formatLocalizedNumber(params.value);
}

export const tableColumnTypes: Record<string, ColTypeDef> = {
    number: {
        cellClass: 'table-cell-number',
        headerClass: 'table-header-number',
        valueFormatter: formatNumberCell,
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
