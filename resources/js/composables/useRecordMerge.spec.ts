import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useRecordMerge } from '@/composables/useRecordMerge';
import type { MergePlan, MergeRequestPayload } from '@/types/merge';
import { setUrlDefaults } from '@/wayfinder';

const toastError = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: { error: (...args: unknown[]) => toastError(...args) },
}));

const RECORD_ID = '01RECORD0K5N3Q8V9WYE6M2H7X';

const payload: MergeRequestPayload = {
    targetId: RECORD_ID,
    sourceId: '01SOURCE0K5N3Q8V9WYE6M2H7X',
    reason: null,
    overrides: {},
};

const plan = {
    target_id: RECORD_ID,
    source_id: payload.sourceId,
    target_version: 1,
    source_version: 1,
    mode: 'allow',
    rule_id: null,
    rule_name: null,
    deny_reason: null,
    requires_reason: false,
    is_mergeable: true,
    fields: [],
    transfers: [],
    blockers: [],
} as MergePlan;

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
    toastError.mockClear();
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useRecordMerge — the preview', () => {
    it('posts to the preview endpoint of this record and keeps the plan', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(200, { data: plan })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const merge = useRecordMerge(vi.fn());

        await merge.preview(RECORD_ID, payload);

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toContain(`/engine/records/${RECORD_ID}/merge/preview`);
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual(payload);
        expect(merge.plan.value).toEqual(plan);
        expect(merge.previewing.value).toBe(false);
    });

    it('reports a failing preview and leaves the plan alone', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(500, {}))),
        );

        const merge = useRecordMerge(vi.fn());

        await merge.preview(RECORD_ID, payload);

        expect(toastError).toHaveBeenCalledTimes(1);
        expect(merge.plan.value).toBeNull();
    });

    it('shows the reason the server refused the preview with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Die Zieldatensätze passen nicht zusammen.',
                        errors: { sourceId: ['Ungültiger Datensatz.'] },
                    }),
                ),
            ),
        );

        const merge = useRecordMerge(vi.fn());

        await merge.preview(RECORD_ID, payload);

        expect(toastError).toHaveBeenCalledWith(
            'Die Zieldatensätze passen nicht zusammen.',
        );
    });

    it('survives a network failure without leaving the flag set', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('offline'))),
        );
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        const merge = useRecordMerge(vi.fn());

        await merge.preview(RECORD_ID, payload);

        expect(toastError).toHaveBeenCalledTimes(1);
        expect(merge.previewing.value).toBe(false);
    });
});

describe('useRecordMerge — the merge', () => {
    it('hands the result to the caller once the merge was accepted', async () => {
        const result = {
            merge_id: 'm-1',
            target_id: RECORD_ID,
            source_id: payload.sourceId,
        };

        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(201, { data: result }))),
        );

        const onMerged = vi.fn();
        const merge = useRecordMerge(onMerged);

        const returned = await merge.merge(RECORD_ID, payload);

        expect(returned).toEqual(result);
        expect(onMerged).toHaveBeenCalledWith(result);
        expect(merge.merging.value).toBe(false);
    });

    it('treats a refused merge as a failure and calls nobody back', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        blockers: [{ reason: 'rule_forbids', detail: 'Nein.' }],
                    }),
                ),
            ),
        );

        const onMerged = vi.fn();
        const merge = useRecordMerge(onMerged);

        expect(await merge.merge(RECORD_ID, payload)).toBeNull();
        expect(onMerged).not.toHaveBeenCalled();
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it('shows the reason the server refused the merge with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Das Zusammenführen wurde abgelehnt.',
                        blockers: [{ reason: 'rule_forbids', detail: 'Nein.' }],
                    }),
                ),
            ),
        );

        const merge = useRecordMerge(vi.fn());

        await merge.merge(RECORD_ID, payload);

        expect(toastError).toHaveBeenCalledWith(
            'Das Zusammenführen wurde abgelehnt.',
        );
    });

    it('refuses to fire twice while one merge is still running', async () => {
        let release: (value: Response) => void = () => undefined;

        const fetchMock = vi.fn(
            () =>
                new Promise<Response>((resolve) => {
                    release = resolve;
                }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const merge = useRecordMerge(vi.fn());

        const first = merge.merge(RECORD_ID, payload);
        const second = await merge.merge(RECORD_ID, payload);

        expect(second).toBeNull();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        release(
            jsonResponse(201, {
                data: {
                    merge_id: 'm-1',
                    target_id: RECORD_ID,
                    source_id: payload.sourceId,
                },
            }),
        );

        await first;
    });
});

describe('useRecordMerge — taking a merge back', () => {
    it('posts to the undo endpoint of this record and returns the report', async () => {
        const report = {
            merge_id: 'm-1',
            target_id: RECORD_ID,
            source_id: payload.sourceId,
            restored_fields: ['volume'],
            kept_fields: [],
            restored_transfers: { notes: 2 },
            unrecoverable: { links: 1 },
        };

        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(200, { data: report })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const merge = useRecordMerge(vi.fn());

        expect(await merge.undo(RECORD_ID, 'm-1')).toEqual(report);

        const [url] = fetchMock.mock.calls[0] as unknown as [string];

        expect(url).toContain(`/engine/records/${RECORD_ID}/merge/m-1/undo`);
        expect(merge.undoing.value).toBe(false);
    });

    it('reports a refused undo instead of pretending it worked', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, { reason: 'window_expired' }),
                ),
            ),
        );

        const merge = useRecordMerge(vi.fn());

        expect(await merge.undo(RECORD_ID, 'm-1')).toBeNull();
        expect(toastError).toHaveBeenCalledTimes(1);
    });

    it('shows the reason the server refused the undo with', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message:
                            'Das Zusammenführen lässt sich nicht mehr zurücknehmen.',
                        reason: 'window_expired',
                    }),
                ),
            ),
        );

        const merge = useRecordMerge(vi.fn());

        await merge.undo(RECORD_ID, 'm-1');

        expect(toastError).toHaveBeenCalledWith(
            'Das Zusammenführen lässt sich nicht mehr zurücknehmen.',
        );
    });
});
