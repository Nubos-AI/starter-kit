import type { IGetRowsParams } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useRecordDatasource } from '@/composables/useRecordDatasource';
import type { GridGetRowsRequest, InitialRecordGrid } from '@/types/grid';
import type {
    ReportDrillDownReportParams,
    ReportDrillDownWidgetParams,
} from '@/types/reports';
import { setUrlDefaults } from '@/wayfinder';

const ACTIVE_TEAM = '01kz75hf7trhsjwt8vtw2fpats';

function initialBlock(
    overrides: Partial<GridGetRowsRequest> = {},
): InitialRecordGrid {
    return {
        request: {
            startRow: 0,
            endRow: 20,
            sortModel: [],
            filterModel: {},
            ...overrides,
        },
        response: { rows: [], lastRow: 0 },
    };
}

function rowParams(): IGetRowsParams {
    return {
        startRow: 0,
        endRow: 20,
        sortModel: [],
        filterModel: {},
        successCallback: vi.fn(),
        failCallback: vi.fn(),
        context: undefined,
    } as unknown as IGetRowsParams;
}

function respondWith(body: unknown): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => body,
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
});

afterEach(() => {
    vi.unstubAllGlobals();
    setUrlDefaults({});
});

describe('useRecordDatasource', () => {
    it('uses the matching first page without fetching and fetches again on refresh', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 7 });
        const consumed = vi.fn();
        const { datasource } = useRecordDatasource(
            'companies',
            undefined,
            undefined,
            undefined,
            undefined,
            initialBlock(),
            consumed,
        );
        const params = rowParams();

        await datasource.getRows(params);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(params.successCallback).toHaveBeenCalledWith([], 0);
        expect(consumed).toHaveBeenCalledOnce();

        await datasource.getRows(rowParams());
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it.each([
        { segment: 'different-segment' },
        { search: 'different-search' },
        { endRow: 100 },
        { startRow: 20 },
        { hierarchy: true },
        { sortModel: [{ colId: 'name', sort: 'desc' as const }] },
        { filterModel: { name: { filterType: 'text', filter: 'private' } } },
        { report: 'report', group: 'v:won' },
        { dashboard: 'dashboard', widget: 'widget', group: 'v:won' },
    ])('discards an initial block with different scope %j', async (scope) => {
        const fetchMock = respondWith({ rows: [], lastRow: 7 });
        const { datasource } = useRecordDatasource(
            'companies',
            undefined,
            undefined,
            undefined,
            undefined,
            initialBlock(scope),
        );
        const params = rowParams();

        await datasource.getRows(params);

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(params.successCallback).toHaveBeenCalledWith([], 7);
    });

    it('keeps sort priority but ignores object key order when matching the first block', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 7 });
        const initial = initialBlock({
            sortModel: [{ colId: 'name', sort: 'asc' }],
            filterModel: { name: { type: 'contains', filter: 'acme' } },
        });
        const { datasource } = useRecordDatasource(
            'companies',
            undefined,
            undefined,
            undefined,
            undefined,
            initial,
        );

        await datasource.getRows({
            ...rowParams(),
            sortModel: [{ sort: 'asc', colId: 'name' }],
            filterModel: { name: { filter: 'acme', type: 'contains' } },
        });

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not reuse a rejected initial block when the filter later returns to its original value', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 7 });
        let segment: string | null = 'selected';
        const { datasource } = useRecordDatasource(
            'companies',
            () => segment,
            undefined,
            undefined,
            undefined,
            initialBlock(),
        );

        await datasource.getRows(rowParams());
        segment = null;
        await datasource.getRows(rowParams());

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('preserves the hierarchy warning from the initial response', async () => {
        const initial = initialBlock({ hierarchy: true });
        initial.response.hierarchy = {
            applied: false,
            reason: 'too_many_rows',
        };
        const { datasource, hierarchyNotice, loading } = useRecordDatasource(
            'companies',
            undefined,
            undefined,
            () => true,
            undefined,
            initial,
        );

        await datasource.getRows(rowParams());

        expect(hierarchyNotice.value).toContain('Hierarchie-Ansicht');
        expect(loading.value).toBe(false);
    });

    it('addresses the grid endpoint of the active team', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            `/${ACTIVE_TEAM}/records/companies/grid`,
        );
    });

    it('uses the configured real team slug', async () => {
        setUrlDefaults({ activeTeam: 'nubos' });
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/nubos/records/companies/grid',
        );
    });

    it('reports a failing request instead of swallowing it', async () => {
        const failCallback = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, status: 404 }),
        );

        const { datasource, error } = useRecordDatasource('companies');
        const params = { ...rowParams(), failCallback };

        await datasource.getRows(params as unknown as IGetRowsParams);

        expect(failCallback).toHaveBeenCalledTimes(1);
        expect(error.value).not.toBeNull();
    });
});

const DRILL_DOWN_REPORT_ID = '01REPORT0000000000000001';

const SEGMENT_ID = '01SEGMENT00000000000001';

function drillDown(
    overrides: Partial<ReportDrillDownReportParams> = {},
): ReportDrillDownReportParams {
    return {
        report: DRILL_DOWN_REPORT_ID,
        group: 'v:won',
        series: null,
        ...overrides,
    };
}

function gridRequestBody(
    fetchMock: ReturnType<typeof vi.fn>,
): Record<string, unknown> {
    const init: unknown = fetchMock.mock.calls[0]?.[1];

    if (init === null || typeof init !== 'object' || !('body' in init)) {
        throw new Error('the grid request carries no request init');
    }

    const body = init.body;

    if (typeof body !== 'string') {
        throw new Error('the grid request carries no JSON body');
    }

    const parsed: unknown = JSON.parse(body);

    if (
        parsed === null ||
        typeof parsed !== 'object' ||
        Array.isArray(parsed)
    ) {
        throw new Error('the grid request body is no plain object');
    }

    return parsed as Record<string, unknown>;
}

describe('useRecordDatasource drill-down', () => {
    it('sends the clicked report, group and series under the names the endpoint reads', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'companies',
            () => null,
            () => drillDown({ series: 'v:2026' }),
        );

        await datasource.getRows(rowParams());

        const body = gridRequestBody(fetchMock);

        expect(Object.keys(body).sort()).toEqual([
            'dashboard',
            'endRow',
            'filterModel',
            'group',
            'hierarchy',
            'report',
            'search',
            'segment',
            'series',
            'sortModel',
            'startRow',
            'widget',
        ]);
        expect(body.report).toBe(DRILL_DOWN_REPORT_ID);
        expect(body.group).toBe('v:won');
        expect(body.series).toBe('v:2026');
        expect(body.segment).toBeNull();
    });

    it('keeps a group without a value distinguishable from no drill-down', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'companies',
            () => null,
            () => drillDown({ group: 'null' }),
        );

        await datasource.getRows(rowParams());

        const body = gridRequestBody(fetchMock);

        expect(body.report).toBe(DRILL_DOWN_REPORT_ID);
        expect(body.group).toBe('null');
        expect(body.series).toBeNull();
    });

    it('sends all five drill-down keys as null on a plain segment request', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'companies',
            () => SEGMENT_ID,
        );

        await datasource.getRows(rowParams());

        const body = gridRequestBody(fetchMock);

        expect(body.segment).toBe(SEGMENT_ID);
        expect(body.report).toBeNull();
        expect(body.dashboard).toBeNull();
        expect(body.widget).toBeNull();
        expect(body.group).toBeNull();
        expect(body.series).toBeNull();
        expect(body.startRow).toBe(0);
        expect(body.endRow).toBe(20);
    });
});

const DRILL_DOWN_DASHBOARD_ID = '01DASHBOARD00000000000001';

const DRILL_DOWN_WIDGET_ID = '01WIDGET000000000000000A';

function widgetDrillDown(
    overrides: Partial<ReportDrillDownWidgetParams> = {},
): ReportDrillDownWidgetParams {
    return {
        dashboard: DRILL_DOWN_DASHBOARD_ID,
        widget: DRILL_DOWN_WIDGET_ID,
        group: 'v:won',
        series: null,
        ...overrides,
    };
}

describe('useRecordDatasource widget drill-down', () => {
    it('names the dashboard and the widget and leaves the report empty', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'companies',
            () => null,
            () => widgetDrillDown({ series: 'v:2026' }),
        );

        await datasource.getRows(rowParams());

        const body = gridRequestBody(fetchMock);

        expect(body.dashboard).toBe(DRILL_DOWN_DASHBOARD_ID);
        expect(body.widget).toBe(DRILL_DOWN_WIDGET_ID);
        expect(body.group).toBe('v:won');
        expect(body.series).toBe('v:2026');
        expect(body.report).toBeNull();
        expect(body.segment).toBeNull();
    });

    it('names the report and leaves the dashboard and the widget empty', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'companies',
            () => null,
            () => drillDown(),
        );

        await datasource.getRows(rowParams());

        const body = gridRequestBody(fetchMock);

        expect(body.report).toBe(DRILL_DOWN_REPORT_ID);
        expect(body.dashboard).toBeNull();
        expect(body.widget).toBeNull();
        expect(body.group).toBe('v:won');
        expect(body.segment).toBeNull();
    });
});

describe('useRecordDatasource failures', () => {
    it('names the reason the server gave instead of a generic failure', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 503,
                json: async () => ({
                    message: 'Die Abfrage hat zu lange gedauert.',
                }),
            }),
        );

        const { datasource, error } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(error.value).toBe('Die Abfrage hat zu lange gedauert.');
    });

    it('distinguishes an aborted request from a failing one', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockRejectedValue(
                    new DOMException('The request was aborted', 'AbortError'),
                ),
        );

        const { datasource, error } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(error.value).toContain('zu lange');
    });

    it('points at the expired session when the request is rejected with 419', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 419,
                json: async () => ({}),
            }),
        );

        const { datasource, error } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(error.value).toContain('Sitzung');
    });

    it('states every failure in German', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, status: 500 }),
        );

        const { datasource, error } = useRecordDatasource('companies');

        await datasource.getRows(rowParams());

        expect(error.value).toBe(
            'Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
        );
    });
});

describe('useRecordDatasource hierarchy', () => {
    it('leaves the tree order out unless the grid asks for it', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource('contacts');

        await datasource.getRows(rowParams());

        expect(gridRequestBody(fetchMock).hierarchy).toBe(false);
    });

    it('asks for the tree order when the grid requests it', async () => {
        const fetchMock = respondWith({ rows: [], lastRow: 0 });
        const { datasource } = useRecordDatasource(
            'contacts',
            () => null,
            () => null,
            () => true,
        );

        await datasource.getRows(rowParams());

        expect(gridRequestBody(fetchMock).hierarchy).toBe(true);
    });

    it('names why the tree order was dropped above the ceiling', async () => {
        respondWith({
            rows: [],
            lastRow: 0,
            hierarchy: { applied: false, reason: 'too_many_rows' },
        });

        const { datasource, hierarchyNotice } = useRecordDatasource(
            'contacts',
            () => null,
            () => null,
            () => true,
        );

        await datasource.getRows(rowParams());

        expect(hierarchyNotice.value).toContain('Hierarchie-Ansicht');
    });

    it('stays quiet when the tree order was applied', async () => {
        respondWith({
            rows: [],
            lastRow: 0,
            hierarchy: { applied: true, reason: null },
        });

        const { datasource, hierarchyNotice } = useRecordDatasource(
            'contacts',
            () => null,
            () => null,
            () => true,
        );

        await datasource.getRows(rowParams());

        expect(hierarchyNotice.value).toBeNull();
    });
});
