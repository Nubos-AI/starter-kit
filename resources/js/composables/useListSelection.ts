import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';

export interface ListSelection {
    selectedIds: Ref<Set<string>>;
    isSelected: (id: string) => boolean;
    toggle: (id: string) => void;
    clear: () => void;
    count: ComputedRef<number>;
    ids: ComputedRef<string[]>;
    headerState: (
        visibleIds: string[],
        visibleCount?: number,
    ) => boolean | 'indeterminate';
    toggleAll: (visibleIds: string[]) => void;
    prune: (visibleIds: string[]) => void;
}

export function useListSelection(): ListSelection {
    const selectedIds = ref<Set<string>>(new Set());

    const isSelected = (id: string): boolean => selectedIds.value.has(id);

    const toggle = (id: string): void => {
        const next = new Set(selectedIds.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        selectedIds.value = next;
    };

    const clear = (): void => {
        selectedIds.value = new Set();
    };

    const count = computed<number>(() => selectedIds.value.size);

    const ids = computed<string[]>(() => Array.from(selectedIds.value));

    const headerState = (
        visibleIds: string[],
        visibleCount: number = visibleIds.length,
    ): boolean | 'indeterminate' => {
        const selected = selectedIds.value;

        if (visibleIds.length === 0) {
            return false;
        }

        const selectedCount = visibleIds.filter((id) =>
            selected.has(id),
        ).length;

        if (selectedCount === 0) {
            return false;
        }

        return selectedCount === visibleIds.length &&
            visibleIds.length === visibleCount
            ? true
            : 'indeterminate';
    };

    const toggleAll = (visibleIds: string[]): void => {
        const allSelected =
            visibleIds.length > 0 && visibleIds.every((id) => isSelected(id));

        const next = new Set(selectedIds.value);

        for (const id of visibleIds) {
            if (allSelected) {
                next.delete(id);
            } else {
                next.add(id);
            }
        }

        selectedIds.value = next;
    };

    const prune = (visibleIds: string[]): void => {
        const visible = new Set(visibleIds);
        const next = new Set(
            Array.from(selectedIds.value).filter((id) => visible.has(id)),
        );

        selectedIds.value = next;
    };

    return {
        selectedIds,
        isSelected,
        toggle,
        clear,
        count,
        ids,
        headerState,
        toggleAll,
        prune,
    };
}
