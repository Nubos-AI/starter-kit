import { onScopeDispose } from 'vue';

const DEFAULT_INTERVAL_MS = 1500;

const DEFAULT_REQUEST_TIMEOUT_MS = 15000;

const DEFAULT_MAX_FAILURES = 5;

export interface UsePollingLoopOptions {
    intervalMs?: number;
    requestTimeoutMs?: number;
    maxFailures?: number;
}

export interface UsePollingLoopReturn {
    request: (url: string, init?: RequestInit) => Promise<Response>;
    start: (task: () => void) => void;
    schedule: (task: () => void) => void;
    clear: () => void;
    resetFailures: () => void;
    registerFailure: () => boolean;
}

export function usePollingLoop(
    options: UsePollingLoopOptions = {},
): UsePollingLoopReturn {
    const intervalMs = options.intervalMs ?? DEFAULT_INTERVAL_MS;
    const requestTimeoutMs =
        options.requestTimeoutMs ?? DEFAULT_REQUEST_TIMEOUT_MS;
    const maxFailures = options.maxFailures ?? DEFAULT_MAX_FAILURES;

    const isBrowser = typeof window !== 'undefined';

    let timer: ReturnType<typeof setTimeout> | null = null;
    let failures = 0;

    const clear = (): void => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const schedule = (task: () => void): void => {
        clear();

        if (!isBrowser) {
            return;
        }

        timer = setTimeout(task, intervalMs);
    };

    const start = (task: () => void): void => {
        if (!isBrowser) {
            return;
        }

        task();
    };

    const request = async (
        url: string,
        init: RequestInit = {},
    ): Promise<Response> => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), requestTimeoutMs);

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
        }
    };

    const resetFailures = (): void => {
        failures = 0;
    };

    const registerFailure = (): boolean => {
        failures += 1;

        if (failures >= maxFailures) {
            clear();

            return true;
        }

        return false;
    };

    onScopeDispose(clear);

    return { request, start, schedule, clear, resetFailures, registerFailure };
}
