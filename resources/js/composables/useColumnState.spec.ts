import type { ColumnState, GridReadyEvent } from 'ag-grid-community';
import { describe, expect, it, vi } from 'vitest';
import { useColumnState } from '@/composables/useColumnState';

let onPersist: ReturnType<typeof vi.fn<(state: ColumnState[]) => void>>;

function columnState(initialState: ColumnState[] | null = null) {
    onPersist = vi.fn<(state: ColumnState[]) => void>();

    return useColumnState({ initialState, onPersist });
}

interface FakeApi {
    getColumnState: () => ColumnState[];
    applyColumnState: ReturnType<typeof vi.fn>;
    setColumnsVisible: ReturnType<typeof vi.fn>;
}

function makeApi(): FakeApi {
    const state: ColumnState[] = [
        { colId: 'title', hide: false, sort: null } as ColumnState,
        { colId: 'stage', hide: false, sort: null } as ColumnState,
    ];

    return {
        getColumnState: () => state.map((entry) => ({ ...entry })),
        applyColumnState: vi.fn(
            ({ state: patch }: { state: ColumnState[] }) => {
                for (const p of patch) {
                    const entry = state.find((e) => e.colId === p.colId);

                    if (entry && 'sort' in p) {
                        entry.sort = p.sort ?? null;
                    }
                }
            },
        ),
        setColumnsVisible: vi.fn((colIds: string[], visible: boolean) => {
            for (const id of colIds) {
                const entry = state.find((e) => e.colId === id);

                if (entry) {
                    entry.hide = !visible;
                }
            }
        }),
    };
}

async function ready(cs: ReturnType<typeof useColumnState>, api: FakeApi) {
    await cs.onGridReady({ api } as unknown as GridReadyEvent);
}

describe('useColumnState — sorting (SC-3)', () => {
    it('cycles a column through asc → desc → none', async () => {
        const cs = columnState();
        const api = makeApi();
        await ready(cs, api);

        cs.cycleSort('title');
        expect(
            cs.columnSnapshot.value.find((e) => e.colId === 'title')?.sort,
        ).toBe('asc');

        cs.cycleSort('title');
        expect(
            cs.columnSnapshot.value.find((e) => e.colId === 'title')?.sort,
        ).toBe('desc');

        cs.cycleSort('title');
        expect(
            cs.columnSnapshot.value.find((e) => e.colId === 'title')?.sort,
        ).toBeNull();
    });
});

describe('useColumnState — hide / show (SC-3)', () => {
    it('hides a column and reflects it in the snapshot', async () => {
        const cs = columnState();
        const api = makeApi();
        await ready(cs, api);

        cs.setColumnVisible('stage', false);

        expect(api.setColumnsVisible).toHaveBeenCalledWith(['stage'], false);
        expect(
            cs.columnSnapshot.value.find((e) => e.colId === 'stage')?.hidden,
        ).toBe(true);
    });

    it('shows a hidden column again', async () => {
        const cs = columnState();
        const api = makeApi();
        await ready(cs, api);

        cs.setColumnVisible('stage', false);
        cs.setColumnVisible('stage', true);

        expect(
            cs.columnSnapshot.value.find((e) => e.colId === 'stage')?.hidden,
        ).toBe(false);
    });
});

describe('useColumnState — persistence contract (SC-3 / SC-13 filter state)', () => {
    it('restores the persisted column state when the grid becomes ready', async () => {
        const saved: ColumnState[] = [
            { colId: 'title', hide: true, sort: 'desc' } as ColumnState,
        ];

        const cs = columnState(saved);
        const api = makeApi();
        await ready(cs, api);

        expect(api.applyColumnState).toHaveBeenCalledWith(
            expect.objectContaining({ state: saved, applyOrder: true }),
        );
        expect(cs.loaded.value).toBe(true);
    });

    it('hands every change to the persist callback', async () => {
        const cs = columnState();
        const api = makeApi();
        await ready(cs, api);

        cs.cycleSort('title');

        expect(onPersist).toHaveBeenCalledWith(
            expect.arrayContaining([
                expect.objectContaining({ colId: 'title', sort: 'asc' }),
            ]),
        );
    });
});
