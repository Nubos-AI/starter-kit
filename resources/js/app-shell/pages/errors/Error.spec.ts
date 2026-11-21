import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { t } from '@/shared/testing/i18n';
import type { MaintenanceState } from '@/shared/types/maintenance';
import ErrorPage from './Error.vue';

const { pageState } = vi.hoisted(() => ({
  pageState: {
    props: {
      maintenance: null as MaintenanceState | null,
    },
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  Head: (await import('@/shared/testing/inertia')).headStub,
  Link: (await import('@/shared/testing/inertia')).linkStub,
  usePage: () => pageState,
}));

const activeLock: MaintenanceState = {
  tenantName: 'Nordlicht Handels GmbH',
  since: '2026-09-13T08:30:00+00:00',
  reason: 'Manuell eingeschaltet',
};

const render = (status: number) =>
  mount(ErrorPage, {
    props: { status },
    global: {
      stubs: {
        AppLogoIcon: { template: '<svg />' },
      },
    },
  });

beforeEach(() => {
  pageState.props.maintenance = null;
});

describe('the error page', () => {
  it.each([
    [403, 'access_denied'],
    [404, 'page_not_found'],
    [419, 'session_expired'],
    [429, 'too_many_requests'],
    [500, 'an_error_occurred'],
    [503, 'temporarily_unavailable'],
  ])('names the %i case with its own heading', (status, heading) => {
    const wrapper = render(status);

    expect(wrapper.text()).toContain(t(`i18n.pages.errors.error.${heading}`));
    expect(wrapper.text()).toContain(`Fehler ${status}`);
  });

  it('describes the 503 case neutrally and addresses the user formally', () => {
    const text = render(503).text();

    expect(text).toContain('Fehler 503');
    expect(text).toMatch(/\bSie\b/);
    expect(text).not.toContain('wiederhergestellt');
    expect(text).not.toContain('Es ist ein Fehler aufgetreten');
  });

  it('names the active maintenance mode of the tenant on the 503 page while a lock is active', () => {
    pageState.props.maintenance = activeLock;

    const wrapper = render(503);
    const banner = wrapper.get('[data-maintenance-banner]');

    expect(banner.get('[data-maintenance-tenant]').text()).toBe(activeLock.tenantName);
    expect(banner.text()).toContain('Wartungsmodus');
    expect(banner.text()).toContain('gesperrt');
    expect(wrapper.text()).toContain('Vorübergehend nicht verfügbar');
  });

  it('shows no maintenance notice on the 503 page without an active lock', () => {
    const wrapper = render(503);

    expect(wrapper.find('[data-maintenance-banner]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Vorübergehend nicht verfügbar');
  });

  it('shows no maintenance notice on any other error page even while a lock is active', () => {
    pageState.props.maintenance = activeLock;

    const wrapper = render(404);

    expect(wrapper.find('[data-maintenance-banner]').exists()).toBe(false);
  });

  it('falls back to the generic message for an unmapped status', () => {
    const wrapper = render(405);

    expect(wrapper.text()).toContain(t('i18n.pages.errors.error.an_error_occurred'));
    expect(wrapper.text()).toContain('Fehler 405');
  });
});
