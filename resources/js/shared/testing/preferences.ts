import type { PreferenceDocument } from '@/shared/types/preferences';

export function emptyPreferenceDocument(): PreferenceDocument {
  return {
    settings: {
      appearance: 'system',
      density: 'compact',
      pageSize: 100,
      sidebarOpen: true,
      startObjectTypeId: null,
    },
    objectTypes: {},
    grids: {},
    policy: {
      columnsAndSorting: { records: true, configuration: true },
      viewMode: { records: true },
      filterAndSegment: { records: false },
      layoutAndAppearance: { global: true },
      panelState: { records: true },
      panelVisibility: { records: true },
    },
  };
}
