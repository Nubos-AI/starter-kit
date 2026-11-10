import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import type { ReportDrillDownParams } from '@/types/reports';

export type SelectionMode = 'visible' | 'all-matching';

export type BulkAction = 'set-field' | 'soft-delete' | 'export-csv';

export interface SelectionSortEntry {
    colId: string;
    sort: 'asc' | 'desc';
}

export interface SelectionSnapshot {
    filterModel: Record<string, unknown>;
    sortModel: SelectionSortEntry[];
    search: string | null;
    segmentId: string | null;
    drillDown: ReportDrillDownParams | null;
    matchCount: number | null;
}

export type SnapshotProvider = () => SelectionSnapshot;

export interface VisibleSelectionPayload {
    mode: 'visible';
    includedIds: string[];
}

export interface AllMatchingSelectionPayload {
    mode: 'all-matching';
    filterModel: Record<string, unknown>;
    sortModel: SelectionSortEntry[];
    search: string | null;
    segmentId: string | null;
    drillDown: ReportDrillDownParams | null;
    excludedIds: string[];
}

export type SelectionPayload =
    | VisibleSelectionPayload
    | AllMatchingSelectionPayload;

export interface SelectionSummary {
    mode: SelectionMode;
    count: number | null;
    excludedCount: number;
}

export interface UseRecordSelectionReturn {
    mode: Ref<SelectionMode>;
    selectedIds: Ref<Set<string>>;
    excludedIds: Ref<Set<string>>;
    isSelected: (id: string) => boolean;
    toggle: (id: string) => void;
    clear: () => void;
    selectAllMatching: () => void;
    hasSelection: ComputedRef<boolean>;
    summary: ComputedRef<SelectionSummary>;
    buildSelectionPayload: () => SelectionPayload;
}

export function useRecordSelection(
    snapshotProvider: SnapshotProvider,
): UseRecordSelectionReturn {
    const mode = ref<SelectionMode>('visible');
    const selectedIds = ref<Set<string>>(new Set());
    const excludedIds = ref<Set<string>>(new Set());
    const snapshot = ref<SelectionSnapshot | null>(null);

    const isSelected = (id: string): boolean =>
        mode.value === 'all-matching'
            ? !excludedIds.value.has(id)
            : selectedIds.value.has(id);

    const toggle = (id: string): void => {
        if (mode.value === 'all-matching') {
            const next = new Set(excludedIds.value);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            excludedIds.value = next;

            return;
        }

        const next = new Set(selectedIds.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        selectedIds.value = next;
    };

    const clear = (): void => {
        mode.value = 'visible';
        selectedIds.value = new Set();
        excludedIds.value = new Set();
        snapshot.value = null;
    };

    const selectAllMatching = (): void => {
        snapshot.value = snapshotProvider();
        selectedIds.value = new Set();
        excludedIds.value = new Set();
        mode.value = 'all-matching';
    };

    const hasSelection = computed<boolean>(() =>
        mode.value === 'all-matching'
            ? snapshot.value !== null
            : selectedIds.value.size > 0,
    );

    const summary = computed<SelectionSummary>(() => {
        if (mode.value === 'all-matching') {
            const excludedCount = excludedIds.value.size;
            const matchCount = snapshot.value?.matchCount ?? null;

            return {
                mode: 'all-matching',
                count:
                    matchCount === null
                        ? null
                        : Math.max(0, matchCount - excludedCount),
                excludedCount,
            };
        }

        return {
            mode: 'visible',
            count: selectedIds.value.size,
            excludedCount: 0,
        };
    });

    const buildSelectionPayload = (): SelectionPayload => {
        if (mode.value === 'all-matching') {
            const frozen = snapshot.value;

            return {
                mode: 'all-matching',
                filterModel: frozen?.filterModel ?? {},
                sortModel: frozen?.sortModel ?? [],
                search: frozen?.search ?? null,
                segmentId: frozen?.segmentId ?? null,
                drillDown: frozen?.drillDown ?? null,
                excludedIds: [...excludedIds.value],
            };
        }

        return {
            mode: 'visible',
            includedIds: [...selectedIds.value],
        };
    };

    return {
        mode,
        selectedIds,
        excludedIds,
        isSelected,
        toggle,
        clear,
        selectAllMatching,
        hasSelection,
        summary,
        buildSelectionPayload,
    };
}
