import { fireEvent, render, screen } from '@testing-library/vue';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { t } from '@/shared/testing/i18n';
import CopyField from '@/shared/ui/CopyField.vue';

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast }));

function stubClipboard(writeText: (text: string) => Promise<void>): void {
  Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
}

describe('CopyField', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('shows the value read-only and copies it on request', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    stubClipboard(writeText);
    const { container } = render(CopyField, { props: { value: 'https://crm.example.com/meet/anna', id: 'link' } });

    const field = container.querySelector<HTMLInputElement>('input[data-copy-field-value]');

    expect(field?.value).toBe('https://crm.example.com/meet/anna');
    expect(field?.readOnly).toBe(true);
    expect(field?.id).toBe('link');

    await fireEvent.click(screen.getByRole('button', { name: t('i18n.components.copy_field.copy') }));
    await flushPromises();

    expect(writeText).toHaveBeenCalledWith('https://crm.example.com/meet/anna');
    expect(toast.success).toHaveBeenCalledWith(t('i18n.composables.use_clipboard.copied'));
  });

  it('tells the user when the browser refuses to copy', async () => {
    stubClipboard(vi.fn().mockRejectedValue(new Error('denied')));
    render(CopyField, { props: { value: 'https://crm.example.com/meet/anna' } });

    await fireEvent.click(screen.getByRole('button', { name: t('i18n.components.copy_field.copy') }));
    await flushPromises();

    expect(toast.error).toHaveBeenCalledWith(t('i18n.composables.use_clipboard.copy_failed'));
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('renders a multi-line snippet in a monospaced text area', () => {
    const { container } = render(CopyField, { props: { value: '<script src="x"></script>', multiline: true } });

    const area = container.querySelector<HTMLTextAreaElement>('textarea[data-copy-field-value]');

    expect(area?.value).toBe('<script src="x"></script>');
    expect(area?.readOnly).toBe(true);
    expect(container.querySelector('input')).toBeNull();
  });

  it('uses the copy label and messages the caller passes', async () => {
    stubClipboard(vi.fn().mockResolvedValue(undefined));
    render(CopyField, {
      props: { value: 'abc', copyLabel: 'Code kopieren', copiedMessage: 'Der Code ist kopiert.' },
    });

    await fireEvent.click(screen.getByRole('button', { name: 'Code kopieren' }));
    await flushPromises();

    expect(toast.success).toHaveBeenCalledWith('Der Code ist kopiert.');
  });
});
