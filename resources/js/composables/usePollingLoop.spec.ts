import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { usePollingLoop } from '@/composables/usePollingLoop';

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
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

        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ intervalMs: 1000 }),
        );

        loop.schedule(task);
        expect(task).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1000);
        expect(task).toHaveBeenCalledTimes(1);

        scope.stop();
    });

    it('clear cancels a scheduled task', () => {
        vi.useFakeTimers();
        const task = vi.fn();

        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ intervalMs: 1000 }),
        );

        loop.schedule(task);
        loop.clear();
        vi.advanceTimersByTime(5000);

        expect(task).not.toHaveBeenCalled();
        scope.stop();
    });

    it('registerFailure returns true only once the failure cap is reached', () => {
        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ maxFailures: 3 }),
        );

        expect(loop.registerFailure()).toBe(false);
        expect(loop.registerFailure()).toBe(false);
        expect(loop.registerFailure()).toBe(true);

        scope.stop();
    });

    it('resetFailures lets the counter start over', () => {
        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ maxFailures: 2 }),
        );

        expect(loop.registerFailure()).toBe(false);
        loop.resetFailures();
        expect(loop.registerFailure()).toBe(false);

        scope.stop();
    });

    it('reaching the failure cap clears any pending task', () => {
        vi.useFakeTimers();
        const task = vi.fn();

        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ maxFailures: 1, intervalMs: 1000 }),
        );

        loop.schedule(task);
        expect(loop.registerFailure()).toBe(true);
        vi.advanceTimersByTime(5000);

        expect(task).not.toHaveBeenCalled();
        scope.stop();
    });

    it('request merges credentials and an abort signal into the fetch init', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({ ok: true } as Response),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { scope, value: loop } = inScope(() => usePollingLoop());

        await loop.request('/x', { method: 'GET' });

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/x');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect(init.signal).toBeInstanceOf(AbortSignal);

        scope.stop();
    });

    it('clears the timer when the scope is disposed', () => {
        vi.useFakeTimers();
        const task = vi.fn();

        const { scope, value: loop } = inScope(() =>
            usePollingLoop({ intervalMs: 1000 }),
        );

        loop.schedule(task);
        scope.stop();
        vi.advanceTimersByTime(5000);

        expect(task).not.toHaveBeenCalled();
    });
});
