import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ErrorPage from '@/pages/errors/Error.vue';
import type { MaintenanceState } from '@/types/maintenance';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {
            maintenance: null as MaintenanceState | null,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        props: ['title'],
        template: '<head-stub :title="title"><slot /></head-stub>',
    },
    Link: { props: ['href'], template: '<a><slot /></a>' },
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
        [403, 'Kein Zugriff'],
        [404, 'Seite nicht gefunden'],
        [419, 'Sitzung abgelaufen'],
        [429, 'Zu viele Anfragen'],
        [500, 'Es ist ein Fehler aufgetreten'],
        [503, 'Vorübergehend nicht verfügbar'],
    ])('names the %i case in german', (status, heading) => {
        const wrapper = render(status);

        expect(wrapper.text()).toContain(heading);
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

        expect(banner.get('[data-maintenance-tenant]').text()).toBe(
            activeLock.tenantName,
        );
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

    it('falls back to a german message for an unmapped status', () => {
        const wrapper = render(405);

        expect(wrapper.text()).toContain('Es ist ein Fehler aufgetreten');
        expect(wrapper.text()).toContain('Fehler 405');
    });

    it('always offers a way back to the application', () => {
        const wrapper = render(404);

        expect(wrapper.text()).toContain('Zur Startseite');
    });

    it('never renders technical exception details', () => {
        const wrapper = render(500);
        const text = wrapper.text();

        expect(text).not.toContain('Exception');
        expect(text).not.toContain('/var/www');
        expect(text).not.toContain('#0');
    });
});
