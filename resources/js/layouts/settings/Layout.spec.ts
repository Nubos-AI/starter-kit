import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import Layout from '@/layouts/settings/Layout.vue';

interface MockPage {
    url: string;
    props: {
        auth: { user: null; can: Record<string, boolean>; authority: null };
    };
}

const { mocks } = vi.hoisted(() => ({
    mocks: {} as { page: MockPage },
}));

vi.mock('@inertiajs/vue3', () => {
    mocks.page = reactive<MockPage>({
        url: '/settings/profile',
        props: { auth: { user: null, can: {}, authority: null } },
    });

    return {
        Link: { props: ['href'], template: '<a :href="href.url"><slot /></a>' },
        usePage: () => mocks.page,
    };
});

function mountLayout() {
    return mount(Layout, { slots: { default: '<p>Abschnitt</p>' } });
}

beforeEach(() => {
    mocks.page.url = '/settings/profile';
});

describe('settings/Layout', () => {
    it('lists the personal settings sections through the shared section navigation', () => {
        const links = mountLayout().findAll(
            '[data-settings-sections] [data-section]',
        );

        expect(links.map((link) => link.text())).toEqual([
            'Profil',
            'Sicherheit',
            'Erscheinungsbild',
            'Benachrichtigungen',
            'Abwesenheit',
            'API-Token',
        ]);
        expect(links.map((link) => link.attributes('href'))).toEqual([
            '/settings/profile',
            '/settings/security',
            '/settings/appearance',
            '/settings/notifications',
            '/settings/absences',
            '/settings/api-tokens',
        ]);
    });

    it('marks exactly the section the current url points at', () => {
        mocks.page.url = '/settings/absences';

        const active = mountLayout().findAll('[data-section-active]');

        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Abwesenheit');
    });

    it('renders the settings page next to the navigation', () => {
        expect(mountLayout().text()).toContain('Abschnitt');
    });
});
