import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { cancel, show } from '@/actions/App/Http/Controllers/Formulas/FormulaBackfillsController';
import { serveEmpty, serveJson, serveNetworkError } from '@/shared/testing/msw';
import type { ServedRoute, ServeOptions } from '@/shared/testing/msw';
import { useFakePollTimers } from '@/shared/testing/pendingTimers';
import { setUrlDefaults } from '@/wayfinder';
import type { BackfillStatus } from '../../util-record-format/types/formulas';
import { useFormulaBackfill } from './useFormulaBackfill';

setUrlDefaults({ activeTeam: 'nubos' });

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

const showRoute = show({ objectType: OBJECT_TYPE });

const cancelRoute = cancel({ backfillRun: RUN_ID });

function serveRun(run: RunPayload | null, options: ServeOptions = {}): ServedRoute {
  return serveJson(showRoute, { run }, options);
}

function serveCancelled(options: ServeOptions = {}): ServedRoute {
  return serveJson(
    cancelRoute,
    {
      run: runPayload({
        status: 'cancelled',
        finishedAt: '2026-07-29T10:00:00+00:00',
      }),
    },
    options,
  );
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

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useFormulaBackfill — first read', () => {
  it('asks the status endpoint for the object type slug right away, without waiting for a mount hook', async () => {
    useFakePollTimers();

    const polled = serveRun(runPayload());
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value).not.toBeNull());

      expect(polled.requests).toHaveLength(1);
      expect(polled.lastRequest()?.method).toBe('GET');
      expect(polled.lastRequest()?.url.pathname).toBe(`/nubos/engine/formula-backfills/${OBJECT_TYPE}`);
      expect(polled.lastRequest()?.headers.get('Accept')).toBe('application/json');
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
      useFakePollTimers();

      const polled = serveRun(runPayload({ status, processedCount: 120 }));
      const { scope, api } = createBackfill();

      try {
        await vi.waitFor(() => expect(api.run.value?.status).toBe(status));

        expect(polled.requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

        expect(polled.requests).toHaveLength(1);
      } finally {
        scope.stop();
      }
    },
  );

  it('keeps polling while the run is still running', async () => {
    useFakePollTimers();

    const later = serveRun(runPayload({ processedCount: 80 }));
    const first = serveRun(runPayload({ processedCount: 40 }), {
      once: true,
    });
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value?.processedCount).toBe(40));

      expect(first.requests).toHaveLength(1);
      expect(later.requests).toHaveLength(0);

      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      await vi.waitFor(() => expect(api.run.value?.processedCount).toBe(80));

      expect(later.requests).toHaveLength(1);
    } finally {
      scope.stop();
    }
  });

  it('stops after an empty answer instead of polling an object type without a run', async () => {
    useFakePollTimers();

    const polled = serveRun(null);
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(polled.requests).toHaveLength(1));
      await vi.advanceTimersByTimeAsync(1);

      expect(api.run.value).toBeNull();

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(1);
    } finally {
      scope.stop();
    }
  });

  it.each<[string, number]>([
    ['a rejected read', 403],
    ['a missing run', 404],
  ])('goes quiet on %s without surfacing an error to the user', async (_label, statusCode) => {
    useFakePollTimers();

    const { toast } = await import('vue-sonner');
    const polled = serveJson(showRoute, {}, { status: statusCode });
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(polled.requests).toHaveLength(1));
      await vi.advanceTimersByTimeAsync(1);

      expect(api.run.value).toBeNull();

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(1);
      expect(toast.error).not.toHaveBeenCalled();
      expect(toast.warning).not.toHaveBeenCalled();
    } finally {
      scope.stop();
    }
  });

  it('retries a read the network dropped on the next interval', async () => {
    useFakePollTimers();

    serveRun(runPayload({ processedCount: 80 }));
    const dropped = serveNetworkError(showRoute, { once: true });
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(dropped.requests).toHaveLength(1));
      await vi.advanceTimersByTimeAsync(1);

      expect(api.run.value).toBeNull();

      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      await vi.waitFor(() => expect(api.run.value?.processedCount).toBe(80));
    } finally {
      scope.stop();
    }
  });
});

describe('useFormulaBackfill — cancelling', () => {
  it('posts to the cancel endpoint with the run id, adopts the returned row and ends the loop', async () => {
    useFakePollTimers();

    const polled = serveRun(runPayload());
    const cancelled = serveCancelled();
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value).not.toBeNull());

      const pollsBeforeCancel = polled.requests.length;

      await api.cancel();

      expect(cancelled.requests).toHaveLength(1);
      expect(cancelled.lastRequest()?.method).toBe('POST');
      expect(cancelled.lastRequest()?.url.pathname).toBe(`/nubos/engine/formula-backfills/${RUN_ID}/cancel`);
      expect(cancelled.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');

      expect(api.run.value?.status).toBe('cancelled');
      expect(api.run.value?.processedCount).toBe(40);
      expect(api.isCancelling.value).toBe(false);

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(polled.requests).toHaveLength(pollsBeforeCancel);
    } finally {
      scope.stop();
    }
  });

  it('reports the cancellation as in flight until the endpoint answers', async () => {
    useFakePollTimers();

    serveRun(runPayload());
    const cancelled = serveCancelled({ held: true });

    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value).not.toBeNull());

      expect(api.isCancelling.value).toBe(false);

      const pending = api.cancel();
      await vi.waitFor(() => expect(cancelled.requests).toHaveLength(1));

      expect(api.isCancelling.value).toBe(true);
      await api.cancel();
      expect(cancelled.requests).toHaveLength(1);

      cancelled.release();
      await pending;

      expect(api.isCancelling.value).toBe(false);
      expect(api.run.value?.status).toBe('cancelled');
      expect(api.hasCancelFailed.value).toBe(false);
    } finally {
      scope.stop();
    }
  });

  it('does nothing when there is no run to cancel', async () => {
    useFakePollTimers();

    const polled = serveRun(null);
    const cancelled = serveCancelled();
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(polled.requests).toHaveLength(1));

      await api.cancel();

      expect(cancelled.requests).toHaveLength(0);
      expect(api.isCancelling.value).toBe(false);
      expect(api.hasCancelFailed.value).toBe(false);
    } finally {
      scope.stop();
    }
  });

  it('clears an earlier failure once a retried cancellation succeeds', async () => {
    useFakePollTimers();

    serveRun(runPayload());
    serveCancelled();
    serveEmpty(cancelRoute, { status: 409, once: true });
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value).not.toBeNull());

      await api.cancel();
      expect(api.hasCancelFailed.value).toBe(true);

      await api.cancel();

      expect(api.hasCancelFailed.value).toBe(false);
      expect(api.run.value?.status).toBe('cancelled');
    } finally {
      scope.stop();
    }
  });
});

describe('useFormulaBackfill — completion callback', () => {
  it('calls onCompleted exactly once when the run completes', async () => {
    useFakePollTimers();

    const onCompleted = vi.fn();
    serveRun(runPayload({ status: 'completed', processedCount: 120 }));
    const { scope } = createBackfill({ onCompleted });

    try {
      await vi.waitFor(() => expect(onCompleted).toHaveBeenCalledTimes(1));

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(onCompleted).toHaveBeenCalledTimes(1);
    } finally {
      scope.stop();
    }
  });

  it.each(['cancelled', 'failed'] as BackfillStatus[])(
    'does not call onCompleted for a run that ended as %s',
    async (status) => {
      useFakePollTimers();

      const onCompleted = vi.fn();
      serveRun(runPayload({ status }));
      const { scope, api } = createBackfill({ onCompleted });

      try {
        await vi.waitFor(() => expect(api.run.value?.status).toBe(status));

        expect(onCompleted).not.toHaveBeenCalled();
      } finally {
        scope.stop();
      }
    },
  );
});

describe('useFormulaBackfill — a refused cancellation', () => {
  it.each<[string, () => ServedRoute]>([
    ['a refused answer', () => serveEmpty(cancelRoute, { status: 409 })],
    ['an unreachable endpoint', () => serveNetworkError(cancelRoute)],
  ])('keeps the running row and keeps polling after %s', async (_label, serveCancel) => {
    useFakePollTimers();

    const polled = serveRun(runPayload());
    const cancelled = serveCancel();
    const { scope, api } = createBackfill();

    try {
      await vi.waitFor(() => expect(api.run.value).not.toBeNull());

      await api.cancel();

      const pollsAfterCancel = polled.requests.length;

      expect(cancelled.requests).toHaveLength(1);
      expect(api.run.value?.status).toBe('running');
      expect(api.isCancelling.value).toBe(false);
      expect(api.hasCancelFailed.value).toBe(true);

      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 2);
      await vi.waitFor(() => expect(polled.requests.length).toBeGreaterThan(pollsAfterCancel));
    } finally {
      scope.stop();
    }
  });
});
