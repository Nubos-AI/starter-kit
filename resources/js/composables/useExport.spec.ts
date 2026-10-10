import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useExport } from '@/composables/useExport';

const MAX_CONSECUTIVE_FAILURES = 5;

const OBJECT_TYPE = 'contacts';
const BATCH_ID = '01BATCH00K5N3Q8V9WYE6M2H7C';
const EXPORT_JOB_ID = '01EXPJOB0K5N3Q8V9WYE6M2H7C';

const FILTER_MODEL = {
    status: { filterType: 'text', type: 'equals', filter: 'active' },
} as const;

const SORT_MODEL = [{ colId: 'name', sort: 'asc' }] as const;

const FIELDS = ['name', 'email'] as const;

const SEGMENT_ID = '01SEGMENT0K5N3Q8V9WYE6M2H7';

const VIEW_PAYLOAD = {
    format: 'csv',
    scope: {
        mode: 'view',
        filterModel: FILTER_MODEL,
        sortModel: SORT_MODEL,
    },
    fields: FIELDS,
} as const;

const WHOLE_TYPE_PAYLOAD = {
    format: 'xlsx',
    scope: { mode: 'whole-type' },
    fields: FIELDS,
} as const;

const SEGMENT_PAYLOAD = {
    format: 'json',
    scope: { mode: 'segment', segmentId: SEGMENT_ID },
    fields: FIELDS,
} as const;

const toastErrorMock = vi.fn();

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: (...args: unknown[]) => toastErrorMock(...args),
        info: vi.fn(),
        warning: vi.fn(),
        message: vi.fn(),
    },
    Toaster: { name: 'ToasterStub', render: () => null },
}));

vi.mock('@/actions/App/Http/Controllers/Export/ExportsController', () => {
    const objectTypeOf = (arg: unknown): string =>
        typeof arg === 'object' && arg !== null
            ? String((arg as { objectType: unknown }).objectType)
            : String(arg);

    return {
        store: {
            url: (arg: unknown) => `/export/${objectTypeOf(arg)}`,
            method: 'post',
        },
        status: {
            url: (arg: { objectType: string; batch: string }) =>
                `/export/${arg.objectType}/status/${arg.batch}`,
            method: 'get',
        },
        download: {
            url: (arg: { objectType: string; exportJob: string }) =>
                `/export/${arg.objectType}/download/${arg.exportJob}`,
            method: 'get',
        },
    };
});

vi.mock(
    '@/actions/App/Http/Controllers/Export/ExportFieldPresetsController',
    () => {
        const objectTypeOf = (arg: unknown): string =>
            typeof arg === 'object' && arg !== null
                ? String((arg as { objectType: unknown }).objectType)
                : String(arg);

        return {
            index: {
                url: (arg: unknown) =>
                    `/engine/export/${objectTypeOf(arg)}/presets`,
                method: 'get',
            },
            store: {
                url: (arg: unknown) =>
                    `/engine/export/${objectTypeOf(arg)}/presets`,
                method: 'post',
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
    storeStatus?: number;
    storeBody?: unknown;
}

function installFetch(scenario: FetchScenario): ReturnType<typeof vi.fn> {
    const ticks = scenario.statusTicks ?? [];
    let statusIndex = 0;

    const fetchMock = vi.fn((url: unknown) => {
        const requested = String(url);

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

        return Promise.resolve(
            jsonResponse(
                scenario.storeStatus ?? 202,
                scenario.storeBody ?? {
                    batchId: BATCH_ID,
                    exportJobId: EXPORT_JOB_ID,
                },
            ),
        );
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function createExport(): {
    scope: ReturnType<typeof effectScope>;
    api: ReturnType<typeof useExport>;
} {
    const scope = effectScope();
    let api: ReturnType<typeof useExport> | undefined;

    scope.run(() => {
        api = useExport();
    });

    return { scope, api: api as ReturnType<typeof useExport> };
}

function storeCall(fetchMock: ReturnType<typeof vi.fn>): unknown[] | undefined {
    return fetchMock.mock.calls.find(
        (call) =>
            String(call[0]).includes('/export/') &&
            !String(call[0]).includes('/status/') &&
            !String(call[0]).includes('/download/'),
    );
}

function statusUrlCalls(fetchMock: ReturnType<typeof vi.fn>): unknown[] {
    return fetchMock.mock.calls.filter((call) =>
        String(call[0]).includes('/status/'),
    );
}

function storeBodyOf(call: unknown[] | undefined): Record<string, unknown> {
    const init = call?.[1] as RequestInit;

    return JSON.parse(init.body as string) as Record<string, unknown>;
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useExport — start + poll + download orchestration', () => {
    it('WYSIWYG: start with scope.mode=view POSTs the exact filterModel + sortModel snapshot supplied', async () => {
        vi.useFakeTimers();
        document.cookie = 'XSRF-TOKEN=export-token';

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

        const { scope, api } = createExport();

        try {
            api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(1);

            const call = storeCall(fetchMock);
            expect(call).toBeTruthy();
            expect(String(call?.[0])).toBe(`/export/${OBJECT_TYPE}`);

            const init = call?.[1] as RequestInit;
            expect(String(init.method).toUpperCase()).toBe('POST');
            expect(
                (init.headers as Record<string, string>)['X-XSRF-TOKEN'],
            ).toBe('export-token');

            const body = storeBodyOf(call);
            expect(body.format).toBe('csv');
            expect(body.fields).toEqual([...FIELDS]);
            expect(body.scope).toEqual({
                mode: 'view',
                filterModel: FILTER_MODEL,
                sortModel: [...SORT_MODEL],
            });

            expect(api.status.value).toBe('polling');
            expect(api.active.value).toBe(true);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('start POSTs the export store endpoint and consumes the 202 {batchId, exportJobId}, then polls status for that batch', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 40,
                    processedJobs: 2,
                    totalJobs: 5,
                    failedJobs: 0,
                    finished: false,
                },
            ],
        });

        const { scope, api } = createExport();

        try {
            api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(1);

            expect(storeCall(fetchMock)).toBeTruthy();

            const firstStatus = statusUrlCalls(fetchMock)[0];
            expect(String((firstStatus as unknown[])[0])).toBe(
                `/export/${OBJECT_TYPE}/status/${BATCH_ID}`,
            );

            expect(api.progress.value).toBe(40);
            expect(api.processedJobs.value).toBe(2);
            expect(api.totalJobs.value).toBe(5);
            expect(api.status.value).toBe('polling');
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('on terminal finished===true exposes downloadUrl keyed by the CAPTURED exportJobId, not any status-response field', async () => {
        vi.useFakeTimers();

        installFetch({
            statusTicks: [
                {
                    progress: 100,
                    processedJobs: 5,
                    totalJobs: 5,
                    failedJobs: 0,
                    finished: true,
                },
            ],
        });

        const { scope, api } = createExport();

        try {
            api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(5);

            expect(api.active.value).toBe(false);
            expect(api.status.value).toBe('finished');
            expect(api.downloadUrl.value).toBe(
                `/export/${OBJECT_TYPE}/download/${EXPORT_JOB_ID}`,
            );
            expect(String(api.downloadUrl.value)).toContain(EXPORT_JOB_ID);
            expect(String(api.downloadUrl.value)).not.toContain(BATCH_ID);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('whole-type scope POSTs {mode:whole-type} with no filterModel/sortModel', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 10,
                    processedJobs: 0,
                    totalJobs: 1,
                    failedJobs: 0,
                    finished: false,
                },
            ],
        });

        const { scope, api } = createExport();

        try {
            api.start(WHOLE_TYPE_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(1);

            const body = storeBodyOf(storeCall(fetchMock));
            expect(body.scope).toEqual({ mode: 'whole-type' });
            expect(
                (body.scope as Record<string, unknown>).filterModel,
            ).toBeUndefined();
            expect(
                (body.scope as Record<string, unknown>).sortModel,
            ).toBeUndefined();
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('segment scope POSTs {mode:segment, segmentId}', async () => {
        vi.useFakeTimers();

        const fetchMock = installFetch({
            statusTicks: [
                {
                    progress: 10,
                    processedJobs: 0,
                    totalJobs: 1,
                    failedJobs: 0,
                    finished: false,
                },
            ],
        });

        const { scope, api } = createExport();

        try {
            api.start(SEGMENT_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(1);

            const body = storeBodyOf(storeCall(fetchMock));
            expect(body.scope).toEqual({
                mode: 'segment',
                segmentId: SEGMENT_ID,
            });
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('stops after MAX_CONSECUTIVE_FAILURES failing polls (status=failed) and never resumes — no poll storm', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        const fetchMock = installFetch({ statusRejects: true });

        const { scope, api } = createExport();

        try {
            api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(statusUrlCalls(fetchMock).length).toBe(
                MAX_CONSECUTIVE_FAILURES,
            );
            expect(api.status.value).toBe('failed');
            expect(api.active.value).toBe(false);
            expect(api.downloadUrl.value).toBeNull();

            const plateau = fetchMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(fetchMock.mock.calls.length).toBe(plateau);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('non-ok store response sets a German error state (status=failed, toast.error) and does NOT throw', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'error').mockImplementation(() => undefined);

        const fetchMock = installFetch({
            storeStatus: 500,
            storeBody: { message: 'boom' },
        });

        const { scope, api } = createExport();

        try {
            expect(() =>
                api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE }),
            ).not.toThrow();
            await vi.advanceTimersByTimeAsync(5);

            expect(api.status.value).toBe('failed');
            expect(api.active.value).toBe(false);
            expect(api.downloadUrl.value).toBeNull();
            expect(statusUrlCalls(fetchMock).length).toBe(0);

            expect(toastErrorMock).toHaveBeenCalledTimes(1);
            const message = String(toastErrorMock.mock.calls[0]?.[0]);
            expect(message.length).toBeGreaterThan(0);
            expect(message).toMatch(/[a-zäöü]/i);
        } finally {
            api.stop();
            scope.stop();
        }
    });

    it('loadPresets GETs the preset index and populates presets from the {data:[]} envelope', async () => {
        const preset = {
            id: '01PRESET00K5N3Q8V9WYE6M2H7',
            name: 'Nur Kontaktdaten',
            fields: ['name', 'email'],
        };

        const fetchMock = vi.fn<(...args: unknown[]) => Promise<Response>>(() =>
            Promise.resolve(jsonResponse(200, { data: [preset] })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { scope, api } = createExport();

        try {
            await api.loadPresets(OBJECT_TYPE);

            expect(String(fetchMock.mock.calls[0]?.[0])).toBe(
                `/engine/export/${OBJECT_TYPE}/presets`,
            );
            const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
            expect(String(init.method).toUpperCase()).toBe('GET');

            expect(api.presets.value).toEqual([preset]);
        } finally {
            scope.stop();
        }
    });

    it('savePreset POSTs {name, fields} to the preset store endpoint and appends the returned preset', async () => {
        document.cookie = 'XSRF-TOKEN=export-token';

        const created = {
            id: '01PRESET10K5N3Q8V9WYE6M2H7',
            name: 'Complete',
            fields: ['name', 'email', 'phone'],
        };

        const fetchMock = vi.fn<(...args: unknown[]) => Promise<Response>>(() =>
            Promise.resolve(jsonResponse(201, { data: created })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { scope, api } = createExport();

        try {
            const result = await api.savePreset(OBJECT_TYPE, 'Complete', [
                'name',
                'email',
                'phone',
            ]);

            const call = fetchMock.mock.calls[0];
            expect(String(call?.[0])).toBe(
                `/engine/export/${OBJECT_TYPE}/presets`,
            );

            const init = call?.[1] as RequestInit;
            expect(String(init.method).toUpperCase()).toBe('POST');
            expect(
                (init.headers as Record<string, string>)['X-XSRF-TOKEN'],
            ).toBe('export-token');
            expect(JSON.parse(init.body as string)).toEqual({
                name: 'Complete',
                fields: ['name', 'email', 'phone'],
            });

            expect(result).toEqual(created);
            expect(api.presets.value).toContainEqual(created);
        } finally {
            scope.stop();
        }
    });

    it('a loaded preset feeds its fields into a subsequent export start POST', async () => {
        vi.useFakeTimers();

        const presetFields = ['name', 'phone'];

        const fetchMock = vi.fn((url: unknown) => {
            const requested = String(url);

            if (requested.endsWith('/presets')) {
                return Promise.resolve(
                    jsonResponse(200, {
                        data: [
                            {
                                id: '01PRESET20K5N3Q8V9WYE6M2H7',
                                name: 'Telefonliste',
                                fields: presetFields,
                            },
                        ],
                    }),
                );
            }

            if (requested.includes('/status/')) {
                return Promise.resolve(
                    jsonResponse(200, {
                        batchId: BATCH_ID,
                        progress: 10,
                        finished: false,
                    }),
                );
            }

            return Promise.resolve(
                jsonResponse(202, {
                    batchId: BATCH_ID,
                    exportJobId: EXPORT_JOB_ID,
                }),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        const { scope, api } = createExport();

        try {
            await api.loadPresets(OBJECT_TYPE);
            const preset = api.presets.value[0];
            expect(preset?.fields).toEqual(presetFields);

            api.start(
                {
                    format: 'csv',
                    scope: { mode: 'whole-type' },
                    fields: preset.fields,
                },
                { objectType: OBJECT_TYPE },
            );
            await vi.advanceTimersByTimeAsync(1);

            const call = fetchMock.mock.calls.find(
                (entry) =>
                    !String(entry[0]).endsWith('/presets') &&
                    !String(entry[0]).includes('/status/'),
            );
            const body = storeBodyOf(call);
            expect(body.fields).toEqual(presetFields);
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

        const { scope, api } = createExport();

        api.start(VIEW_PAYLOAD, { objectType: OBJECT_TYPE });
        await vi.advanceTimersByTimeAsync(1);

        const callsBeforeDispose = fetchMock.mock.calls.length;
        expect(callsBeforeDispose).toBeGreaterThan(0);

        scope.stop();

        await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

        expect(fetchMock.mock.calls.length).toBe(callsBeforeDispose);
    });
});
