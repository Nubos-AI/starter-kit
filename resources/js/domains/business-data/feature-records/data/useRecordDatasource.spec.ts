import type { IGetRowsParams } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { grid } from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import type { ReportDrillDownReportParams, ReportDrillDownWidgetParams } from '@/shared/business-data/types/drillDown';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { GridGetRowsRequest, InitialRecordGrid } from '../../data/types/grid';
import { useRecordDatasource } from './useRecordDatasource';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const ACTIVE_TEAM = '01kz75hf7trhsjwt8vtw2fpats';

function initialBlock(overrides: Partial<GridGetRowsRequest> = {}): InitialRecordGrid {
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

function gridRoute(slug = 'companies'): ReturnType<typeof grid> {
  return grid({ objectType: slug });
}

function respondWith(body: Parameters<typeof serveJson>[1], slug = 'companies'): ServedRoute {
  return serveJson(gridRoute(slug), body);
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: ACTIVE_TEAM });
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.useRealTimers();
  setUrlDefaults({});
});

describe('useRecordDatasource', () => {
  it('uses the matching first page without fetching and fetches again on refresh', async () => {
    const served = respondWith({ rows: [], lastRow: 7 });
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

    expect(served.requests).toHaveLength(0);
    expect(params.successCallback).toHaveBeenCalledWith([], 0);
    expect(consumed).toHaveBeenCalledOnce();

    await datasource.getRows(rowParams());
    expect(served.requests).toHaveLength(1);
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
    const served = respondWith({ rows: [], lastRow: 7 });
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

    expect(served.requests).toHaveLength(1);
    expect(params.successCallback).toHaveBeenCalledWith([], 7);
  });

  it('keeps sort priority but ignores object key order when matching the first block', async () => {
    const served = respondWith({ rows: [], lastRow: 7 });
    const initial = initialBlock({
      sortModel: [{ colId: 'name', sort: 'asc' }],
      filterModel: { name: { type: 'contains', filter: 'acme' } },
    });
    const { datasource } = useRecordDatasource('companies', undefined, undefined, undefined, undefined, initial);

    await datasource.getRows({
      ...rowParams(),
      sortModel: [{ sort: 'asc', colId: 'name' }],
      filterModel: { name: { filter: 'acme', type: 'contains' } },
    });

    expect(served.requests).toHaveLength(0);
  });

  it('does not reuse a rejected initial block when the filter later returns to its original value', async () => {
    const served = respondWith({ rows: [], lastRow: 7 });
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

    expect(served.requests).toHaveLength(2);
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

  it.each([
    ['the active team', ACTIVE_TEAM],
    ['the configured real team slug', 'nubos'],
  ])('addresses the grid endpoint of %s', async (_label, teamSlug) => {
    setUrlDefaults({ activeTeam: teamSlug });
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource('companies');

    await datasource.getRows(rowParams());

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/${teamSlug}/records/companies/grid`);
  });

  it('reports a failing request instead of swallowing it', async () => {
    const failCallback = vi.fn();
    serveEmpty(gridRoute(), { status: 404 });

    const { datasource, error } = useRecordDatasource('companies');
    const params = { ...rowParams(), failCallback };

    await datasource.getRows(params as unknown as IGetRowsParams);

    expect(failCallback).toHaveBeenCalledTimes(1);
    expect(params.successCallback).not.toHaveBeenCalled();
    expect(error.value).toBe('Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.');
  });
});

const DRILL_DOWN_REPORT_ID = '01REPORT0000000000000001';

const SEGMENT_ID = '01SEGMENT00000000000001';

function drillDown(overrides: Partial<ReportDrillDownReportParams> = {}): ReportDrillDownReportParams {
  return {
    report: DRILL_DOWN_REPORT_ID,
    group: 'v:won',
    series: null,
    ...overrides,
  };
}

function gridRequestBody(served: ServedRoute): Record<string, unknown> {
  const body = served.lastRequest()?.body;

  if (body === null || typeof body !== 'object' || Array.isArray(body)) {
    throw new Error('the grid request body is no plain object');
  }

  return body as Record<string, unknown>;
}

describe('useRecordDatasource drill-down', () => {
  it('sends the clicked report, group and series under the names the endpoint reads', async () => {
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource(
      'companies',
      () => null,
      () => drillDown({ series: 'v:2026' }),
    );

    await datasource.getRows(rowParams());

    const body = gridRequestBody(served);

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
    expect(body.dashboard).toBeNull();
    expect(body.widget).toBeNull();
  });

  it('keeps a group without a value distinguishable from no drill-down', async () => {
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource(
      'companies',
      () => null,
      () => drillDown({ group: 'null' }),
    );

    await datasource.getRows(rowParams());

    const body = gridRequestBody(served);

    expect(body.report).toBe(DRILL_DOWN_REPORT_ID);
    expect(body.group).toBe('null');
    expect(body.series).toBeNull();
  });

  it('sends all five drill-down keys as null on a plain segment request', async () => {
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource('companies', () => SEGMENT_ID);

    await datasource.getRows(rowParams());

    const body = gridRequestBody(served);

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

function widgetDrillDown(overrides: Partial<ReportDrillDownWidgetParams> = {}): ReportDrillDownWidgetParams {
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
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource(
      'companies',
      () => null,
      () => widgetDrillDown({ series: 'v:2026' }),
    );

    await datasource.getRows(rowParams());

    const body = gridRequestBody(served);

    expect(body.dashboard).toBe(DRILL_DOWN_DASHBOARD_ID);
    expect(body.widget).toBe(DRILL_DOWN_WIDGET_ID);
    expect(body.group).toBe('v:won');
    expect(body.series).toBe('v:2026');
    expect(body.report).toBeNull();
    expect(body.segment).toBeNull();
  });
});

describe('useRecordDatasource failures', () => {
  it('names the reason the server gave instead of a generic failure', async () => {
    serveServerError(gridRoute(), { message: 'Die Abfrage hat zu lange gedauert.' }, { status: 503 });

    const { datasource, error } = useRecordDatasource('companies');

    await datasource.getRows(rowParams());

    expect(error.value).toBe('Die Abfrage hat zu lange gedauert.');
  });

  it('distinguishes an aborted request from a failing one', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const served = serveJson(gridRoute(), { rows: [], lastRow: 0 }, { held: true });
    const params = rowParams();
    const { datasource, error, loading } = useRecordDatasource('companies');

    const loadingRows = datasource.getRows(params);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await vi.advanceTimersByTimeAsync(20000);
    await loadingRows;
    served.release();

    expect(served.lastRequest()?.signal.aborted).toBe(true);
    expect(params.failCallback).toHaveBeenCalledTimes(1);
    expect(params.successCallback).not.toHaveBeenCalled();
    expect(error.value).toBe('Die Abfrage hat zu lange gedauert. Bitte schränken Sie Filter oder Suche ein.');
    expect(loading.value).toBe(false);
  });

  it('reports a lost connection as a failed load', async () => {
    serveNetworkError(gridRoute());
    const params = rowParams();
    const { datasource, error, loading } = useRecordDatasource('companies');

    await datasource.getRows(params);

    expect(params.failCallback).toHaveBeenCalledTimes(1);
    expect(error.value).toBe('Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.');
    expect(loading.value).toBe(false);
  });

  it('marks the first block as loading until the server answers', async () => {
    const served = serveJson(gridRoute(), { rows: [], lastRow: 3 }, { held: true });
    const params = rowParams();
    const { datasource, loading } = useRecordDatasource('companies');

    const loadingRows = datasource.getRows(params);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingRows;

    expect(loading.value).toBe(false);
    expect(params.successCallback).toHaveBeenCalledWith([], 3);
  });

  it('sends the session token and asks for JSON', async () => {
    document.cookie = 'XSRF-TOKEN=test-xsrf-token';
    const served = respondWith({ rows: [], lastRow: 0 });
    const { datasource } = useRecordDatasource('companies');

    await datasource.getRows(rowParams());

    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
  });

  it('points at the expired session when the request is rejected with 419', async () => {
    serveJson(gridRoute(), {}, { status: 419 });

    const { datasource, error } = useRecordDatasource('companies');

    await datasource.getRows(rowParams());

    expect(error.value).toBe('Ihre Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.');
  });

  it('states every failure in German', async () => {
    serveEmpty(gridRoute(), { status: 500 });

    const { datasource, error } = useRecordDatasource('companies');

    await datasource.getRows(rowParams());

    expect(error.value).toBe('Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.');
  });
});

describe('useRecordDatasource hierarchy', () => {
  it('leaves the tree order out unless the grid asks for it', async () => {
    const served = respondWith({ rows: [], lastRow: 0 }, 'contacts');
    const { datasource } = useRecordDatasource('contacts');

    await datasource.getRows(rowParams());

    expect(gridRequestBody(served).hierarchy).toBe(false);
  });

  it('asks for the tree order when the grid requests it', async () => {
    const served = respondWith({ rows: [], lastRow: 0 }, 'contacts');
    const { datasource } = useRecordDatasource(
      'contacts',
      () => null,
      () => null,
      () => true,
    );

    await datasource.getRows(rowParams());

    expect(gridRequestBody(served).hierarchy).toBe(true);
  });

  it('names why the tree order was dropped above the ceiling', async () => {
    respondWith(
      {
        rows: [],
        lastRow: 0,
        hierarchy: { applied: false, reason: 'too_many_rows' },
      },
      'contacts',
    );

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
    respondWith(
      {
        rows: [],
        lastRow: 0,
        hierarchy: { applied: true, reason: null },
      },
      'contacts',
    );

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
