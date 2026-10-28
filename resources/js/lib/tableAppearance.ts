import type { GridDensity } from '@/types/preferences';

export const tableDensityParams = {
    compact: {
        spacing: 4,
        fontSize: 14,
        rowHeight: 44,
        headerHeight: 42,
        headerFontSize: 13,
    },
    comfortable: {
        spacing: 6,
        fontSize: 15,
        rowHeight: 54,
        headerHeight: 46,
        headerFontSize: 13,
    },
} as const satisfies Record<GridDensity, Record<string, number>>;
