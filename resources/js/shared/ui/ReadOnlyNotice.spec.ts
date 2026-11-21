import { render, screen } from '@testing-library/vue';
import { describe, expect, it } from 'vitest';
import { t } from '@/shared/testing/i18n';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';

describe('ReadOnlyNotice', () => {
  it('names the read-only state and the reason the user cannot change anything', () => {
    render(ReadOnlyNotice, { props: { reason: 'Ihnen fehlt die Berechtigung zum Bearbeiten.' } });

    expect(screen.getByText(t('i18n.components.read_only_notice.title'))).toBeTruthy();
    expect(screen.getByText('Ihnen fehlt die Berechtigung zum Bearbeiten.')).toBeTruthy();
  });

  it('replaces the default title when one is given', () => {
    render(ReadOnlyNotice, { props: { reason: 'Systemobjekte bleiben unverändert.', title: 'Systemobjekt' } });

    expect(screen.getByText('Systemobjekt')).toBeTruthy();
    expect(screen.queryByText(t('i18n.components.read_only_notice.title'))).toBeNull();
  });

  it('is a neutral status notice, not an error', () => {
    const { container } = render(ReadOnlyNotice, { props: { reason: 'Nur Ansicht.' } });

    const notice = container.querySelector('[data-slot="alert"]');

    expect(notice?.getAttribute('role')).toBe('status');
    expect(notice?.querySelector('svg')).not.toBeNull();
  });

  it('passes data attributes through to the notice', () => {
    const { container } = render(ReadOnlyNotice, {
      props: { reason: 'Nur Ansicht.' },
      attrs: { 'data-policy-read-only': '' },
    });

    expect(container.querySelector('[data-slot="alert"][data-policy-read-only]')).not.toBeNull();
  });
});
