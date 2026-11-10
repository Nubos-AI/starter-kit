import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useRowDeletion } from '@/composables/useRowDeletion';

interface VisitOptions {
    preserveScroll?: boolean;
    preserveState?: boolean;
    onSuccess?: unknown;
    onError?: unknown;
    onFinish?: () => void;
}

interface Row {
    id: string;
    name: string;
}

const { deleteMock } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { delete: deleteMock },
}));

const row: Row = { id: '01ROWDEL0K5N3Q8V9WYE6M2H7C', name: 'Alpha' };

function rowUrl(item: Row): string {
    return `/rows/${item.id}`;
}

function lastDeleteCall(): [string, VisitOptions] {
    return deleteMock.mock.calls[deleteMock.mock.calls.length - 1] as [
        string,
        VisitOptions,
    ];
}

describe('useRowDeletion', () => {
    beforeEach(() => {
        deleteMock.mockReset();
    });

    it('opens the dialog for the requested row and closes it again', () => {
        const deletion = useRowDeletion<Row>({ url: rowUrl });

        expect(deletion.deleteDialogOpen.value).toBe(false);

        deletion.requestDelete(row);

        expect(deletion.deleteDialogOpen.value).toBe(true);
        expect(deletion.pendingDelete.value).toEqual(row);

        deletion.deleteDialogOpen.value = false;

        expect(deletion.pendingDelete.value).toBeNull();
    });

    it('sends no request while no row is pending', () => {
        const deletion = useRowDeletion<Row>({ url: rowUrl });

        deletion.confirmDelete();

        expect(deleteMock).not.toHaveBeenCalled();
        expect(deletion.deletePending.value).toBe(false);
    });

    it('deletes the pending row with preserved scroll and resets once finished', () => {
        const deletion = useRowDeletion<Row>({ url: rowUrl });

        deletion.requestDelete(row);
        deletion.confirmDelete();

        const [url, options] = lastDeleteCall();

        expect(url).toBe(`/rows/${row.id}`);
        expect(options.preserveScroll).toBe(true);
        expect(options.preserveState).toBeUndefined();
        expect(deletion.deletePending.value).toBe(true);
        expect(deletion.deleteDialogOpen.value).toBe(true);

        options.onFinish?.();

        expect(deletion.deletePending.value).toBe(false);
        expect(deletion.deleteDialogOpen.value).toBe(false);
    });

    it('passes preserveState and the success and error handlers through to the visit', () => {
        const onSuccess = vi.fn();
        const onError = vi.fn();
        const deletion = useRowDeletion<Row>({
            url: rowUrl,
            preserveState: true,
            onSuccess,
            onError,
        });

        deletion.requestDelete(row);
        deletion.confirmDelete();

        const [, options] = lastDeleteCall();

        expect(options.preserveState).toBe(true);
        expect(options.onSuccess).toBe(onSuccess);
        expect(options.onError).toBe(onError);
    });
});
