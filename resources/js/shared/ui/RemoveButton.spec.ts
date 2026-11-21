import { fireEvent, render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import RemoveButton from '@/shared/ui/RemoveButton.vue';

describe('RemoveButton', () => {
  it('is an icon button named by its label', () => {
    render(RemoveButton, { props: { label: 'Stufe 2 entfernen' } });

    const button = screen.getByRole('button', { name: 'Stufe 2 entfernen' });

    expect(button.getAttribute('type')).toBe('button');
    expect(button.getAttribute('title')).toBe('Stufe 2 entfernen');
    expect(button.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    expect(button.textContent?.trim()).toBe('');
  });

  it('emits click when pressed', async () => {
    const { emitted } = render(RemoveButton, { props: { label: 'Zeile entfernen' } });

    await fireEvent.click(screen.getByRole('button'));

    expect(emitted('click')).toHaveLength(1);
  });

  it('stays inert while disabled', async () => {
    const { emitted } = render(RemoveButton, { props: { label: 'Zeile entfernen', disabled: true } });

    const button = screen.getByRole('button');

    expect(button.hasAttribute('disabled')).toBe(true);

    await fireEvent.click(button);

    expect(emitted('click')).toBeUndefined();
  });

  it('passes data attributes through to the button', () => {
    const { container } = render(RemoveButton, {
      props: { label: 'Zeile entfernen' },
      attrs: { 'data-tax-rate-remove': '' },
    });

    expect(container.querySelector('button[data-tax-rate-remove]')).not.toBeNull();
  });
});
