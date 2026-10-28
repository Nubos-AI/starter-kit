import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import DashboardWidgetResultsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetResultsController';
import {
    RESULTS_ERROR_MESSAGE,
    useWidgetResults,
} from '@/composables/useWidgetResults';
import type { DashboardWidgetTile } from '@/types/dashboards';
import type { ReportResult } from '@/types/reports';
import { setUrlDefaults } from '@/wayfinder';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|failed|error|could|please)\b/i;

type FetchMock = ReturnType<typeof vi.fn>;

type FetchHandler = (input: string, init?: RequestInit) => Promise<Response>;

interface Deferred<T> {
    promise: Promise<T>;
    resolve: (value: T) => void;
}

interface ScopedResults {
    results: ReturnType<typeof useWidgetResults>;
    stop: () => void;
}

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

function deferred<T>(): Deferred<T> {
    let resolve: (value: T) => void = () => undefined;

    const promise = new Promise<T>((resolveFn) => {
        resolve = resolveFn;
    });

    return { promise, resolve };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function result(total: string): ReportResult {
    return {
        aggregation: 'count',
        rows: [
            {
                group_value: 'won',
                series_value: null,
                value: total,
                record_count: 1,
                discarded_value_count: 0,
                is_other_group: false,
                is_other_series: false,
            },
        ],
        total,
        record_count: 1,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T11:58:00Z',
        execution_mode: 'viewer',
    };
}

function tile(widgetId: string, total: string): DashboardWidgetTile {
    return {
        widget_id: widgetId,
        title: 'Erste Kachel',
        chart_type: 'metric',
        position: 0,
        column_span: 1,
        goal_id: null,
        goal: null,
        report_id: '01REPORT0000000000000001',
        object_type: {
            id: '01OBJECTTYPE000000000001',
            slug: 'companies',
            name: 'Unternehmen',
        },
        execution_mode: 'viewer',
        generated_at: '2026-08-10T11:58:00Z',
        result: result(total),
        notice: null,
    };
}

function stubFetch(handler: FetchHandler): FetchMock {
    const mock = vi.fn(handler);

    vi.stubGlobal('fetch', mock);

    return mock;
}

function pathOf(url: string): string {
    return new URL(url, 'http://localhost').pathname;
}

function indexRoute(): string {
    return DashboardWidgetResultsController.index.url({
        dashboard: DASHBOARD_ID,
    });
}

function showRoute(widgetId: string): string {
    return DashboardWidgetResultsController.show.url({
        dashboard: DASHBOARD_ID,
        widget: widgetId,
    });
}

function callsTo(
    fetchMock: FetchMock,
    method: string,
    route: string,
): Array<[string, RequestInit]> {
    return fetchMock.mock.calls.filter(([url, init]) => {
        const used = (init as RequestInit | undefined)?.method ?? 'GET';

        return used === method && pathOf(String(url)) === pathOf(route);
    }) as Array<[string, RequestInit]>;
}

function scoped(): ScopedResults {
    const scope = effectScope();
    const created = scope.run(() => useWidgetResults(DASHBOARD_ID));

    if (created === undefined) {
        throw new Error('The results composable did not run inside the scope.');
    }

    return { results: created, stop: () => scope.stop() };
}

function totalsOf(results: ReturnType<typeof useWidgetResults>): string[] {
    return Object.values(results.tiles.value).map(
        (entry) => entry.result?.total ?? '',
    );
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    toastError.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    setUrlDefaults({});
});

describe('useWidgetResults — when the endpoint refuses', () => {
    it('says so in German and leaves the tiles it already had alone', async () => {
        const answers: Array<Promise<Response>> = [
            Promise.resolve(jsonResponse(200, { data: [tile('w1', '42')] })),
        ];
        stubFetch(() => {
            const next = answers.shift();

            return (
                next ?? Promise.resolve(jsonResponse(500, { message: 'no' }))
            );
        });

        const { results, stop } = scoped();

        await results.refreshAll();

        expect(totalsOf(results)).toEqual(['42']);

        await results.refreshAll();

        expect(results.error.value).toBe(RESULTS_ERROR_MESSAGE);
        expect(String(results.error.value)).not.toMatch(ENGLISH_WORDS);
        expect(toastError).toHaveBeenCalledTimes(1);
        expect(totalsOf(results)).toEqual(['42']);
        expect(results.isRefreshingAll.value).toBe(false);

        stop();
    });

    it('treats a refused single tile the same way and frees it again', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(500, { message: 'no' })),
        );

        const { results, stop } = scoped();

        await results.refreshOne('w1');

        expect(callsTo(fetchMock, 'POST', showRoute('w1'))).toHaveLength(1);
        expect(results.error.value).toBe(RESULTS_ERROR_MESSAGE);
        expect(toastError).toHaveBeenCalledTimes(1);
        expect(results.pending.value.has('w1')).toBe(false);

        stop();
    });
});

describe('useWidgetResults — a late answer never overwrites a fresh one', () => {
    it('keeps the freshly fetched tile when the slower collective run lands last', async () => {
        const collective = deferred<Response>();
        const single = deferred<Response>();

        stubFetch((url) =>
            pathOf(String(url)) === pathOf(indexRoute())
                ? collective.promise
                : single.promise,
        );

        const { results, stop } = scoped();

        const all = results.refreshAll();
        const one = results.refreshOne('w1');

        single.resolve(jsonResponse(200, { data: tile('w1', 'NEU') }));
        await one;

        expect(totalsOf(results)).toEqual(['NEU']);

        collective.resolve(jsonResponse(200, { data: [tile('w1', 'ALT')] }));
        await all;

        expect(totalsOf(results)).toEqual(['NEU']);
        expect(results.isRefreshingAll.value).toBe(false);

        stop();
    });

    it('answers two runs started back to back with a single request', async () => {
        const pending = deferred<Response>();
        const fetchMock = stubFetch(() => pending.promise);

        const { results, stop } = scoped();

        const first = results.refreshAll();
        const second = results.refreshAll();

        expect(callsTo(fetchMock, 'POST', indexRoute())).toHaveLength(1);

        pending.resolve(jsonResponse(200, { data: [tile('w1', '42')] }));
        await Promise.all([first, second]);

        expect(callsTo(fetchMock, 'POST', indexRoute())).toHaveLength(1);
        expect(totalsOf(results)).toEqual(['42']);

        stop();
    });
});

describe('useWidgetResults — tearing the scope down', () => {
    it('aborts the run in flight and stops touching its state afterwards', async () => {
        const pending = deferred<Response>();
        const fetchMock = stubFetch(() => pending.promise);

        const { results, stop } = scoped();

        const run = results.refreshAll();

        expect(callsTo(fetchMock, 'POST', indexRoute())).toHaveLength(1);

        stop();

        expect(
            callsTo(fetchMock, 'POST', indexRoute())[0][1].signal?.aborted,
        ).toBe(true);

        pending.resolve(jsonResponse(500, { message: 'too late' }));
        await run;
        await flushPromises();

        expect(results.error.value).toBeNull();
        expect(results.tiles.value).toEqual({});
        expect(toastError).not.toHaveBeenCalled();
    });
});
