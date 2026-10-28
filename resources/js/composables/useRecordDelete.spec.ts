import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { UseRecordDeleteReturn } from '@/composables/useRecordDelete';
import { useRecordDelete } from '@/composables/useRecordDelete';
import type { RecordPayload } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const errorToast = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: {
        error: (message: string) => errorToast(message),
    },
}));

const recordToastMock = vi.fn();

vi.mock('@/lib/recordToast', () => ({
    recordToast: (action: string) => recordToastMock(action),
}));

const record = {
    id: '01RECORD0K5N3Q8V9WYE6M2H7X',
    recordNumber: 'REC-1',
} as RecordPayload;

function emptyResponse(status: number): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
    } as unknown as Response;
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

beforeEach(() => {
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=test-xsrf-token',
    } as unknown as Document);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useRecordDelete', () => {
    it('starts without a pending record', () => {
        const deletion: UseRecordDeleteReturn = useRecordDelete(vi.fn());

        expect(deletion.pending.value).toBeNull();
        expect(deletion.isOpen.value).toBe(false);
    });

    it('opens the confirmation for the requested record', () => {
        const deletion = useRecordDelete(vi.fn());

        deletion.request(record);

        expect(deletion.pending.value).toStrictEqual(record);
        expect(deletion.isOpen.value).toBe(true);
    });

    it('drops the pending record when the confirmation is cancelled', () => {
        const deletion = useRecordDelete(vi.fn());

        deletion.request(record);
        deletion.cancel();

        expect(deletion.pending.value).toBeNull();
    });

    it('sends the delete request and reports the deletion', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(emptyResponse(204)));
        vi.stubGlobal('fetch', fetchMock);
        const onDeleted = vi.fn();
        const deletion = useRecordDelete(onDeleted);

        deletion.request(record);
        await deletion.confirm();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            `/nubos/engine/records/${record.id}`,
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(onDeleted).toHaveBeenCalledWith(record);
        expect(recordToastMock).toHaveBeenCalledWith('delete');
        expect(deletion.pending.value).toBeNull();
    });

    it('keeps the record and warns when the server refuses', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(emptyResponse(403))),
        );
        const onDeleted = vi.fn();
        const deletion = useRecordDelete(onDeleted);

        deletion.request(record);
        await deletion.confirm();
        await flushPromises();

        expect(onDeleted).not.toHaveBeenCalled();
        expect(errorToast).toHaveBeenCalled();
    });

    it('shows the reason the server sent instead of a generic notice', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(403, {
                        message:
                            'Dieser Datensatz ist gesperrt und kann nicht gelöscht werden.',
                    }),
                ),
            ),
        );
        const deletion = useRecordDelete(vi.fn());

        deletion.request(record);
        await deletion.confirm();
        await flushPromises();

        expect(errorToast).toHaveBeenCalledWith(
            'Dieser Datensatz ist gesperrt und kann nicht gelöscht werden.',
        );
    });

    it('shows the reason the server rejected the deletion reason with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'The given data was invalid.',
                        errors: {
                            deletion_reason: [
                                'Der Löschgrund darf höchstens 255 Zeichen lang sein.',
                            ],
                        },
                    }),
                ),
            ),
        );
        const onDeleted = vi.fn();
        const deletion = useRecordDelete(onDeleted);

        deletion.request(record);
        await deletion.confirm();
        await flushPromises();

        expect(onDeleted).not.toHaveBeenCalled();
        expect(deletion.reasonError.value).toBe(
            'Der Löschgrund darf höchstens 255 Zeichen lang sein.',
        );
    });

    it('ignores a confirmation without a pending record', async () => {
        const fetchMock = vi.fn(() => Promise.resolve(emptyResponse(204)));
        vi.stubGlobal('fetch', fetchMock);
        const deletion = useRecordDelete(vi.fn());

        await deletion.confirm();

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
