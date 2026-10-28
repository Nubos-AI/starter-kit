import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MaintenanceLockBanner from '@/components/maintenance/MaintenanceLockBanner.vue';
import BANNER_SOURCE from '@/components/maintenance/MaintenanceLockBanner.vue?raw';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { MaintenanceState } from '@/types/maintenance';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {
            maintenance: null as MaintenanceState | null,
        },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
    Link: {
        props: ['href'],
        template: '<a :href="href" data-link-stub><slot /></a>',
    },
}));

const TAILWIND_PALETTE_UTILITY =
    /\b(?:bg|text|border|ring|fill|stroke|from|via|to|divide|outline|decoration|shadow|caret|placeholder)-(?:red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|slate|gray|zinc|neutral|stone)-\d{2,3}\b/;

const RAW_COLOR_LITERAL =
    /#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|oklch|lab|lch)\(/;

const activeLock: MaintenanceState = {
    tenantName: 'Nordlicht Handels GmbH',
    since: '2026-09-13T08:30:00+00:00',
    reason: 'Manuell eingeschaltet',
};

const expectedNotice =
    'Für Nordlicht Handels GmbH ist der Wartungsmodus aktiv. Schreibzugriffe, API-Schreibzugriffe, Automationen und Zeitpläne sind gesperrt, bis der Wartungsmodus unter Administration › Betrieb › Wartungsmodus beendet wird.';

const passthrough = { template: '<div><slot /></div>' };

function text(wrapper: { text: () => string }): string {
    return wrapper.text().replace(/\s+/g, ' ').trim();
}

function mountBanner() {
    return mount(MaintenanceLockBanner);
}

function mountLayout() {
    return mount(AppSidebarLayout, {
        shallow: true,
        slots: { default: '<p data-page-content>Inhalt</p>' },
        global: {
            stubs: {
                AppShell: passthrough,
                AppContent: passthrough,
                MaintenanceLockBanner: false,
                UiExtensionPoint: {
                    props: ['name'],
                    template:
                        '<div v-if="name === \'content.before\'" data-module-notice>Module notice</div>',
                },
            },
        },
    });
}

beforeEach(() => {
    pageState.props.maintenance = null;
});

describe('MaintenanceLockBanner', () => {
    it('renders nothing while no maintenance lock is active', () => {
        const wrapper = mountBanner();

        expect(wrapper.find('[data-maintenance-banner]').exists()).toBe(false);
    });

    it('announces the active maintenance mode as a status with the tenant name and the page that ends it', () => {
        pageState.props.maintenance = activeLock;

        const wrapper = mountBanner();
        const banner = wrapper.get('[data-maintenance-banner]');

        expect(banner.attributes('role')).toBe('status');
        expect(banner.get('[data-maintenance-tenant]').text()).toBe(
            activeLock.tenantName,
        );
        expect(text(banner)).toBe(expectedNotice);
        expect(text(banner)).not.toContain('Wiederherstellung');
    });

    it('names the release path in plain text without any link', () => {
        pageState.props.maintenance = activeLock;

        const wrapper = mountBanner();

        expect(wrapper.find('[data-maintenance-banner]').exists()).toBe(true);
        expect(wrapper.findAll('a')).toHaveLength(0);
        expect(wrapper.find('[href]').exists()).toBe(false);
    });

    it('keeps the lock start and the reason label off the screen', () => {
        pageState.props.maintenance = activeLock;

        const wrapper = mountBanner();
        const notice = text(wrapper.get('[data-maintenance-banner]'));

        expect(notice).not.toBe('');
        expect(notice).not.toContain(activeLock.since);
        expect(notice).not.toContain(activeLock.reason);
    });

    it('is built from design tokens only and carries no anchor or restore coupling', () => {
        expect(BANNER_SOURCE).toContain('data-maintenance-banner');
        expect(BANNER_SOURCE).not.toMatch(/\bdark:/);
        expect(BANNER_SOURCE).not.toMatch(RAW_COLOR_LITERAL);
        expect(BANNER_SOURCE).not.toMatch(TAILWIND_PALETTE_UTILITY);
        expect(BANNER_SOURCE).not.toContain('restoreRunUrl');
        expect(BANNER_SOURCE).not.toMatch(/<a[\s>]/);
        expect(BANNER_SOURCE).not.toContain('window.location');
    });
});

describe('AppSidebarLayout — maintenance banner placement', () => {
    it('renders the maintenance banner below module notices and above the page content', () => {
        pageState.props.maintenance = activeLock;

        const wrapper = mountLayout();

        const moduleNotice = wrapper.get('[data-module-notice]').element;
        const banner = wrapper.get('[data-maintenance-banner]').element;
        const pageContent = wrapper.get('[data-page-content]').element;

        expect(
            moduleNotice.compareDocumentPosition(banner) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
        expect(
            banner.compareDocumentPosition(pageContent) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
    });

    it('leaves the maintenance banner out of the layout while no lock is active', () => {
        const wrapper = mountLayout();

        expect(wrapper.find('[data-page-content]').exists()).toBe(true);
        expect(wrapper.find('[data-maintenance-banner]').exists()).toBe(false);
    });
});
