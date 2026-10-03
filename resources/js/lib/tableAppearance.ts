import type { GridDensity } from '@/types/preferences';

export const tableDensityParams = {
    compact: {
        spacing: 4,
        fontSize: 12,
        rowHeight: 32,
        headerHeight: 34,
        headerFontSize: 12,
    },
    comfortable: {
        spacing: 6,
        fontSize: 13,
        rowHeight: 40,
        headerHeight: 42,
        headerFontSize: 13,
    },
} as const satisfies Record<GridDensity, Record<string, number>>;
