import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useReportPreview } from '@/composables/useReportPreview';
import { preview as previewRoute } from '@/routes/reports';
import type { ReportResult } from '@/types/reports';

interface Deferred<T> {
    promise: Promise<T>;
    resolve: (value: T) => void;
    reject: (reason: unknown) => void;
}

interface ScopedPreview {
    preview: ReturnType<typeof useReportPreview>;
    stop: () => void;
}

type FetchHandler = (input: string, init: RequestInit) => Promise<Response>;

type FetchMock = ReturnType<typeof stubFetch>;

const PAYLOAD: Record<string, unknown> = {
    object_type_id: '01OBJECTTYPE000000000001',
    filter_definition: null,
    aggregation_type: 'count',
    aggregation_field_key: null,
    group_by_field_key: 'stage',
    group_by_bucket: null,
    series_field_key: null,
};

function result(overrides: Partial<ReportResult> = {}): ReportResult {
    return {
        aggregation: 'count',
        rows: [
            {
                group_value: 'won',
                series_value: null,
                value: '6',
                record_count: 6,
                discarded_value_count: 0,
                is_other_group: false,
                is_other_series: false,
            },
        ],
        total: '6',
        record_count: 6,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;
    let reject: (reason: unknown) => void = () => undefined;

    const promise = new Promise<T>((resolveFn, rejectFn) => {
        resolve = resolveFn;
        reject = rejectFn;
    });

    return { promise, resolve, reject };
}

function scoped(): ScopedPreview {
    const scope = effectScope();
    const created = scope.run(() => useReportPreview());

    if (created === undefined) {
        throw new Error('The preview composable did not run inside the scope.');
    }

    return { preview: created, stop: () => scope.stop() };
}

function stubFetch(handler: FetchHandler) {
    const mock = vi.fn(handler);

    vi.stubGlobal('fetch', mock);

    return mock;
}

function fetchCall(fetchMock: FetchMock, index = 0): RequestInit {
    const call = fetchMock.mock.calls[index];

    if (call === undefined) {
        throw new Error(`No fetch call at index ${index}`);
    }

    const init = call[1];

    if (init === null || typeof init !== 'object') {
        throw new Error('The preview request carries no request init');
    }

    return init;
}

function sentBody(fetchMock: FetchMock, index = 0): unknown {
    const body = fetchCall(fetchMock, index).body;

    if (typeof body !== 'string') {
        throw new Error('The preview request carries no JSON body');
    }

    return JSON.parse(body);
}

function headersOf(fetchMock: FetchMock, index = 0): Record<string, string> {
    const headers = fetchCall(fetchMock, index).headers;

    if (headers === null || typeof headers !== 'object') {
        throw new Error('The preview request carries no headers');
    }

    const flat: Record<string, string> = {};

    Object.entries(headers).forEach(([name, value]) => {
        flat[name] = String(value);
    });

    return flat;
}

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=preview-xsrf-token';
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useReportPreview — request', () => {
    it('starts idle and carries neither a result nor a refusal', () => {
        const { preview, stop } = scoped();

        expect(preview.state.value).toBe('idle');
        expect(preview.result.value).toBeNull();
        expect(preview.refusal.value).toBeNull();

        stop();
    });

    it('reports loading while the request is in flight and settled afterwards', async () => {
        const pending = deferred<Response>();
        stubFetch(() => pending.promise);

        const { preview, stop } = scoped();

        const run = preview.run(PAYLOAD);

        expect(preview.state.value).toBe('loading');

        pending.resolve(jsonResponse(200, { data: result() }));
        await run;

        expect(preview.state.value).toBe('settled');

        stop();
    });

    it('posts the definition to the shared execution endpoint', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(200, { data: result() })),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(fetchMock.mock.calls[0][0]).toBe(previewRoute.url());
        expect(fetchCall(fetchMock).method).toBe('POST');
        expect(fetchCall(fetchMock).credentials).toBe('same-origin');
        expect(sentBody(fetchMock)).toEqual(PAYLOAD);

        stop();
    });

    it('sends the shared request headers including the XSRF token', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(200, { data: result() })),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        const headers = headersOf(fetchMock);

        expect(headers['Content-Type']).toBe('application/json');
        expect(headers.Accept).toBe('application/json');
        expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
        expect(headers['X-XSRF-TOKEN']).toBe('preview-xsrf-token');

        stop();
    });

    it('never sends a report id, so the preview shows the edited definition', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(200, { data: result() })),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(Object.keys(sentBody(fetchMock) ?? {})).not.toContain(
            'report_id',
        );

        stop();
    });
});

describe('useReportPreview — answers', () => {
    it('reads the result out of the data envelope and never off the root', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: result(),
                    rows: [],
                    record_count: 0,
                }),
            ),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(preview.result.value?.record_count).toBe(6);
        expect(preview.result.value?.rows).toHaveLength(1);
        expect(preview.refusal.value).toBeNull();

        stop();
    });

    it('turns a 422 into the refusal reason and drops the English message', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(422, {
                    message: 'Grouping by a time span requires a date.',
                    reason: 'unsupported_grouping',
                }),
            ),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(preview.refusal.value).toEqual({
            reason: 'unsupported_grouping',
        });
        expect(preview.result.value).toBeNull();
        expect(preview.state.value).toBe('settled');

        stop();
    });

    it('turns a forbidden, an expired session and a server fault into the fallback refusal', async () => {
        for (const status of [403, 419, 500]) {
            stubFetch(() =>
                Promise.resolve(jsonResponse(status, { message: 'nope' })),
            );

            const { preview, stop } = scoped();

            await preview.run(PAYLOAD);

            expect(preview.refusal.value).toEqual({ reason: '' });
            expect(preview.result.value).toBeNull();
            expect(preview.state.value).toBe('settled');

            stop();
        }
    });

    it('turns a broken connection into the fallback refusal instead of a stuck skeleton', async () => {
        stubFetch(() => Promise.reject(new TypeError('Failed to fetch')));

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(preview.refusal.value).toEqual({ reason: '' });
        expect(preview.result.value).toBeNull();
        expect(preview.state.value).toBe('settled');

        stop();
    });

    it('turns an aborted request into the fallback refusal', async () => {
        stubFetch(() =>
            Promise.reject(new DOMException('Aborted', 'AbortError')),
        );

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(preview.refusal.value).toEqual({ reason: '' });
        expect(preview.state.value).toBe('settled');

        stop();
    });

    it('clears a previous refusal when the next run succeeds', async () => {
        const answers: Response[] = [
            jsonResponse(422, { reason: 'unknown_field' }),
            jsonResponse(200, { data: result() }),
        ];
        stubFetch(() => {
            const next = answers.shift();

            if (next === undefined) {
                throw new Error('Unexpected third request');
            }

            return Promise.resolve(next);
        });

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);
        expect(preview.refusal.value).toEqual({ reason: 'unknown_field' });

        await preview.run(PAYLOAD);

        expect(preview.refusal.value).toBeNull();
        expect(preview.result.value?.record_count).toBe(6);

        stop();
    });

    it('resets back to the idle state on request', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const { preview, stop } = scoped();

        await preview.run(PAYLOAD);

        expect(preview.result.value).not.toBeNull();

        preview.reset();

        expect(preview.state.value).toBe('idle');
        expect(preview.result.value).toBeNull();
        expect(preview.refusal.value).toBeNull();

        stop();
    });
});

describe('useReportPreview — overlapping runs', () => {
    it('lets the newest run win and discards the answer of the one it replaced', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        const pending = [first, second];

        stubFetch(() => {
            const next = pending.shift();

            if (next === undefined) {
                throw new Error('Unexpected third request');
            }

            return next.promise;
        });

        const { preview, stop } = scoped();

        const firstRun = preview.run(PAYLOAD);
        const secondRun = preview.run(PAYLOAD);

        second.resolve(
            jsonResponse(200, {
                data: result({ record_count: 2, total: '2' }),
            }),
        );
        await secondRun;

        first.resolve(
            jsonResponse(200, {
                data: result({ record_count: 99, total: '99' }),
            }),
        );
        await firstRun;

        expect(preview.result.value?.record_count).toBe(2);
        expect(preview.refusal.value).toBeNull();

        stop();
    });

    it('aborts the request it replaced', async () => {
        const first = deferred<Response>();
        const second = deferred<Response>();
        const pending = [first, second];

        const fetchMock = stubFetch(() => {
            const next = pending.shift();

            if (next === undefined) {
                throw new Error('Unexpected third request');
            }

            return next.promise;
        });

        const { preview, stop } = scoped();

        const firstRun = preview.run(PAYLOAD);
        const secondRun = preview.run(PAYLOAD);

        expect(fetchCall(fetchMock, 0).signal?.aborted).toBe(true);
        expect(fetchCall(fetchMock, 1).signal?.aborted).toBe(false);

        second.resolve(jsonResponse(200, { data: result() }));
        first.resolve(jsonResponse(200, { data: result() }));

        await Promise.all([firstRun, secondRun]);

        stop();
    });

    it('aborts an in flight request when its scope is torn down mid flight', async () => {
        const pending = deferred<Response>();
        const fetchMock = stubFetch(() => pending.promise);

        const { preview, stop } = scoped();

        const run = preview.run(PAYLOAD);

        stop();

        expect(fetchCall(fetchMock).signal?.aborted).toBe(true);

        pending.resolve(jsonResponse(200, { data: result() }));
        await run;

        expect(preview.result.value).toBeNull();
    });
});
