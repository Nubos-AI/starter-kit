import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { inComponent, unmountComposableHosts } from '@/shared/testing/composableHost';
import { t } from '@/shared/testing/i18n';
import { useClipboard } from './useClipboard';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast }));

function stubClipboard(writeText: (text: string) => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
}

describe('useClipboard', () => {
  afterEach(() => {
    vi.clearAllMocks();
    vi.useRealTimers();
    unmountComposableHosts();
  });

  it('writes the text, reports the copy and confirms it with a success toast', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    stubClipboard(writeText);
    const clipboard = inComponent(() => useClipboard());

    const outcome = await clipboard.copy('https://example.com/pay/abc');

    expect(outcome).toBe('copied');
    expect(writeText).toHaveBeenCalledWith('https://example.com/pay/abc');
    expect(clipboard.copied.value).toBe(true);
    expect(toast.success).toHaveBeenCalledWith(t('i18n.composables.use_clipboard.copied'));
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('reports a refused copy and tells the user with an error toast', async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    const clipboard = inComponent(() => useClipboard());

    const outcome = await clipboard.copy('secret');

    expect(outcome).toBe('failed');
    expect(clipboard.copied.value).toBe(false);
    expect(toast.error).toHaveBeenCalledWith(t('i18n.composables.use_clipboard.copy_failed'));
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('reports a failure when the browser offers no clipboard at all', async () => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    const clipboard = inComponent(() => useClipboard());

    expect(await clipboard.copy('secret')).toBe('failed');
    expect(toast.error).toHaveBeenCalled();
  });

  it('uses the messages the caller passes and stays silent on success when asked to', async () => {
    stubClipboard(vi.fn().mockResolvedValue(undefined));
    const silent = inComponent(() =>
      useClipboard({ copied: false, failed: 'Der Schlüssel ließ sich nicht kopieren.' }),
    );

    await silent.copy('key');

    expect(toast.success).not.toHaveBeenCalled();

    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    await silent.copy('key');

    expect(toast.error).toHaveBeenCalledWith('Der Schlüssel ließ sich nicht kopieren.');
  });

  it('resets the copied flag after a moment', async () => {
    vi.useFakeTimers();
    stubClipboard(vi.fn().mockResolvedValue(undefined));
    const clipboard = inComponent(() => useClipboard());

    await clipboard.copy('text');
    await flushPromises();

    expect(clipboard.copied.value).toBe(true);

    vi.advanceTimersByTime(2000);

    expect(clipboard.copied.value).toBe(false);
  });
});
