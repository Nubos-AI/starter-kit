import { vi } from 'vitest';

type TimerHandle = ReturnType<typeof setTimeout>;

const nativeSetTimeout = globalThis.setTimeout;
const nativeClearTimeout = globalThis.clearTimeout;
const shortTimers = new Set<TimerHandle>();
const shortTimerLimitMs = 250;
const drainRoundMs = 25;
const drainRoundLimit = 40;

function trackedSetTimeout(
  handler: (...callbackArgs: unknown[]) => void,
  delay?: number,
  ...args: unknown[]
): TimerHandle {
  if ((delay ?? 0) > shortTimerLimitMs) {
    return nativeSetTimeout(handler, delay, ...args);
  }

  const handle = nativeSetTimeout(() => {
    shortTimers.delete(handle);
    handler(...args);
  }, delay);

  shortTimers.add(handle);

  return handle;
}

function trackedClearTimeout(handle?: TimerHandle): void {
  if (handle !== undefined) {
    shortTimers.delete(handle);
  }

  nativeClearTimeout(handle);
}

export function trackShortTimers(): void {
  globalThis.setTimeout = trackedSetTimeout as unknown as typeof setTimeout;
  globalThis.clearTimeout = trackedClearTimeout as unknown as typeof clearTimeout;
}

export async function drainShortTimers(): Promise<void> {
  for (let round = 0; shortTimers.size > 0 && round < drainRoundLimit; round++) {
    await new Promise<void>((resolve) => {
      nativeSetTimeout(resolve, drainRoundMs);
    });
  }
}

export function useFakePollTimers(): void {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
}
