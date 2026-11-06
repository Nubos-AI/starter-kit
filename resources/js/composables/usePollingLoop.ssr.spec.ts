// @vitest-environment node

import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useFormulaBackfill } from '@/composables/useFormulaBackfill';
import { usePollingLoop } from '@/composables/usePollingLoop';
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

    it('keeps the formula backfill from fetching a relative url', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const scope = effectScope();
        scope.run(() => useFormulaBackfill('persons'));

        expect(fetchMock).not.toHaveBeenCalled();

        scope.stop();
        vi.unstubAllGlobals();
    });
});
