import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import {
    clearPageBreadcrumbs,
    provideBreadcrumbTail,
    useBreadcrumbTrail,
    usePageBreadcrumbs,
} from '@/composables/useBreadcrumbs';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        component: 'dashboard/Index',
        props: {} as unknown,
        url: '/dashboard',
    } as { component: string; props: unknown; url: string },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

const navigation = {
    main: [
        {
            label: '',
            items: [
                { key: 'dashboard', label: 'Dashboard', href: '/T1/dashboard' },
                {
                    key: 'reminders',
                    label: 'Erinnerungen',
                    href: '/T1/reminders',
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
                {
                    key: 'record-company-groups',
                    label: 'Company groups',
                    href: '/T1/records/company-groups',
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
                    key: 'cfg-analytics',
                    label: 'Analyse',
                    group: true,
                    children: [
                        {
                            key: 'reports',
                            label: 'Auswertungen',
                            href: '/T1/reports',
                        },
                        {
                            key: 'dashboards',
                            label: 'Dashboards',
                            href: '/T1/dashboards',
                        },
                        { key: 'goals', label: 'Ziele', href: '/T1/goals' },
                    ],
                },
                {
                    key: 'cfg-automation',
                    label: 'Automatisierung',
                    group: true,
                    children: [
                        {
                            key: 'automations',
                            label: 'Automatisierungen',
                            href: '/T1/engine/automations',
                        },
                        {
                            key: 'automation-templates',
                            label: 'Automatisierungsvorlagen',
                            href: '/T1/engine/automations/templates',
                        },
                    ],
                },
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
        {
            key: 'administration',
            label: 'Administration',
            children: [
                {
                    key: 'adm-access',
                    label: 'Zugriff',
                    group: true,
                    children: [
                        {
                            key: 'teams',
                            label: 'Teams',
                            href: '/T1/engine/teams',
                        },
                    ],
                },
            ],
        },
    ],
};

function visit(url: string, component: string = 'pages/Index'): void {
    pageState.component = component;
    pageState.url = url;
    pageState.props = { navigation };
    clearPageBreadcrumbs();
}

beforeEach(() => {
    visit('/T1/dashboard');
});

describe('useBreadcrumbTrail — navigation path', () => {
    it('builds the configuration path with the groups as plain text', () => {
        visit('/T1/engine/object-types');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Konfiguration' },
            { title: 'Datenmodell' },
            { title: 'Objekttypen', href: '/T1/engine/object-types' },
        ]);
    });

    it('keeps a leaf of an unlabelled section at a single entry so no breadcrumb is shown', () => {
        visit('/T1/dashboard');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Dashboard', href: '/T1/dashboard' },
        ]);
    });

    it('prefixes a leaf of a labelled main section with that section label', () => {
        visit('/T1/records/companies');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Datensätze' },
            { title: 'Companies', href: '/T1/records/companies' },
        ]);
    });

    it('builds the reports trail from the Analyse group of the configuration', () => {
        visit('/T1/reports');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Konfiguration' },
            { title: 'Analyse' },
            { title: 'Auswertungen', href: '/T1/reports' },
        ]);
    });

    it('builds the teams trail from the Zugriff group of Administration', () => {
        visit('/T1/engine/teams');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Administration' },
            { title: 'Zugriff' },
            { title: 'Teams', href: '/T1/engine/teams' },
        ]);
    });

    it('renders the section label unlinked so only the leaf carries an href', () => {
        visit('/T1/goals');

        const trail = useBreadcrumbTrail();

        expect(trail.items.value.at(0)).not.toHaveProperty('href');
        expect(trail.backHref.value).toBeNull();
    });

    it('matches a sub page onto its list entry', () => {
        visit('/T1/engine/object-types/companies/edit');

        expect(
            useBreadcrumbTrail().items.value.map((item) => item.title),
        ).toEqual(['Konfiguration', 'Datenmodell', 'Objekttypen']);
    });

    it('prefers the longest matching entry', () => {
        visit('/T1/engine/automations/templates');

        const titles = useBreadcrumbTrail().items.value.map(
            (item) => item.title,
        );

        expect(titles).toContain('Automatisierungsvorlagen');
        expect(titles).not.toContain('Automatisierungen');
    });

    it('never matches an entry on a partial path segment', () => {
        visit('/T1/records/company-groups');

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Datensätze' },
            { title: 'Company groups', href: '/T1/records/company-groups' },
        ]);
    });

    it('ignores the query string of the current url', () => {
        visit('/T1/engine/object-types?page=2');

        expect(
            useBreadcrumbTrail().items.value.map((item) => item.title),
        ).toEqual(['Konfiguration', 'Datenmodell', 'Objekttypen']);
    });

    it('stays empty when no entry matches', () => {
        visit('/T1/settings/profile');

        expect(useBreadcrumbTrail().items.value).toEqual([]);
    });
});

describe('useBreadcrumbTrail — page tail', () => {
    it('appends the entries the page registered', () => {
        visit('/T1/records/companies/01ABC/edit');
        usePageBreadcrumbs(() => [{ title: 'Acme GmbH' }]);

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Datensätze' },
            { title: 'Companies', href: '/T1/records/companies' },
            { title: 'Acme GmbH' },
        ]);
    });

    it('never repeats the list entry a page carries in its own tail', () => {
        visit('/T1/records/companies/create');
        usePageBreadcrumbs(() => [
            { title: 'Companies', href: '/T1/records/companies' },
            { title: 'Companies anlegen' },
        ]);

        expect(useBreadcrumbTrail().items.value).toEqual([
            { title: 'Datensätze' },
            { title: 'Companies', href: '/T1/records/companies' },
            { title: 'Companies anlegen' },
        ]);
    });

    it('carries the list entry when the url alone does not reveal it', () => {
        visit('/T1/records/01ABC');
        usePageBreadcrumbs(() => [
            { title: 'Companies', href: '/T1/records/companies' },
            { title: 'Acme GmbH' },
        ]);

        const trail = useBreadcrumbTrail();

        expect(trail.items.value).toHaveLength(2);
        expect(trail.backHref.value).toBe('/T1/records/companies');
    });

    it('drops a tail that belongs to another page', () => {
        pageState.component = 'records/Form';
        pageState.url = '/T1/records/companies/01ABC/edit';
        pageState.props = { navigation };
        usePageBreadcrumbs(() => [{ title: 'Acme GmbH' }]);

        pageState.component = 'objectTypes/Index';
        pageState.url = '/T1/engine/object-types';

        expect(
            useBreadcrumbTrail().items.value.map((item) => item.title),
        ).toEqual(['Konfiguration', 'Datenmodell', 'Objekttypen']);
    });

    it('keeps the tail when the same page component is reused for a new url', () => {
        visit('/T1/engine/automations/create', 'automations/FlowCanvas');

        const title = ref('Automation anlegen');
        usePageBreadcrumbs(() => [{ title: title.value }]);

        pageState.url = '/T1/engine/automations/01AUTO/edit';
        title.value = 'Testautomation';

        const trail = useBreadcrumbTrail();

        expect(trail.items.value.map((item) => item.title)).toEqual([
            'Konfiguration',
            'Automatisierung',
            'Automatisierungen',
            'Testautomation',
        ]);
        expect(trail.backHref.value).toBe('/T1/engine/automations');
    });

    it('follows a reactive tail', () => {
        visit('/T1/records/companies/01ABC/edit');

        const name = ref('Acme GmbH');
        usePageBreadcrumbs(() => [{ title: name.value }]);

        const trail = useBreadcrumbTrail();

        expect(trail.items.value.at(-1)).toEqual({ title: 'Acme GmbH' });

        name.value = 'Acme AG';

        expect(trail.items.value.at(-1)).toEqual({ title: 'Acme AG' });
    });
});

describe('useBreadcrumbTrail — back target', () => {
    it('points one level up to the nearest linked entry', () => {
        visit('/T1/engine/object-types/companies/edit');
        usePageBreadcrumbs(() => [{ title: 'Firma' }]);

        expect(useBreadcrumbTrail().backHref.value).toBe(
            '/T1/engine/object-types',
        );
    });

    it('points to the detail page when the tail carries one', () => {
        visit('/T1/engine/teams/01TEAM/access-rules');
        usePageBreadcrumbs(() => [
            { title: 'Vertrieb', href: '/T1/engine/teams/01TEAM/edit' },
            { title: 'Zugriffsregeln' },
        ]);

        const trail = useBreadcrumbTrail();

        expect(trail.backHref.value).toBe('/T1/engine/teams/01TEAM/edit');
        expect(trail.items.value.map((item) => item.title)).toEqual([
            'Administration',
            'Zugriff',
            'Teams',
            'Vertrieb',
            'Zugriffsregeln',
        ]);
    });

    it('offers no target on a list page', () => {
        visit('/T1/engine/object-types');

        expect(useBreadcrumbTrail().backHref.value).toBeNull();
    });
});

describe('useBreadcrumbs — the page tail never crosses a request boundary', () => {
    it('keeps two layout instances apart so one page cannot read the other tail', () => {
        const Header = defineComponent({
            setup() {
                const { items } = useBreadcrumbTrail();

                return () =>
                    h('span', {}, items.value.map((i) => i.title).join('|'));
            },
        });

        const Page = defineComponent({
            props: { title: { type: String, required: true } },
            setup(pageProps) {
                usePageBreadcrumbs(() => [{ title: pageProps.title }]);

                return () => h('div');
            },
        });

        const Layout = defineComponent({
            props: { title: { type: String, required: true } },
            setup(layoutProps) {
                provideBreadcrumbTail();

                return () => [h(Header), h(Page, { title: layoutProps.title })];
            },
        });

        const first = mount(Layout, { props: { title: 'Datensatz von A' } });
        const second = mount(Layout, { props: { title: 'Datensatz von B' } });

        expect(first.text()).not.toContain('Datensatz von B');
        expect(second.text()).not.toContain('Datensatz von A');
    });

    it('lets the page tail reach the header inside the same layout', async () => {
        const Header = defineComponent({
            setup() {
                const { items } = useBreadcrumbTrail();

                return () =>
                    h('span', {}, items.value.map((i) => i.title).join('|'));
            },
        });

        const Page = defineComponent({
            setup() {
                usePageBreadcrumbs(() => [{ title: 'Eigener Titel' }]);

                return () => h('div');
            },
        });

        const Layout = defineComponent({
            setup() {
                provideBreadcrumbTail();

                return () => [h(Header), h(Page)];
            },
        });

        const wrapper = mount(Layout);
        await nextTick();

        expect(wrapper.text()).toContain('Eigener Titel');
    });
});
