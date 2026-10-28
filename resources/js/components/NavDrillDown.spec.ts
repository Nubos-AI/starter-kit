import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { computed, nextTick, reactive } from 'vue';
import NavDrillDown from '@/components/NavDrillDown.vue';
import type { NavNode, NavSection } from '@/types';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}));

const currentPage = reactive({ href: '' });

vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({
        currentUrl: computed(() => currentPage.href),
        isCurrentUrl: (href: string) => href === currentPage.href,
    }),
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarGroup: { template: '<div><slot /></div>' },
    SidebarGroupLabel: { template: '<div><slot /></div>' },
    SidebarMenu: { template: '<ul><slot /></ul>' },
    SidebarMenuItem: { template: '<li><slot /></li>' },
    SidebarMenuButton: {
        props: ['isActive'],
        template:
            '<button :data-active="isActive ? \'true\' : null"><slot /></button>',
    },
    SidebarSeparator: { template: '<hr />' },
}));

const mainSections: NavSection[] = [
    {
        label: '',
        items: [
            { key: 'dashboard', label: 'Dashboard', href: '/dashboard' },
            { key: 'reminders', label: 'Reminders', href: '/reminders' },
        ],
    },
    {
        label: 'Records',
        items: [
            { key: 'c', label: 'Companies', href: '/records/companies' },
            {
                key: 'setup',
                label: 'Einrichtung',
                children: [
                    {
                        key: 'templates',
                        label: 'E-Mail-Vorlagen',
                        href: '/crm/email-templates',
                    },
                ],
            },
        ],
    },
];

const configuration: NavNode[] = [
    {
        key: 'configuration',
        label: 'Konfiguration',
        children: [
            {
                key: 'automations',
                label: 'Automationen',
                href: '/engine/automations',
            },
            {
                key: 'object-types',
                label: 'Object types',
                href: '/engine/object-types',
            },
            {
                key: 'group',
                label: 'Untergruppe',
                children: [{ key: 'deep', label: 'Tief', href: '/deep' }],
            },
            {
                key: 'cfg-applications',
                label: 'Apps',
                group: true,
                children: [
                    {
                        key: 'applications',
                        label: 'Apps verwalten',
                        href: '/apps',
                    },
                    {
                        key: 'cfg-app-crm',
                        label: 'CRM',
                        children: [
                            {
                                key: 'crm-web-forms',
                                label: 'Webformulare',
                                href: '/crm/web-forms',
                            },
                        ],
                    },
                ],
            },
        ],
    },
];

function mountNav() {
    return mount(NavDrillDown, { props: { mainSections, configuration } });
}

async function clickText(
    wrapper: ReturnType<typeof mountNav>,
    text: string,
): Promise<void> {
    const button = wrapper
        .findAll('button')
        .find((candidate) => candidate.text().includes(text));

    if (!button) {
        throw new Error(`No button containing "${text}"`);
    }

    await button.trigger('click');
    await nextTick();
}

describe('NavDrillDown', () => {
    it('shows the flat main sections plus a configuration entry at root', () => {
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Reminders');
        expect(wrapper.text()).toContain('Companies');
        expect(wrapper.text()).toContain('Konfiguration');
        expect(wrapper.text()).not.toContain('Automationen');
        expect(wrapper.text()).not.toContain('Zurück');
    });

    it('drills into configuration, replacing the main area with its children', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');

        expect(wrapper.text()).toContain('Automationen');
        expect(wrapper.text()).toContain('Object types');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Dashboard');
        expect(wrapper.text()).not.toContain('Companies');
    });

    it('drills arbitrarily deep', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        await clickText(wrapper, 'Untergruppe');

        expect(wrapper.text()).toContain('Tief');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Automationen');
    });

    it('returns to the root level via the back row', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        await clickText(wrapper, 'Zurück');

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.text()).toContain('Konfiguration');
        expect(wrapper.text()).not.toContain('Automationen');
    });

    it('drills into a submenu of a main section like into configuration', async () => {
        currentPage.href = '';
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Einrichtung');
        expect(wrapper.text()).not.toContain('E-Mail-Vorlagen');

        await clickText(wrapper, 'Einrichtung');

        expect(wrapper.text()).toContain('E-Mail-Vorlagen');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Companies');
        expect(wrapper.text()).not.toContain('Dashboard');
    });

    it('starts inside the submenu of a main section that contains the current page', () => {
        currentPage.href = '/crm/email-templates';
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('E-Mail-Vorlagen');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Companies');

        currentPage.href = '';
    });

    it('scrolls the main sections while the configuration entries stay in view', () => {
        const wrapper = mountNav();
        const scrollArea = wrapper.get('[data-nav-scroll]');

        expect(scrollArea.text()).toContain('Dashboard');
        expect(scrollArea.text()).toContain('Companies');
        expect(scrollArea.text()).not.toContain('Konfiguration');
    });

    it('scrolls the entries of a level while the back row stays in view', async () => {
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        const scrollArea = wrapper.get('[data-nav-scroll]');

        expect(scrollArea.text()).toContain('Automationen');
        expect(scrollArea.text()).not.toContain('Zurück');
    });

    it('drills into the configuration of an app listed inside a group', async () => {
        currentPage.href = '';
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');

        expect(wrapper.text()).toContain('Apps verwalten');
        expect(wrapper.text()).not.toContain('Webformulare');

        await clickText(wrapper, 'CRM');

        expect(wrapper.text()).toContain('Webformulare');
        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Apps verwalten');
    });

    it('starts inside the app configuration that contains the current page', () => {
        currentPage.href = '/crm/web-forms';
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Webformulare');
        expect(wrapper.text()).not.toContain('Apps verwalten');

        currentPage.href = '';
    });

    it('follows a visit to a page that lives on another level', async () => {
        currentPage.href = '/records/companies';
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Companies');

        currentPage.href = '/crm/web-forms';
        await nextTick();

        expect(wrapper.text()).toContain('Webformulare');
        expect(wrapper.text()).not.toContain('Companies');

        currentPage.href = '/dashboard';
        await nextTick();

        expect(wrapper.text()).toContain('Companies');
        expect(wrapper.text()).not.toContain('Webformulare');

        currentPage.href = '';
    });

    it('starts inside the submenu that lists the page a sub page belongs to and marks its entry', () => {
        currentPage.href =
            '/crm/email-templates/01ARZ3NDEKTSV4RRFFQ69G5FAV/edit';
        const wrapper = mountNav();

        expect(wrapper.text()).toContain('Zurück');
        expect(wrapper.text()).not.toContain('Companies');
        expect(
            wrapper
                .findAll('[data-active="true"]')
                .map((entry) => entry.text()),
        ).toEqual(['E-Mail-Vorlagen']);

        currentPage.href = '';
    });

    it('follows a visit to a sub page that lives on another level', async () => {
        currentPage.href = '';
        const wrapper = mountNav();

        currentPage.href = '/crm/web-forms/01ARZ3NDEKTSV4RRFFQ69G5FAV/edit';
        await nextTick();

        expect(wrapper.text()).toContain('Webformulare');
        expect(wrapper.text()).not.toContain('Companies');

        currentPage.href = '';
    });

    it('marks only the entry with the longest matching address', () => {
        currentPage.href = '/records/companies/01ARZ3NDEKTSV4RRFFQ69G5FAV';
        const wrapper = mountNav();

        expect(
            wrapper
                .findAll('[data-active="true"]')
                .map((entry) => entry.text()),
        ).toEqual(['Companies']);
        expect(
            wrapper
                .findAll('[data-active="true"]')
                .map((entry) => entry.text()),
        ).not.toContain('Dashboard');

        currentPage.href = '';
    });

    it('keeps the drilled level for a page the navigation does not list', async () => {
        currentPage.href = '';
        const wrapper = mountNav();

        await clickText(wrapper, 'Konfiguration');
        currentPage.href = '/unlisted/01ARZ3NDEKTSV4RRFFQ69G5FAV';
        await nextTick();

        expect(wrapper.text()).toContain('Automationen');

        currentPage.href = '';
    });

    it('draws a separator between the entries an app splits apart and drops one with nothing to separate', () => {
        const wrapper = mount(NavDrillDown, {
            props: {
                mainSections: [
                    {
                        label: 'CRM',
                        items: [
                            { key: 'lead', label: '', separator: true },
                            { key: 'mail', label: 'Postfach', href: '/mail' },
                            { key: 'cal', label: 'Kalender', href: '/cal' },
                            { key: 'split', label: '', separator: true },
                            { key: 'twice', label: '', separator: true },
                            { key: 'c', label: 'Companies', href: '/c' },
                            { key: 'tail', label: '', separator: true },
                        ],
                    },
                ],
                configuration: [],
            },
        });

        const entries = wrapper
            .findAll('li')
            .map((entry) =>
                entry.find('[data-nav-separator]').exists()
                    ? '---'
                    : entry.text(),
            );

        expect(entries).toEqual(['Postfach', 'Kalender', '---', 'Companies']);
    });

    it('renders links for the flat leaf items', () => {
        const wrapper = mountNav();

        expect(wrapper.find('a').exists()).toBe(true);
    });
});
