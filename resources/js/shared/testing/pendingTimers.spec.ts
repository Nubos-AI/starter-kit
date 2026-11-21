import { describe, expect, it, vi } from 'vitest';
import { drainShortTimers } from '@/shared/testing/pendingTimers';

describe('pending timers before the environment is torn down', () => {
  it('waits for a short timer chain that a destroyed component left behind', async () => {
    const lateDomWork = vi.fn();

    window.setTimeout(() => {
      window.setTimeout(lateDomWork, 100);
    }, 0);

    await drainShortTimers();

    expect(lateDomWork).toHaveBeenCalledOnce();
  });

  it('does not wait for a cleared timer or a long timer', async () => {
    const clearedWork = vi.fn();
    const longWork = vi.fn();
    const startedAt = Date.now();

    window.clearTimeout(window.setTimeout(clearedWork, 100));
    const longTimer = window.setTimeout(longWork, 5000);

    await drainShortTimers();

    expect(Date.now() - startedAt).toBeLessThan(500);
    expect(clearedWork).not.toHaveBeenCalled();
    expect(longWork).not.toHaveBeenCalled();

    window.clearTimeout(longTimer);
  });
});
