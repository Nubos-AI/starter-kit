import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { preview as previewRoute } from '@/routes/reports';
import type { ServedRoute } from '@/shared/testing/msw';
import { recordEveryRequest, serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { ReportResult } from '../../data/types/reports';
import { useReportPreview } from './useReportPreview';

interface ScopedPreview {
  preview: ReturnType<typeof useReportPreview>;
  stop: () => void;
}

setUrlDefaults({ activeTeam: 'nubos' });

const route = previewRoute();

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

function scoped(): ScopedPreview {
  const scope = effectScope();
  const created = scope.run(() => useReportPreview());

  if (created === undefined) {
    throw new Error('The preview composable did not run inside the scope.');
  }

  return { preview: created, stop: () => scope.stop() };
}

function serveResult(
  body: Parameters<typeof serveJson>[1],
  options: Parameters<typeof serveJson>[2] = {},
): ServedRoute {
  return serveJson(route, body, options);
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=preview-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.useRealTimers();
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
    const served = serveResult({ data: result() }, { held: true });

    const { preview, stop } = scoped();

    const run = preview.run(PAYLOAD);

    expect(preview.state.value).toBe('loading');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    expect(preview.state.value).toBe('loading');

    served.release();
    await run;

    expect(preview.state.value).toBe('settled');

    stop();
  });

  it('posts the definition to the shared execution endpoint', async () => {
    const requestLog = recordEveryRequest();
    const served = serveResult({ data: result() });

    const { preview, stop } = scoped();

    await preview.run(PAYLOAD);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/reports/preview');
    expect(served.lastRequest()?.method).toBe('POST');
    expect(requestLog.requests.map((request) => request.credentials)).toEqual(['same-origin']);
    expect(served.lastRequest()?.body).toEqual(PAYLOAD);

    stop();
  });

  it('sends the shared request headers including the XSRF token', async () => {
    const served = serveResult({ data: result() });

    const { preview, stop } = scoped();

    await preview.run(PAYLOAD);

    const headers = served.lastRequest()?.headers;

    expect(headers?.get('Content-Type')).toBe('application/json');
    expect(headers?.get('Accept')).toBe('application/json');
    expect(headers?.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(headers?.get('X-XSRF-TOKEN')).toBe('preview-xsrf-token');

    stop();
  });
});

describe('useReportPreview — answers', () => {
  it('reads the result out of the data envelope and never off the root', async () => {
    serveResult({
      data: result(),
      rows: [],
      record_count: 0,
    });

    const { preview, stop } = scoped();

    await preview.run(PAYLOAD);

    expect(preview.result.value?.record_count).toBe(6);
    expect(preview.result.value?.rows).toHaveLength(1);
    expect(preview.refusal.value).toBeNull();

    stop();
  });

  it('turns a 422 into the refusal reason and drops the English message', async () => {
    serveResult(
      {
        message: 'Grouping by a time span requires a date.',
        reason: 'unsupported_grouping',
      },
      { status: 422 },
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

  it.each<[string, () => ServedRoute]>([
    ['a forbidden answer', () => serveResult({ message: 'nope' }, { status: 403 })],
    ['an expired session', () => serveResult({ message: 'nope' }, { status: 419 })],
    ['a server fault', () => serveServerError(route, { message: 'nope' })],
    ['a refusal without a reason', () => serveResult({ message: 'nope' }, { status: 422 })],
    ['a successful answer without a data envelope', () => serveResult({ rows: [] })],
    ['a broken connection', () => serveNetworkError(route)],
  ])('turns %s into the fallback refusal instead of a stuck skeleton', async (_label, serve) => {
    serve();

    const { preview, stop } = scoped();

    await preview.run(PAYLOAD);

    expect(preview.refusal.value).toEqual({ reason: '' });
    expect(preview.result.value).toBeNull();
    expect(preview.state.value).toBe('settled');

    stop();
  });

  it('turns a request that runs into the timeout into the fallback refusal', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const served = serveResult({ data: result() }, { held: true });

    const { preview, stop } = scoped();

    const run = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await vi.advanceTimersByTimeAsync(15000);
    await run;
    served.release();

    expect(served.lastRequest()?.signal.aborted).toBe(true);
    expect(preview.refusal.value).toEqual({ reason: '' });
    expect(preview.result.value).toBeNull();
    expect(preview.state.value).toBe('settled');

    stop();
  });

  it('clears a previous refusal when the next run succeeds', async () => {
    const succeeded = serveResult({ data: result() });
    const refused = serveResult({ reason: 'unknown_field' }, { status: 422, once: true });

    const { preview, stop } = scoped();

    await preview.run(PAYLOAD);
    expect(preview.refusal.value).toEqual({ reason: 'unknown_field' });

    await preview.run(PAYLOAD);

    expect(refused.requests).toHaveLength(1);
    expect(succeeded.requests).toHaveLength(1);
    expect(preview.refusal.value).toBeNull();
    expect(preview.result.value?.record_count).toBe(6);

    stop();
  });

  it('resets back to the idle state on request', async () => {
    serveResult({ data: result() });

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
    const newest = serveResult({ data: result({ record_count: 2, total: '2' }) }, { held: true });
    const replaced = serveResult({ data: result({ record_count: 99, total: '99' }) }, { held: true, once: true });

    const { preview, stop } = scoped();

    const firstRun = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(replaced.requests).toHaveLength(1));
    const secondRun = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(newest.requests).toHaveLength(1));

    newest.release();
    await secondRun;

    replaced.release();
    await firstRun;

    expect(preview.result.value?.record_count).toBe(2);
    expect(preview.refusal.value).toBeNull();
    expect(preview.state.value).toBe('settled');

    stop();
  });

  it('aborts the request it replaced', async () => {
    const newest = serveResult({ data: result() }, { held: true });
    const replaced = serveResult({ data: result() }, { held: true, once: true });

    const { preview, stop } = scoped();

    const firstRun = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(replaced.requests).toHaveLength(1));
    const secondRun = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(newest.requests).toHaveLength(1));

    expect(replaced.lastRequest()?.signal.aborted).toBe(true);
    expect(newest.lastRequest()?.signal.aborted).toBe(false);

    newest.release();
    replaced.release();

    await Promise.all([firstRun, secondRun]);

    stop();
  });

  it('aborts an in flight request when its scope is torn down mid flight', async () => {
    const served = serveResult({ data: result() }, { held: true });

    const { preview, stop } = scoped();

    const run = preview.run(PAYLOAD);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    stop();

    expect(served.lastRequest()?.signal.aborted).toBe(true);

    served.release();
    await run;

    expect(preview.result.value).toBeNull();
  });
});
