import { fireEvent, render, screen } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { t } from '@/shared/testing/i18n';
import ApiTokenSecretDialog from './ApiTokenSecretDialog.vue';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast }));

function stubClipboard(writeText: (text: string) => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
}

describe('ApiTokenSecretDialog', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('copies the secret and confirms it on the button instead of a toast', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    stubClipboard(writeText);
    render(ApiTokenSecretDialog, { props: { secret: 'plain-text-secret' } });

    await fireEvent.click(
      await screen.findByRole('button', { name: t('i18n.components.api.api_token_secret_dialog.copy_token') }),
    );
    await flushPromises();

    expect(writeText).toHaveBeenCalledWith('plain-text-secret');
    expect(screen.getByRole('button', { name: t('i18n.components.api.api_token_secret_dialog.copied') })).toBeDefined();
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('reports a refused copy with the message the caller passes', async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    render(ApiTokenSecretDialog, {
      props: { secret: 'plain-text-secret', copyErrorMessage: 'Das Geheimnis konnte nicht kopiert werden.' },
    });

    await fireEvent.click(
      await screen.findByRole('button', { name: t('i18n.components.api.api_token_secret_dialog.copy_token') }),
    );
    await flushPromises();

    expect(toast.error).toHaveBeenCalledWith('Das Geheimnis konnte nicht kopiert werden.');
  });

  it('falls back to the token copy failure message', async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    render(ApiTokenSecretDialog, { props: { secret: 'plain-text-secret' } });

    await fireEvent.click(
      await screen.findByRole('button', { name: t('i18n.components.api.api_token_secret_dialog.copy_token') }),
    );
    await flushPromises();

    expect(toast.error).toHaveBeenCalledWith(
      t('i18n.components.api.api_token_secret_dialog.the_token_could_not_be_copied'),
    );
  });
});
