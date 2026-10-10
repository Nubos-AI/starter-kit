import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useFormulaBackfill } from '@/composables/useFormulaBackfill';
import type { BackfillStatus } from '@/types/formulas';

const OBJECT_TYPE = 'contacts';

const RUN_ID = '01JQZ3F5H8K2M4N6P8R0S2T4V6';

const POLL_INTERVAL_MS = 1500;

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        message: vi.fn(),
    },
    Toaster: { name: 'ToasterStub', render: () => null },
}));

vi.mock(
    '@/actions/App/Http/Controllers/Formulas/FormulaBackfillsController',
    () => {
        const parameterOf = (arg: unknown, key: string): string =>
            typeof arg === 'object' && arg !== null
                ? String((arg as Record<string, unknown>)[key])
                : String(arg);

        return {
            show: {
                url: (arg: unknown) =>
                    `/engine/formula-backfills/${parameterOf(arg, 'objectType')}`,
                method: 'get',
            },
            cancel: {
                url: (arg: unknown) =>
                    `/engine/formula-backfills/${parameterOf(arg, 'backfillRun')}/cancel`,
                method: 'post',
            },
        };
    },
);

interface RunPayload {
    id: string;
    status: BackfillStatus;
    totalCount: number;
    processedCount: number;
    errorCount: number;
    finishedAt: string | null;
}

function runPayload(overrides: Partial<RunPayload> = {}): RunPayload {
    return {
        id: RUN_ID,
        status: 'running',
        totalCount: 120,
        processedCount: 40,
        errorCount: 0,
        finishedAt: null,
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

interface FetchScenario {
    ticks?: (RunPayload | null)[];
    statusCode?: number;
    cancelResponse?: () => Promise<Response>;
}

function installFetch(scenario: FetchScenario): ReturnType<typeof vi.fn> {
    const ticks = scenario.ticks ?? [];
    let index = 0;

    const fetchMock = vi.fn((url: unknown) => {
        const requested = String(url);

        if (requested.endsWith('/cancel')) {
            if (scenario.cancelResponse !== undefined) {
                return scenario.cancelResponse();
            }

            return Promise.resolve(
                jsonResponse(200, {
                    run: runPayload({
                        status: 'cancelled',
                        finishedAt: '2026-07-29T10:00:00+00:00',
                    }),
                }),
            );
        }

        const status = scenario.statusCode ?? 200;

        if (status !== 200) {
            return Promise.resolve(jsonResponse(status, {}));
        }

        const tick =
            ticks.length === 0
                ? null
                : ticks[Math.min(index, ticks.length - 1)];
        index += 1;

        return Promise.resolve(jsonResponse(200, { run: tick }));
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function statusCalls(fetchMock: ReturnType<typeof vi.fn>): unknown[][] {
    return fetchMock.mock.calls.filter(
        (call) => !String(call[0]).endsWith('/cancel'),
    ) as unknown[][];
}

function cancelCalls(fetchMock: ReturnType<typeof vi.fn>): unknown[][] {
    return fetchMock.mock.calls.filter((call) =>
        String(call[0]).endsWith('/cancel'),
    ) as unknown[][];
}

function createBackfill(options?: { onCompleted?: () => void }): {
    scope: ReturnType<typeof effectScope>;
    api: ReturnType<typeof useFormulaBackfill>;
} {
    const scope = effectScope();
    let api: ReturnType<typeof useFormulaBackfill> | undefined;

    scope.run(() => {
        api = useFormulaBackfill(OBJECT_TYPE, options);
    });

    return { scope, api: api as ReturnType<typeof useFormulaBackfill> };
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useFormulaBackfill — first read', () => {
    it('asks the status endpoint for the object type slug right away, without waiting for a mount hook', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({ ticks: [runPayload()] });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(statusCalls(fetchMock)).toHaveLength(1);
            expect(String(statusCalls(fetchMock)[0][0])).toBe(
                `/engine/formula-backfills/${OBJECT_TYPE}`,
            );
            expect(api.run.value).toMatchObject({
                id: RUN_ID,
                status: 'running',
                totalCount: 120,
                processedCount: 40,
                errorCount: 0,
            });
            expect(api.isCancelling.value).toBe(false);
        } finally {
            scope.stop();
        }
    });
});

describe('useFormulaBackfill — polling lifecycle', () => {
    it.each(['completed', 'cancelled', 'failed'] as BackfillStatus[])(
        'schedules no further request once the run reports %s',
        async (status) => {
            vi.useFakeTimers();

            const fetchMock = installFetch({
                ticks: [runPayload({ status, processedCount: 120 })],
            });
            const { scope, api } = createBackfill();

            try {
                await vi.advanceTimersByTimeAsync(1);

                expect(statusCalls(fetchMock)).toHaveLength(1);
                expect(api.run.value?.status).toBe(status);

                await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

                expect(statusCalls(fetchMock)).toHaveLength(1);
            } finally {
                scope.stop();
            }
        },
    );

    it('keeps polling while the run is still running', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            ticks: [
                runPayload({ processedCount: 40 }),
                runPayload({ processedCount: 80 }),
            ],
        });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(statusCalls(fetchMock)).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);

            expect(statusCalls(fetchMock)).toHaveLength(2);
            expect(api.run.value?.processedCount).toBe(80);
        } finally {
            scope.stop();
        }
    });

    it('stops after an empty answer instead of polling an object type without a run', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({ ticks: [null] });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(api.run.value).toBeNull();
            expect(statusCalls(fetchMock)).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusCalls(fetchMock)).toHaveLength(1);
        } finally {
            scope.stop();
        }
    });

    it('goes quiet on a rejected read without surfacing an error to the user', async () => {
        vi.useFakeTimers();

        const { toast } = await import('vue-sonner');
        const fetchMock = installFetch({ statusCode: 403 });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(api.run.value).toBeNull();
            expect(statusCalls(fetchMock)).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusCalls(fetchMock)).toHaveLength(1);
            expect(toast.error).not.toHaveBeenCalled();
            expect(toast.warning).not.toHaveBeenCalled();
        } finally {
            scope.stop();
        }
    });

    it('goes quiet on a missing run without surfacing an error to the user', async () => {
        vi.useFakeTimers();

        const { toast } = await import('vue-sonner');
        const fetchMock = installFetch({ statusCode: 404 });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(api.run.value).toBeNull();
            expect(statusCalls(fetchMock)).toHaveLength(1);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusCalls(fetchMock)).toHaveLength(1);
            expect(toast.error).not.toHaveBeenCalled();
        } finally {
            scope.stop();
        }
    });
});

describe('useFormulaBackfill — cancelling', () => {
    it('posts to the cancel endpoint with the run id, adopts the returned row and ends the loop', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({ ticks: [runPayload()] });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            const pollsBeforeCancel = statusCalls(fetchMock).length;

            await api.cancel();

            expect(cancelCalls(fetchMock)).toHaveLength(1);
            expect(String(cancelCalls(fetchMock)[0][0])).toBe(
                `/engine/formula-backfills/${RUN_ID}/cancel`,
            );
            expect(
                String(
                    (cancelCalls(fetchMock)[0][1] as RequestInit).method,
                ).toUpperCase(),
            ).toBe('POST');

            expect(api.run.value?.status).toBe('cancelled');
            expect(api.run.value?.processedCount).toBe(40);
            expect(api.isCancelling.value).toBe(false);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusCalls(fetchMock)).toHaveLength(pollsBeforeCancel);
        } finally {
            scope.stop();
        }
    });

    it('reports the cancellation as in flight until the endpoint answers', async () => {
        vi.useFakeTimers();

        let settle!: (response: Response) => void;
        const answered = new Promise<Response>((resolve) => {
            settle = resolve;
        });

        installFetch({
            ticks: [runPayload()],
            cancelResponse: () => answered,
        });

        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(api.isCancelling.value).toBe(false);

            const pending = api.cancel();

            expect(api.isCancelling.value).toBe(true);

            settle(
                jsonResponse(200, {
                    run: runPayload({
                        status: 'cancelled',
                        finishedAt: '2026-07-29T10:00:00+00:00',
                    }),
                }),
            );

            await pending;

            expect(api.isCancelling.value).toBe(false);
            expect(api.run.value?.status).toBe('cancelled');
        } finally {
            scope.stop();
        }
    });

    it('does nothing when there is no run to cancel', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({ ticks: [null] });
        const { scope, api } = createBackfill();

        try {
            await vi.advanceTimersByTimeAsync(1);

            await api.cancel();

            expect(cancelCalls(fetchMock)).toHaveLength(0);
            expect(api.isCancelling.value).toBe(false);
        } finally {
            scope.stop();
        }
    });
});

describe('useFormulaBackfill — completion callback', () => {
    it('calls onCompleted exactly once when the run completes', async () => {
        vi.useFakeTimers();

        const onCompleted = vi.fn();
        installFetch({
            ticks: [runPayload({ status: 'completed', processedCount: 120 })],
        });
        const { scope } = createBackfill({ onCompleted });

        try {
            await vi.advanceTimersByTimeAsync(1);

            expect(onCompleted).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(onCompleted).toHaveBeenCalledTimes(1);
        } finally {
            scope.stop();
        }
    });

    it.each(['cancelled', 'failed'] as BackfillStatus[])(
        'does not call onCompleted for a run that ended as %s',
        async (status) => {
            vi.useFakeTimers();

            const onCompleted = vi.fn();
            installFetch({ ticks: [runPayload({ status })] });
            const { scope, api } = createBackfill({ onCompleted });

            try {
                await vi.advanceTimersByTimeAsync(1);

                expect(api.run.value?.status).toBe(status);
                expect(onCompleted).not.toHaveBeenCalled();
            } finally {
                scope.stop();
            }
        },
    );
});
