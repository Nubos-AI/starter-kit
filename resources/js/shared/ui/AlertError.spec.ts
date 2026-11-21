import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import { t } from '@/shared/testing/i18n';
import AlertError from '@/shared/ui/AlertError.vue';

describe('AlertError', () => {
  it('lists every distinct error once', () => {
    render(AlertError, { props: { errors: ['A fehlt.', 'A fehlt.', 'B fehlt.'] } });

    expect(screen.getAllByRole('listitem').map((item) => item.textContent?.trim())).toEqual(['A fehlt.', 'B fehlt.']);
  });

  it('renders a single message as a sentence instead of a list', () => {
    render(AlertError, { props: { title: 'Import fehlgeschlagen', message: 'Die Datei ist leer.' } });

    expect(screen.getByText('Import fehlgeschlagen')).toBeTruthy();
    expect(screen.getByText('Die Datei ist leer.')).toBeTruthy();
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('renders the default slot as the description', () => {
    render(AlertError, {
      props: { title: 'Verbindung getrennt' },
      slots: { default: '<button data-reconnect>Erneut verbinden</button>' },
    });

    expect(screen.getByText('Erneut verbinden')).toBeTruthy();
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('titles a list without its own title with the default heading', () => {
    render(AlertError, { props: { errors: ['Kaputt.'] } });

    expect(screen.getByText(t('i18n.components.alert_error.something_went_wrong'))).toBeTruthy();
  });

  it('renders a single message without a heading when no title is given', () => {
    const { container } = render(AlertError, { props: { message: 'Bereits beendet.' }, attrs: { 'data-failure': '' } });

    expect(screen.queryByText(t('i18n.components.alert_error.something_went_wrong'))).toBeNull();
    expect(container.querySelector('[data-failure]')?.textContent?.trim()).toBe('Bereits beendet.');
  });
});
