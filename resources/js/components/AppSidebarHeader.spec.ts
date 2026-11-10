import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import {
    clearPageBreadcrumbs,
    usePageBreadcrumbs,
} from '@/composables/useBreadcrumbs';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        props: {} as unknown,
        url: '/T1/dashboard',
    } as { props: unknown; url: string },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => pageState,
}));

const navigation = {
    main: [
        {
            label: '',
            items: [
                {
                    key: 'dashboard',
                    label: 'Dashboard',
                    href: '/T1/dashboard',
                },
            ],
        },
        {
            label: 'Datensätze',
            items: [
                {
                    key: 'record-companies',
                    label: 'Companies',
                    href: '/T1/records/companies',
                },
            ],
        },
    ],
    configuration: [
        {
            key: 'configuration',
            label: 'Konfiguration',
            children: [
                {
                    key: 'cfg-types',
                    label: 'Datenmodell',
                    group: true,
                    children: [
                        {
                            key: 'object-types',
                            label: 'Objekttypen',
                            href: '/T1/engine/object-types',
                        },
                    ],
                },
            ],
        },
    ],
};

const backSelector = '[data-breadcrumb-back]';

function visit(url: string): void {
    pageState.url = url;
    pageState.props = { navigation };
    clearPageBreadcrumbs();
}

function mountHeader(): ReturnType<typeof mount> {
    return mount(AppSidebarHeader, {
        global: {
            stubs: {
                SidebarTrigger: { template: '<button data-sidebar-trigger />' },
            },
        },
    });
}

beforeEach(() => {
    visit('/T1/dashboard');
});

describe('AppSidebarHeader', () => {
    it('shows neither breadcrumb nor back arrow on a top level list', () => {
        visit('/T1/dashboard');

        const wrapper = mountHeader();

        expect(wrapper.findComponent(Breadcrumbs).exists()).toBe(false);
        expect(wrapper.find(backSelector).exists()).toBe(false);
        expect(wrapper.find('[data-sidebar-trigger]').exists()).toBe(true);
    });

    it('renders the navigation path of a configuration list without a back arrow', () => {
        visit('/T1/engine/object-types');

        const wrapper = mountHeader();

        expect(wrapper.findComponent(Breadcrumbs).props('breadcrumbs')).toEqual(
            [
                { title: 'Konfiguration' },
                { title: 'Datenmodell' },
                { title: 'Objekttypen', href: '/T1/engine/object-types' },
            ],
        );
        expect(wrapper.find(backSelector).exists()).toBe(false);
    });

    it('renders the entity behind the list and links the back arrow one level up', () => {
        visit('/T1/records/companies/01ABC/edit');
        usePageBreadcrumbs(() => [{ title: 'Acme GmbH' }]);

        const wrapper = mountHeader();

        expect(
            wrapper
                .findComponent(Breadcrumbs)
                .props('breadcrumbs')
                .map((item: { title: string }) => item.title),
        ).toEqual(['Datensätze', 'Companies', 'Acme GmbH']);
        expect(wrapper.find(backSelector).attributes('href')).toBe(
            '/T1/records/companies',
        );
    });
});
