import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useJobProgress } from '@/composables/useJobProgress';

const MAX_CONSECUTIVE_FAILURES = 5;

const OBJECT_TYPE = 'contacts';
const BATCH_ID = '01BATCH00K5N3Q8V9WYE6M2H7C';
const IMPORT_JOB_ID = '01IMPJOB0K5N3Q8V9WYE6M2H7C';
const OTHER_JOB_ID = '01OTHER00K5N3Q8V9WYE6M2H7C';

const PAYLOAD = {
    path: 'imports/contacts.csv',
    format: 'csv',
    sheet: null,
    mapping: { name: 'A', email: 'B' },
    duplicate_mode: 'upsert',
} as const;

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
    '@/actions/App/Http/Controllers/Import/ImportExecutionsController',
    () => {
        const objectTypeOf = (arg: unknown): string =>
            typeof arg === 'object' && arg !== null
                ? String((arg as { objectType: unknown }).objectType)
                : String(arg);

        return {
            execute: {
                url: (arg: unknown) => `/import/${objectTypeOf(arg)}/execute`,
                method: 'post',
            },
            status: {
                url: (arg: { objectType: string; batch: string }) =>
                    `/import/${arg.objectType}/status/${arg.batch}`,
                method: 'get',
            },
            history: {
                url: (arg: unknown) => `/import/${objectTypeOf(arg)}/history`,
                method: 'get',
            },
            errorReport: {
                url: (arg: { objectType: string; importJob: string }) =>
                    `/import/${arg.objectType}/error-report/${arg.importJob}`,
                method: 'get',
            },
        };
    },
);

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

interface StatusTick {
    progress?: number;
    processedJobs?: number;
    totalJobs?: number;
    failedJobs?: number;
    finished?: boolean;
    cancelled?: boolean;
}

interface FetchScenario {
    statusTicks?: StatusTick[];
    statusRejects?: boolean;
    history?: unknown;
}

function installFetch(scenario: FetchScenario): ReturnType<typeof vi.fn> {
    const ticks = scenario.statusTicks ?? [];
    let statusIndex = 0;

    const fetchMock = vi.fn((url: unknown) => {
        const requested = String(url);

        if (requested.includes('/execute')) {
            return Promise.resolve(
                jsonResponse(202, {
                    batchId: BATCH_ID,
                    importJobId: IMPORT_JOB_ID,
                }),
            );
        }

        if (requested.includes('/status/')) {
            if (scenario.statusRejects === true) {
                return Promise.reject(new Error('poll endpoint unreachable'));
            }

            const tick = ticks[Math.min(statusIndex, ticks.length - 1)] ?? {};
            statusIndex += 1;

            return Promise.resolve(
                jsonResponse(200, { batchId: BATCH_ID, ...tick }),
            );
        }

        if (requested.includes('/history')) {
            return Promise.resolve(
                jsonResponse(200, scenario.history ?? { data: [] }),
            );
        }

        return Promise.resolve(jsonResponse(404, {}));
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function createProgress(): {
    scope: ReturnType<typeof effectScope>;
    api: ReturnType<typeof useJobProgress>;
} {
    const scope = effectScope();
    let api: ReturnType<typeof useJobProgress> | undefined;

    scope.run(() => {
        api = useJobProgress();
    });

    return { scope, api: api as ReturnType<typeof useJobProgress> };
}

function statusUrlCalls(fetchMock: ReturnType<typeof vi.fn>): unknown[] {
    return fetchMock.mock.calls.filter((call) =>
        String(call[0]).includes('/status/'),
    );
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useJobProgress — execute + poll orchestration', () => {
    it('POSTs execute with the payload/XSRF header, stores the batch, then polls status across ticks while unfinished', async () => {
        vi.useFakeTimers();
        document.cookie = 'XSRF-TOKEN=poll-token';

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 25,
                    processedJobs: 1,
                    totalJobs: 4,
                    failedJobs: 0,
                    finished: false,
                },
                {
                    progress: 50,
                    processedJobs: 2,
                    totalJobs: 4,
                    failedJobs: 0,
                    finished: false,
                },
            ],
        });

        const { scope, api } = createProgress();

        try {
            api.start(PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(1);

            const executeCall = fetchMock.mock.calls.find((call) =>
                String(call[0]).includes('/execute'),
            );
            expect(executeCall).toBeTruthy();
            const executeInit = executeCall?.[1] as RequestInit;
            expect(String(executeCall?.[0])).toBe(
                `/import/${OBJECT_TYPE}/execute`,
            );
            expect(String(executeInit.method).toUpperCase()).toBe('POST');
            expect(JSON.parse(executeInit.body as string)).toMatchObject({
                path: PAYLOAD.path,
                format: PAYLOAD.format,
                mapping: PAYLOAD.mapping,
                duplicate_mode: PAYLOAD.duplicate_mode,
            });
            expect(
                (executeInit.headers as Record<string, string>)['X-XSRF-TOKEN'],
            ).toBe('poll-token');

            expect(api.status.value).toBe('polling');
            expect(api.active.value).toBe(true);
            expect(api.progress.value).toBe(25);
            expect(api.processedJobs.value).toBe(1);
            expect(api.totalJobs.value).toBe(4);

            const firstStatusCall = statusUrlCalls(fetchMock)[0];
            expect(String((firstStatusCall as unknown[])[0])).toBe(
                `/import/${OBJECT_TYPE}/status/${BATCH_ID}`,
            );

            await vi.advanceTimersByTimeAsync(1500);

            expect(api.progress.value).toBe(50);
            expect(api.processedJobs.value).toBe(2);
            expect(api.status.value).toBe('polling');
            expect(statusUrlCalls(fetchMock).length).toBeGreaterThanOrEqual(2);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('on terminal WITH errors: GETs history, matches the row by importJobId, exposes counts and a non-null errorReportUrl', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 100,
                    processedJobs: 4,
                    totalJobs: 4,
                    failedJobs: 2,
                    finished: true,
                },
            ],
            history: {
                data: [
                    {
                        id: OTHER_JOB_ID,
                        status: 'completed',
                        created_count: 1,
                        updated_count: 1,
                        error_count: 0,
                        error_report_path: null,
                    },
                    {
                        id: IMPORT_JOB_ID,
                        status: 'completed',
                        created_count: 10,
                        updated_count: 3,
                        error_count: 2,
                        error_report_path: 'imports/errors.csv',
                    },
                ],
            },
        });

        const { scope, api } = createProgress();

        try {
            api.start(PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(5);

            const historyCall = fetchMock.mock.calls.find((call) =>
                String(call[0]).includes('/history'),
            );
            expect(historyCall).toBeTruthy();
            expect(String(historyCall?.[0])).toBe(
                `/import/${OBJECT_TYPE}/history`,
            );

            expect(api.active.value).toBe(false);
            expect(api.status.value).not.toBe('polling');
            expect(api.createdCount.value).toBe(10);
            expect(api.updatedCount.value).toBe(3);
            expect(api.errorCount.value).toBe(2);

            expect(api.errorReportUrl.value).not.toBeNull();
            expect(String(api.errorReportUrl.value)).toContain(IMPORT_JOB_ID);
            expect(String(api.errorReportUrl.value)).not.toContain(BATCH_ID);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('on terminal with ZERO errors: hydrates counts but leaves errorReportUrl null', async () => {
        vi.useFakeTimers();

        installFetch({
            statusTicks: [
                {
                    progress: 100,
                    processedJobs: 2,
                    totalJobs: 2,
                    failedJobs: 0,
                    finished: true,
                },
            ],
            history: {
                data: [
                    {
                        id: IMPORT_JOB_ID,
                        status: 'completed',
                        created_count: 5,
                        updated_count: 0,
                        error_count: 0,
                        error_report_path: null,
                    },
                ],
            },
        });

        const { scope, api } = createProgress();

        try {
            api.start(PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(5);

            expect(api.active.value).toBe(false);
            expect(api.status.value).not.toBe('polling');
            expect(api.createdCount.value).toBe(5);
            expect(api.updatedCount.value).toBe(0);
            expect(api.errorCount.value).toBe(0);
            expect(api.errorReportUrl.value).toBeNull();
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('stops after MAX_CONSECUTIVE_FAILURES failing polls (status=failed) and never resumes — no poll storm', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        const fetchMock = installFetch({ statusRejects: true });

        const { scope, api } = createProgress();

        try {
            api.start(PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusUrlCalls(fetchMock).length).toBe(
                MAX_CONSECUTIVE_FAILURES,
            );
            expect(api.status.value).toBe('failed');
            expect(api.active.value).toBe(false);

            const plateau = fetchMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(fetchMock.mock.calls.length).toBe(plateau);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('schedules no further polls once a terminal state is reached', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 100,
                    processedJobs: 1,
                    totalJobs: 1,
                    failedJobs: 0,
                    finished: true,
                },
            ],
            history: {
                data: [
                    {
                        id: IMPORT_JOB_ID,
                        status: 'completed',
                        created_count: 1,
                        updated_count: 0,
                        error_count: 0,
                        error_report_path: null,
                    },
                ],
            },
        });

        const { scope, api } = createProgress();

        try {
            api.start(PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(5);

            const settledStatusCalls = statusUrlCalls(fetchMock).length;
            expect(settledStatusCalls).toBe(1);
            expect(api.active.value).toBe(false);

            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusUrlCalls(fetchMock).length).toBe(settledStatusCalls);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('onScopeDispose clears the timer so no poll fires after the scope is stopped', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 20,
                    processedJobs: 1,
                    totalJobs: 5,
                    failedJobs: 0,
                    finished: false,
                },
            ],
        });

        const { scope, api } = createProgress();

        api.start(PAYLOAD, { objectType: OBJECT_TYPE });
        await vi.advanceTimersByTimeAsync(1);

        const callsBeforeDispose = fetchMock.mock.calls.length;
        expect(callsBeforeDispose).toBeGreaterThan(0);

        scope.stop();

        await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

        expect(fetchMock.mock.calls.length).toBe(callsBeforeDispose);
    });
});
