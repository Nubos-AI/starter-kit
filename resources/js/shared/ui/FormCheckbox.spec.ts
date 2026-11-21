import { fireEvent, render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';

describe('FormCheckbox', () => {
  it('ties the label to the checkbox', () => {
    render(FormCheckbox, { props: { label: 'Aktiv', modelValue: false } });

    expect(screen.getByRole('checkbox', { name: 'Aktiv' })).toBeTruthy();
  });

  it('uses the given id for the checkbox', () => {
    render(FormCheckbox, { props: { id: 'is_active', label: 'Aktiv', modelValue: false } });

    expect(screen.getByRole('checkbox', { name: 'Aktiv' }).getAttribute('id')).toBe('is_active');
  });

  it('emits the toggled value through v-model', async () => {
    const { emitted } = render(FormCheckbox, { props: { label: 'Aktiv', modelValue: false } });

    await fireEvent.click(screen.getByRole('checkbox', { name: 'Aktiv' }));

    expect(emitted()['update:modelValue']).toEqual([[true]]);
  });

  it('sends the value through a hidden field when a name is given', () => {
    const { container } = render(FormCheckbox, { props: { name: 'is_active', label: 'Aktiv', modelValue: true } });

    const hidden = container.querySelector('input[type="hidden"][name="is_active"]');

    expect(hidden?.getAttribute('value')).toBe('1');
    expect(screen.getByTestId('flag-is_active')).toBe(screen.getByRole('checkbox', { name: 'Aktiv' }));
  });

  it('renders neither a hidden field nor a test id without a name', () => {
    const { container } = render(FormCheckbox, { props: { label: 'Aktiv', modelValue: true } });

    expect(container.querySelector('input[type="hidden"]')).toBeNull();
    expect(container.querySelector('[data-testid]')).toBeNull();
  });

  it('shows the description and the bound error message', () => {
    render(FormCheckbox, {
      props: {
        label: 'Aktiv',
        modelValue: false,
        description: 'Gilt für alle Datensätze.',
        error: 'Bitte prüfen Sie die Angabe.',
      },
    });

    expect(screen.getByText('Gilt für alle Datensätze.')).toBeTruthy();
    expect(screen.getByText('Bitte prüfen Sie die Angabe.')).toBeTruthy();
  });

  it('points the checkbox at its error message', () => {
    const { container } = render(FormCheckbox, {
      props: { id: 'consent', label: 'Zustimmen', modelValue: false, error: 'Bitte stimmen Sie zu.' },
    });

    expect(screen.getByRole('checkbox', { name: 'Zustimmen' }).getAttribute('aria-describedby')).toBe('consent-error');
    expect(container.querySelector('#consent-error')?.textContent).toContain('Bitte stimmen Sie zu.');
  });

  it('describes the checkbox by nothing while there is no error', () => {
    render(FormCheckbox, { props: { id: 'consent', label: 'Zustimmen', modelValue: false } });

    expect(screen.getByRole('checkbox', { name: 'Zustimmen' }).hasAttribute('aria-describedby')).toBe(false);
  });

  it('centres the checkbox on the label by default and aligns it to the top for long labels', async () => {
    const { container, rerender } = render(FormCheckbox, { props: { label: 'Aktiv', modelValue: false } });

    expect(container.querySelector('[data-form-checkbox-row]')?.getAttribute('data-align')).toBe('center');

    await rerender({ label: 'Aktiv', modelValue: false, align: 'start' });

    expect(container.querySelector('[data-form-checkbox-row]')?.getAttribute('data-align')).toBe('start');
  });

  it('disables the checkbox', () => {
    render(FormCheckbox, { props: { label: 'Aktiv', modelValue: false, disabled: true } });

    expect(screen.getByRole('checkbox', { name: 'Aktiv' }).hasAttribute('disabled')).toBe(true);
  });

  it('forwards caller hooks to the checkbox instead of the frame', () => {
    const { container } = render(FormCheckbox, {
      props: { label: 'Aktiv', modelValue: false },
      attrs: { class: 'sm:col-span-2', 'data-policy-checkbox': 'records' },
    });

    const frame = container.querySelector('[data-form-checkbox]');

    expect(screen.getByRole('checkbox', { name: 'Aktiv' }).getAttribute('data-policy-checkbox')).toBe('records');
    expect(frame?.hasAttribute('data-policy-checkbox')).toBe(false);
  });
});
