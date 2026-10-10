import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useRecordDuplicate } from '@/composables/useRecordDuplicate';
import type { RecordPayload } from '@/types/records';

const toastError = vi.fn();
const recordToast = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@/lib/recordToast', () => ({
    recordToast: (...args: unknown[]) => recordToast(...args),
}));

const record = {
    id: '01RECORD0K5N3Q8V9WYE6M2H7X',
    objectTypeId: 'ot-1',
    stageId: null,
    recordNumber: 'REC-1',
    title: 'Angebot A',
    externalReferenceId: null,
    version: 3,
    data: {},
} as unknown as RecordPayload;

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

beforeEach(() => {
    toastError.mockClear();
    recordToast.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useRecordDuplicate', () => {
    it('posts to the duplicate endpoint of this record', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(
                jsonResponse(201, { data: { ...record, id: 'copy-1' } }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const onDuplicated = vi.fn();
        const { duplicate } = useRecordDuplicate(onDuplicated);

        await duplicate(record);

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toContain(record.id);
        expect(url).toContain('duplicate');
        expect(init.method).toBe('POST');
    });

    it('hands the copy back to the caller', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(201, { data: { ...record, id: 'copy-1' } }),
                ),
            ),
        );

        const onDuplicated = vi.fn();
        const { duplicate } = useRecordDuplicate(onDuplicated);

        await duplicate(record);

        expect(onDuplicated).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'copy-1' }),
        );
        expect(recordToast).toHaveBeenCalledWith('create');
    });

    it('warns and hands nothing back when the server refuses', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(403, { message: 'no' }))),
        );

        const onDuplicated = vi.fn();
        const { duplicate } = useRecordDuplicate(onDuplicated);

        await duplicate(record);

        expect(onDuplicated).not.toHaveBeenCalled();
        expect(toastError).toHaveBeenCalled();
    });

    it('shows the reason the server refused the duplicate with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Das Pflichtfeld „Titel" fehlt in der Kopie.',
                        errors: { 'data.title': ['Titel ist erforderlich.'] },
                    }),
                ),
            ),
        );

        const { duplicate } = useRecordDuplicate(vi.fn());

        await duplicate(record);

        expect(toastError).toHaveBeenCalledWith(
            'Das Pflichtfeld „Titel" fehlt in der Kopie.',
        );
    });

    it('asks the server only once while a duplicate is still running', async () => {
        let resolveFetch: (value: Response) => void = () => undefined;
        const fetchMock = vi.fn(
            () =>
                new Promise<Response>((resolve) => {
                    resolveFetch = resolve;
                }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { duplicate, duplicating } = useRecordDuplicate(vi.fn());

        const first = duplicate(record);
        await duplicate(record);

        expect(duplicating.value).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveFetch(jsonResponse(201, { data: { ...record, id: 'copy-1' } }));
        await first;

        expect(duplicating.value).toBe(false);
    });
});
