import { fireEvent, render, screen } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { qrCode, secretKey } from '@/routes/two-factor';
import { t } from '@/shared/testing/i18n';
import { serveJson } from '@/shared/testing/msw';
import { useTwoFactorAuth } from '../../data/data/useTwoFactorAuth';
import TwoFactorSetupModal from './TwoFactorSetupModal.vue';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast }));

function stubClipboard(writeText: (text: string) => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
}

async function openWithSetupKey(): Promise<void> {
  serveJson(qrCode(), { svg: '<svg />', url: 'otpauth://x' });
  serveJson(secretKey(), { secretKey: 'ABCD-EFGH' });
  const { rerender } = render(TwoFactorSetupModal, {
    props: { requiresConfirmation: false, twoFactorEnabled: false, isOpen: false },
  });

  await rerender({ requiresConfirmation: false, twoFactorEnabled: false, isOpen: true });
  await flushPromises();
}

describe('TwoFactorSetupModal — copying the manual setup key', () => {
  afterEach(() => {
    useTwoFactorAuth().clearTwoFactorAuthData();
    vi.clearAllMocks();
  });

  it('copies the setup key and confirms it on the button instead of a toast', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    stubClipboard(writeText);
    await openWithSetupKey();

    await fireEvent.click(
      await screen.findByRole('button', { name: t('i18n.components.two_factor_setup_modal.copy_key') }),
    );
    await flushPromises();

    expect(writeText).toHaveBeenCalledWith('ABCD-EFGH');
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('tells the user when the browser refuses to copy the setup key', async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    await openWithSetupKey();

    await fireEvent.click(
      await screen.findByRole('button', { name: t('i18n.components.two_factor_setup_modal.copy_key') }),
    );
    await flushPromises();

    expect(toast.error).toHaveBeenCalledWith(t('i18n.composables.use_clipboard.copy_failed'));
  });
});
