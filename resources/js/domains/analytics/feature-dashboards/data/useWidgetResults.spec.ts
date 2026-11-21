import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import DashboardWidgetResultsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetResultsController';
import type { ReportResult } from '@/domains/analytics/data/types/reports';
import { t } from '@/shared/testing/i18n';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { DashboardWidgetTile } from '../../data/types/dashboards';
import { RESULTS_ERROR_MESSAGE, useWidgetResults } from './useWidgetResults';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
  toast: { error: toastError, success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|failed|error|could|please)\b/i;

interface ScopedResults {
  results: ReturnType<typeof useWidgetResults>;
  stop: () => void;
}

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

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
      label: 'Unternehmen',
    },
    execution_mode: 'viewer',
    generated_at: '2026-08-10T11:58:00Z',
    result: result(total),
    currency: null,
    bucket: null,
    notice: null,
  };
}

function indexRoute(): ReturnType<typeof DashboardWidgetResultsController.index> {
  return DashboardWidgetResultsController.index({ dashboard: DASHBOARD_ID });
}

function showRoute(widgetId: string): ReturnType<typeof DashboardWidgetResultsController.show> {
  return DashboardWidgetResultsController.show({
    dashboard: DASHBOARD_ID,
    widget: widgetId,
  });
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
  return Object.values(results.tiles.value).map((entry) => entry.result?.total ?? '');
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: ACTIVE_TEAM });
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockReset();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  setUrlDefaults({});
});

describe('useWidgetResults — loading the tiles', () => {
  it('posts an empty body to the results of the dashboard and keys the tiles by widget', async () => {
    const served = serveJson(indexRoute(), {
      data: [tile('w1', '42'), tile('w2', '7')],
    });

    const { results, stop } = scoped();

    await results.refreshAll();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/dashboards/${DASHBOARD_ID}/widget-results`);
    expect(served.lastRequest()?.body).toEqual({});
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(Object.keys(results.tiles.value)).toEqual(['w1', 'w2']);
    expect(results.error.value).toBeNull();

    stop();
  });

  it('marks a single tile as pending until the server answers', async () => {
    const served = serveJson(showRoute('w1'), { data: tile('w1', '42') }, { held: true });

    const { results, stop } = scoped();

    const refreshing = results.refreshOne('w1');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(results.pending.value.has('w1')).toBe(true);
    await results.refreshOne('w1');

    served.release();
    await refreshing;

    expect(served.requests).toHaveLength(1);
    expect(results.pending.value.has('w1')).toBe(false);
    expect(totalsOf(results)).toEqual(['42']);

    stop();
  });
});

describe('useWidgetResults — when the endpoint refuses', () => {
  it('says so in German and leaves the tiles it already had alone', async () => {
    serveServerError(indexRoute(), { message: 'no' });
    serveJson(indexRoute(), { data: [tile('w1', '42')] }, { once: true });

    const { results, stop } = scoped();

    await results.refreshAll();

    expect(totalsOf(results)).toEqual(['42']);

    await results.refreshAll();

    expect(results.error.value).toBe(t(RESULTS_ERROR_MESSAGE));
    expect(String(results.error.value)).not.toMatch(ENGLISH_WORDS);
    expect(toastError).toHaveBeenCalledTimes(1);
    expect(toastError).toHaveBeenCalledWith(t(RESULTS_ERROR_MESSAGE));
    expect(totalsOf(results)).toEqual(['42']);
    expect(results.isRefreshingAll.value).toBe(false);

    stop();
  });

  it('treats a refused single tile the same way and frees it again', async () => {
    const served = serveServerError(showRoute('w1'), { message: 'no' });

    const { results, stop } = scoped();

    await results.refreshOne('w1');

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(results.error.value).toBe(t(RESULTS_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledTimes(1);
    expect(results.pending.value.has('w1')).toBe(false);

    stop();
  });

  it.each<[string, () => ServedRoute]>([
    ['the connection fails', () => serveNetworkError(indexRoute())],
    ['the answer carries no collection', () => serveJson(indexRoute(), { data: { widget_id: 'w1' } })],
  ])('reports the failure when %s', async (_name, serve) => {
    serve();

    const { results, stop } = scoped();

    await results.refreshAll();

    expect(results.error.value).toBe(t(RESULTS_ERROR_MESSAGE));
    expect(toastError).toHaveBeenCalledTimes(1);
    expect(results.tiles.value).toEqual({});
    expect(results.isRefreshingAll.value).toBe(false);

    stop();
  });

  it('reports a single tile whose connection fails', async () => {
    serveNetworkError(showRoute('w1'));

    const { results, stop } = scoped();

    await results.refreshOne('w1');

    expect(results.error.value).toBe(t(RESULTS_ERROR_MESSAGE));
    expect(results.pending.value.has('w1')).toBe(false);

    stop();
  });
});

describe('useWidgetResults — a late answer never overwrites a fresh one', () => {
  it('keeps the freshly fetched tile when the slower collective run lands last', async () => {
    const collective = serveJson(indexRoute(), { data: [tile('w1', 'ALT')] }, { held: true });
    const single = serveJson(showRoute('w1'), { data: tile('w1', 'NEU') }, { held: true });

    const { results, stop } = scoped();

    const all = results.refreshAll();
    const one = results.refreshOne('w1');
    await vi.waitFor(() => expect(collective.requests).toHaveLength(1));
    await vi.waitFor(() => expect(single.requests).toHaveLength(1));

    single.release();
    await one;

    expect(totalsOf(results)).toEqual(['NEU']);

    collective.release();
    await all;

    expect(totalsOf(results)).toEqual(['NEU']);
    expect(results.isRefreshingAll.value).toBe(false);

    stop();
  });

  it('answers two runs started back to back with a single request', async () => {
    const served = serveJson(indexRoute(), { data: [tile('w1', '42')] }, { held: true });

    const { results, stop } = scoped();

    const first = results.refreshAll();
    const second = results.refreshAll();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(results.isRefreshingAll.value).toBe(true);

    served.release();
    await Promise.all([first, second]);

    expect(served.requests).toHaveLength(1);
    expect(totalsOf(results)).toEqual(['42']);

    stop();
  });
});

describe('useWidgetResults — tearing the scope down', () => {
  it('aborts the run in flight and stops touching its state afterwards', async () => {
    const served = serveServerError(indexRoute(), { message: 'too late' }, { held: true });

    const { results, stop } = scoped();

    const run = results.refreshAll();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    stop();

    expect(served.lastRequest()?.signal.aborted).toBe(true);

    served.release();
    await run;

    expect(results.error.value).toBeNull();
    expect(results.tiles.value).toEqual({});
    expect(toastError).not.toHaveBeenCalled();
  });
});
