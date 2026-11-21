import { fireEvent, render, screen, waitFor } from '@testing-library/vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { t } from '@/shared/testing/i18n';
import FormField from '@/shared/ui/FormField.vue';

describe('FormField', () => {
  it('ties the label to the control through the given id', () => {
    render(FormField, {
      props: { label: 'Vorname', for: 'first_name' },
      slots: { default: '<input id="first_name" />' },
    });

    expect(screen.getByLabelText('Vorname').getAttribute('id')).toBe('first_name');
  });

  it('renders label markup from the label slot', () => {
    render(FormField, {
      props: { for: 'starts_at' },
      slots: { label: '<span data-label-part>Beginn</span>', default: '<input id="starts_at" />' },
    });

    expect(screen.getByLabelText('Beginn').getAttribute('id')).toBe('starts_at');
  });

  it('shows the bound error message', () => {
    render(FormField, {
      props: { label: 'E-Mail', for: 'email', error: 'Bitte geben Sie eine E-Mail-Adresse an.' },
      slots: { default: '<input id="email" />' },
    });

    expect(screen.getByText('Bitte geben Sie eine E-Mail-Adresse an.')).toBeTruthy();
  });

  it('renders the hint only when one is given', async () => {
    const { container, rerender } = render(FormField, {
      props: { label: 'Name', for: 'name' },
      slots: { default: '<input id="name" />' },
    });

    expect(container.querySelector('[data-form-field-hint]')).toBeNull();

    await rerender({ label: 'Name', for: 'name', hint: 'Wird in Listen angezeigt.' });

    expect(container.querySelector('[data-form-field-hint]')?.textContent?.trim()).toBe('Wird in Listen angezeigt.');
  });

  it('keeps a hidden label accessible', () => {
    render(FormField, {
      props: { label: 'Passwort', for: 'password', hideLabel: true },
      slots: { default: '<input id="password" />' },
    });

    expect(screen.getByLabelText('Passwort').getAttribute('id')).toBe('password');
  });

  it('gives the label an id for controls that reference it', () => {
    render(FormField, {
      props: { label: 'Farbschema', labelId: 'colour-scheme' },
      slots: { default: '<div role="group" aria-labelledby="colour-scheme" />' },
    });

    expect(screen.getByRole('group', { name: 'Farbschema' })).toBeTruthy();
  });

  it('keeps the caller attributes on the field frame', () => {
    const { container } = render(FormField, {
      props: { label: 'Name', for: 'name' },
      attrs: { 'data-placeholder-field': '' },
      slots: { default: '<input id="name" />' },
    });

    expect(container.querySelector('[data-form-field]')?.hasAttribute('data-placeholder-field')).toBe(true);
  });

  it('marks a required field visually and for screen readers', () => {
    const { container } = render(FormField, {
      props: { label: 'Titel', for: 'title', required: true },
      slots: { default: '<input id="title" />' },
    });

    const marker = container.querySelector('label [aria-hidden="true"]');

    expect(marker?.textContent?.trim()).toBe('*');
    expect(screen.getByText(t('i18n.components.form_field.required')).closest('label')).not.toBeNull();
  });

  it('renders no required marker for an optional field', () => {
    const { container } = render(FormField, {
      props: { label: 'Titel', for: 'title' },
      slots: { default: '<input id="title" />' },
    });

    expect(container.querySelector('label [aria-hidden="true"]')).toBeNull();
    expect(screen.queryByText(t('i18n.components.form_field.required'))).toBeNull();
  });

  it('renders no help trigger without a help text', () => {
    const { container } = render(FormField, {
      props: { label: 'Straße', for: 'street' },
      slots: { default: '<input id="street" />' },
    });

    expect(container.querySelector('[data-field-help-trigger]')).toBeNull();
  });

  it('names the help trigger after the label and keeps the help text for screen readers', () => {
    const { container } = render(FormField, {
      props: { label: 'Straße', for: 'street', help: 'Straße und Hausnummer.' },
      slots: { default: '<input id="street" />' },
    });

    const trigger = container.querySelector('[data-field-help-trigger="street"]');

    expect(trigger?.getAttribute('aria-label')).toBe(
      t('i18n.components.form_field.explanation_of', { value1: 'Straße' }),
    );
    expect(trigger?.getAttribute('aria-expanded')).toBe('false');
    expect(container.querySelector('#street-help')?.textContent).toBe('Straße und Hausnummer.');
  });

  it('hands the help and error ids to the control through the slot', () => {
    const { container } = render(FormField, {
      props: {
        label: 'Straße',
        for: 'street',
        help: 'Straße und Hausnummer.',
        error: 'Bitte prüfen Sie die Angabe.',
      },
      slots: {
        default:
          '<template #default="{ describedBy }"><input id="street" :aria-describedby="describedBy" /></template>',
      },
    });

    expect(container.querySelector('#street')?.getAttribute('aria-describedby')).toBe('street-help street-error');
    expect(container.querySelector('#street-error')?.textContent).toContain('Bitte prüfen Sie die Angabe.');
  });

  it('adds the hint to the description of the control', () => {
    const { container } = render(FormField, {
      props: { label: 'Formel', for: 'formula', hint: 'Felder in geschweiften Klammern.', error: 'Ungültig.' },
      slots: {
        default:
          '<template #default="{ describedBy }"><input id="formula" :aria-describedby="describedBy" /></template>',
      },
    });

    expect(container.querySelector('#formula')?.getAttribute('aria-describedby')).toBe('formula-hint formula-error');
    expect(container.querySelector('#formula-hint')?.textContent?.trim()).toBe('Felder in geschweiften Klammern.');
  });

  it('hands no description id to the control when there is nothing to describe', () => {
    const { container } = render(FormField, {
      props: { label: 'Straße', for: 'street' },
      slots: {
        default:
          '<template #default="{ describedBy }"><input id="street" :aria-describedby="describedBy" /></template>',
      },
    });

    expect(container.querySelector('#street')?.hasAttribute('aria-describedby')).toBe(false);
  });
});

describe('FormField help tooltip', () => {
  function tooltip(): Element | null {
    return document.querySelector('[data-slot="tooltip-content"]');
  }

  function renderWithHelp(): Element {
    const { container } = render(FormField, {
      props: { label: 'Straße', for: 'street', help: 'Straße und Hausnummer.' },
      slots: { default: '<input id="street" />' },
    });

    const trigger = container.querySelector('[data-field-help-trigger="street"]');

    if (trigger === null) {
      throw new Error('help trigger missing');
    }

    return trigger;
  }

  afterEach(() => {
    vi.useRealTimers();
  });

  it('opens on click and closes on a second click', async () => {
    const trigger = renderWithHelp();

    expect(tooltip()).toBeNull();

    await fireEvent.click(trigger);

    expect(tooltip()?.textContent).toContain('Straße und Hausnummer.');
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    await fireEvent.click(trigger);

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    await waitFor(() => expect(tooltip()).toBeNull());
  });

  it('closes itself three seconds after it was opened', async () => {
    vi.useFakeTimers();
    const trigger = renderWithHelp();

    await fireEvent.click(trigger);
    await vi.advanceTimersByTimeAsync(2999);

    expect(tooltip()).not.toBeNull();

    await vi.advanceTimersByTimeAsync(1);

    expect(tooltip()).toBeNull();
  });

  it('closes on escape', async () => {
    const trigger = renderWithHelp();

    await fireEvent.click(trigger);
    await fireEvent.keyDown(trigger, { key: 'Escape' });

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    await waitFor(() => expect(tooltip()).toBeNull());
  });
});
