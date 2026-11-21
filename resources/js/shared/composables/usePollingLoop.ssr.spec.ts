// @vitest-environment node

import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { show } from '@/actions/App/Http/Controllers/Formulas/FormulaBackfillsController';
import { useFormulaBackfill } from '@/domains/business-data/ui-record-field/composables/useFormulaBackfill';
import { usePollingLoop } from '@/shared/composables/usePollingLoop';
import { serveJson } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

describe('polling on the server', () => {
  it('never runs a task that was handed to start', () => {
    const task = vi.fn();
    const scope = effectScope();

    scope.run(() => usePollingLoop().start(task));

    expect(task).not.toHaveBeenCalled();

    scope.stop();
  });

  it('schedules no timer', () => {
    const task = vi.fn();
    const scope = effectScope();

    vi.useFakeTimers();
    scope.run(() => usePollingLoop().schedule(task));
    vi.advanceTimersByTime(60000);
    vi.useRealTimers();

    expect(task).not.toHaveBeenCalled();

    scope.stop();
  });

  it('keeps the formula backfill from fetching a relative url', async () => {
    const served = serveJson(show({ objectType: 'persons' }), {
      run: null,
    });

    const scope = effectScope();
    scope.run(() => useFormulaBackfill('persons'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(served.requests).toHaveLength(0);

    scope.stop();
  });
});
