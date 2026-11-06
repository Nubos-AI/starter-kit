import type { Errors } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import type { Ref, ShallowRef, WritableComputedRef } from 'vue';
import { computed, ref, shallowRef } from 'vue';

export interface RowDeletionOptions<TRow> {
    url: (row: TRow) => string;
    preserveState?: boolean;
    onSuccess?: () => void;
    onError?: (errors: Errors) => void;
}

export interface RowDeletion<TRow> {
    pendingDelete: ShallowRef<TRow | null>;
    deletePending: Ref<boolean>;
    deleteDialogOpen: WritableComputedRef<boolean>;
    requestDelete: (row: TRow) => void;
    confirmDelete: () => void;
}

export function useRowDeletion<TRow>(
    options: RowDeletionOptions<TRow>,
): RowDeletion<TRow> {
    const pendingDelete = shallowRef<TRow | null>(null);
    const deletePending = ref<boolean>(false);

    const deleteDialogOpen = computed<boolean>({
        get: () => pendingDelete.value !== null,
        set: (value) => {
            if (!value) {
                pendingDelete.value = null;
            }
        },
    });

    const requestDelete = (row: TRow): void => {
        pendingDelete.value = row;
    };

    const confirmDelete = (): void => {
        const row = pendingDelete.value;

        if (row === null) {
            return;
        }

        deletePending.value = true;

        router.delete(options.url(row), {
            preserveScroll: true,
            preserveState: options.preserveState,
            onSuccess: options.onSuccess,
            onError: options.onError,
            onFinish: () => {
                deletePending.value = false;
                pendingDelete.value = null;
            },
        });
    };

    return {
        pendingDelete,
        deletePending,
        deleteDialogOpen,
        requestDelete,
        confirmDelete,
    };
}
