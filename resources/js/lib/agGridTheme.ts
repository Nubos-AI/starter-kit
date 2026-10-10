import { themeQuartz } from 'ag-grid-community';
import type { Theme } from 'ag-grid-community';
import { tableDensityParams } from '@/lib/tableAppearance';
import type { GridDensity } from '@/types/preferences';

export const agGridThemeParams = {
    backgroundColor: 'var(--table-background)',
    foregroundColor: 'var(--table-foreground)',
    accentColor: 'var(--table-accent)',
    borderColor: 'var(--table-border)',
    headerBackgroundColor: 'var(--table-header-background)',
    headerTextColor: 'var(--table-header-foreground)',
    rowHoverColor: 'var(--table-hover)',
    selectedRowBackgroundColor: 'var(--table-selected)',
    borderRadius: 'var(--table-radius)',
    wrapperBorderRadius: 'var(--table-radius)',
    wrapperBorder: true,
    rowBorder: true,
    headerRowBorder: true,
    columnBorder: false,
    headerColumnBorder: false,
    headerColumnResizeHandleColor: 'var(--table-border)',
    pinnedColumnBorder: { color: 'var(--table-border)', width: 1 },
    cellHorizontalPadding: 'var(--table-cell-padding-x)',
    spacing: 4,
    fontSize: 12,
    rowHeight: 32,
    headerHeight: 34,
    headerFontSize: 12,
    headerFontWeight: 500,
    fontFamily: 'inherit',
} as const;

export const agGridDensityParams = tableDensityParams;
export function buildAgGridTheme(density: GridDensity): Theme {
    return themeQuartz.withParams({
        ...agGridThemeParams,
        ...agGridDensityParams[density],
    });
}
