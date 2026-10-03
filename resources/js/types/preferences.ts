import type { ColumnState } from 'ag-grid-community';
import type { FilterGroupNode } from '@/composables/useFilterTree';

export type RecordViewMode = 'table' | 'kanban';

export type GridDensity = 'compact' | 'comfortable';

export type Appearance = 'light' | 'dark' | 'system';

export type ConfigurationGrid =
    | 'object-types'
    | 'roles'
    | 'teams'
    | 'trash'
    | 'users';

export type PreferenceCategory =
    | 'columnsAndSorting'
    | 'viewMode'
    | 'filterAndSegment'
    | 'layoutAndAppearance'
    | 'panelState'
    | 'panelVisibility';

export interface GlobalPreferences {
    appearance: Appearance;
    density: GridDensity;
    pageSize: number;
    sidebarOpen: boolean;
    startObjectTypeId: string | null;
}

export interface ObjectTypePreferences {
    viewMode: RecordViewMode | null;
    kanbanAxis: string | null;
    kanbanPipeline: string | null;
    hierarchyOrder: boolean | null;
    columnState: ColumnState[] | null;
    lastSegmentId: string | null;
    lastFilter: FilterGroupNode | null;
    collapsedSections: string[] | null;
    hiddenSections: string[] | null;
}

export interface GridPreferences {
    columnState: ColumnState[] | null;
}

export type PreferencePolicy = Record<
    PreferenceCategory,
    Record<string, boolean>
>;

export interface PreferenceDocument {
    settings: GlobalPreferences;
    objectTypes: Record<string, ObjectTypePreferences>;
    grids: Partial<Record<ConfigurationGrid, GridPreferences>>;
    policy: PreferencePolicy;
}

export interface PreferencePatch {
    settings?: Partial<GlobalPreferences>;
    objectTypes?: Record<string, Partial<ObjectTypePreferences>>;
    grids?: Partial<Record<ConfigurationGrid, Partial<GridPreferences>>>;
}

export const emptyObjectTypePreferences = (): ObjectTypePreferences => ({
    viewMode: null,
    kanbanAxis: null,
    kanbanPipeline: null,
    hierarchyOrder: null,
    columnState: null,
    lastSegmentId: null,
    lastFilter: null,
    collapsedSections: null,
    hiddenSections: null,
});
