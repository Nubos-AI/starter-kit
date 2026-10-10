import type { ColumnState, GridApi, GridReadyEvent } from 'ag-grid-community';
import type { Ref } from 'vue';
import { ref } from 'vue';

export interface ColumnSnapshotEntry {
    colId: string;
    hidden: boolean;
    sort: 'asc' | 'desc' | null;
}

export type ColumnSnapshot = ColumnSnapshotEntry[];

export interface ColumnGridEvent {
    api: GridApi;
    source: string;
}

export interface UseColumnStateOptions {
    initialState: ColumnState[] | null;
    onPersist: (state: ColumnState[]) => void;
}

export interface UseColumnStateReturn {
    onGridReady: (event: GridReadyEvent) => Promise<void>;
    onColumnEvent: (event: ColumnGridEvent) => void;
    columnSnapshot: Ref<ColumnSnapshot>;
    setColumnVisible: (colId: string, visible: boolean) => void;
    cycleSort: (colId: string) => void;
    loaded: Ref<boolean>;
}

export function useColumnState(
    options: UseColumnStateOptions,
): UseColumnStateReturn {
    const loaded = ref<boolean>(false);
    const columnSnapshot = ref<ColumnSnapshot>([]);

    let gridApi: GridApi | null = null;

    const refreshSnapshot = (api: GridApi): void => {
        columnSnapshot.value = api.getColumnState().map((entry) => ({
            colId: entry.colId,
            hidden: entry.hide ?? false,
            sort: entry.sort ?? null,
        }));
    };

    const persist = (): void => {
        if (gridApi === null) {
            return;
        }

        options.onPersist(gridApi.getColumnState());
    };

    const onGridReady = async (event: GridReadyEvent): Promise<void> => {
        gridApi = event.api;

        if (options.initialState !== null) {
            event.api.applyColumnState({
                state: options.initialState,
                applyOrder: true,
                defaultState: { sort: null },
            });
        }

        loaded.value = true;
        refreshSnapshot(event.api);
    };

    const onColumnEvent = (event: ColumnGridEvent): void => {
        if (event.source === 'api') {
            return;
        }

        refreshSnapshot(event.api);
        persist();
    };

    const setColumnVisible = (colId: string, visible: boolean): void => {
        if (gridApi === null) {
            return;
        }

        gridApi.setColumnsVisible([colId], visible);
        refreshSnapshot(gridApi);
        persist();
    };

    const cycleSort = (colId: string): void => {
        if (gridApi === null) {
            return;
        }

        const current = gridApi
            .getColumnState()
            .find((entry) => entry.colId === colId);

        const next: 'asc' | 'desc' | null =
            current?.sort === 'asc'
                ? 'desc'
                : current?.sort === 'desc'
                  ? null
                  : 'asc';

        gridApi.applyColumnState({
            state: [{ colId, sort: next }],
            defaultState: { sort: null },
        });

        refreshSnapshot(gridApi);
        persist();
    };

    return {
        onGridReady,
        onColumnEvent,
        columnSnapshot,
        setColumnVisible,
        cycleSort,
        loaded,
    };
}
