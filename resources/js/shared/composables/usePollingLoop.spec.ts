import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { cancel } from '@/actions/App/Http/Controllers/Formulas/FormulaBackfillsController';
import { usePollingLoop } from '@/shared/composables/usePollingLoop';
import { serveEmpty } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const cancelRoute = cancel({ backfillRun: 'run-1' });

afterEach(() => {
  vi.useRealTimers();
  vi.clearAllMocks();
});

function inScope<T>(factory: () => T): {
  scope: ReturnType<typeof effectScope>;
  value: T;
} {
  const scope = effectScope();
  let value: T | undefined;

  scope.run(() => {
    value = factory();
  });

  return { scope, value: value as T };
}

describe('usePollingLoop', () => {
  it('runs the scheduled task after the interval', () => {
    vi.useFakeTimers();
    const task = vi.fn();

    const { scope, value: loop } = inScope(() => usePollingLoop({ intervalMs: 1000 }));

    loop.schedule(task);
    expect(task).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1000);
    expect(task).toHaveBeenCalledTimes(1);

    scope.stop();
  });

  it('clear cancels a scheduled task', () => {
    vi.useFakeTimers();
    const task = vi.fn();

    const { scope, value: loop } = inScope(() => usePollingLoop({ intervalMs: 1000 }));

    loop.schedule(task);
    loop.clear();
    vi.advanceTimersByTime(5000);

    expect(task).not.toHaveBeenCalled();
    scope.stop();
  });

  it('registerFailure returns true only once the failure cap is reached', () => {
    const { scope, value: loop } = inScope(() => usePollingLoop({ maxFailures: 3 }));

    expect(loop.registerFailure()).toBe(false);
    expect(loop.registerFailure()).toBe(false);
    expect(loop.registerFailure()).toBe(true);

    scope.stop();
  });

  it('resetFailures lets the counter start over', () => {
    const { scope, value: loop } = inScope(() => usePollingLoop({ maxFailures: 2 }));

    expect(loop.registerFailure()).toBe(false);
    loop.resetFailures();
    expect(loop.registerFailure()).toBe(false);

    scope.stop();
  });

  it('reaching the failure cap clears any pending task', () => {
    vi.useFakeTimers();
    const task = vi.fn();

    const { scope, value: loop } = inScope(() => usePollingLoop({ maxFailures: 1, intervalMs: 1000 }));

    loop.schedule(task);
    expect(loop.registerFailure()).toBe(true);
    vi.advanceTimersByTime(5000);

    expect(task).not.toHaveBeenCalled();
    scope.stop();
  });

  it('request sends the call with the caller init merged in', async () => {
    const served = serveEmpty(cancelRoute);

    const { scope, value: loop } = inScope(() => usePollingLoop());

    const response = await loop.request(cancelRoute.url, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    });

    expect(response.status).toBe(204);
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/formula-backfills/run-1/cancel');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');

    scope.stop();
  });

  it('request aborts a call that outlives the request timeout', async () => {
    const served = serveEmpty(cancelRoute, { held: true });

    const { scope, value: loop } = inScope(() => usePollingLoop({ requestTimeoutMs: 20 }));

    await expect(loop.request(cancelRoute.url, { method: 'POST' })).rejects.toThrow();
    expect(served.lastRequest()?.signal.aborted).toBe(true);

    served.release();
    scope.stop();
  });

  it('clears the timer when the scope is disposed', () => {
    vi.useFakeTimers();
    const task = vi.fn();

    const { scope, value: loop } = inScope(() => usePollingLoop({ intervalMs: 1000 }));

    loop.schedule(task);
    scope.stop();
    vi.advanceTimersByTime(5000);

    expect(task).not.toHaveBeenCalled();
  });
});
