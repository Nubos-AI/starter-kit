import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import InputError from '@/shared/ui/InputError.vue';

describe('InputError', () => {
  it('announces the message as an alert when asked to', () => {
    render(InputError, { props: { message: 'Speichern fehlgeschlagen.', role: 'alert' } });

    expect(screen.getByRole('alert').textContent?.trim()).toBe('Speichern fehlgeschlagen.');
  });

  it('carries no alert role by default', () => {
    render(InputError, { props: { message: 'Pflichtfeld.' } });

    expect(screen.queryByRole('alert')).toBeNull();
  });
});
