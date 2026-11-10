import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import TeamAccessRulesController from '@/actions/App/Http/Controllers/Teams/TeamAccessRulesController';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import Layout from '@/layouts/team/Layout.vue';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

interface MockPage {
    url: string;
    props: {
        auth: { user: null; can: Record<string, boolean>; authority: null };
        team: { id: string; name: string } | null;
        mode?: 'create' | 'edit';
    };
}

const { mocks } = vi.hoisted(() => ({
    mocks: {} as { page: MockPage },
}));

vi.mock('@inertiajs/vue3', () => {
    mocks.page = reactive<MockPage>({
        url: '/',
        props: {
            auth: { user: null, can: {}, authority: null },
            team: null,
        },
    });

    return {
        Link: { props: ['href'], template: '<a :href="href.url"><slot /></a>' },
        usePage: () => mocks.page,
    };
});

const TEAM = { team: 'team-1' };

const SECTION_URLS = [
    TeamsController.edit(TEAM).url,
    TeamsController.members(TEAM).url,
    TeamsController.roles(TEAM).url,
    TeamsController.deniedPermissions(TEAM).url,
    TeamAccessRulesController.index(TEAM).url,
];

const SECTION_TITLES = [
    'Details',
    'Mitglieder',
    'Rollen',
    'Entzogene Rechte',
    'Datensatzzugriff',
];

function mountLayout() {
    return mount(Layout, { slots: { default: '<p>Abschnitt</p>' } });
}

beforeEach(() => {
    mocks.page.url = SECTION_URLS[0];
    mocks.page.props.team = { id: 'team-1', name: 'Nubos Company' };
    mocks.page.props.mode = undefined;
    mocks.page.props.auth.can = { 'teams.manage': true };
});

describe('team/Layout', () => {
    it('lists every section of the team as its own page in a fixed order', () => {
        const links = mountLayout().findAll('[data-team-sections] a');

        expect(links.map((link) => link.text())).toEqual(SECTION_TITLES);
        expect(links.map((link) => link.attributes('href'))).toEqual(
            SECTION_URLS,
        );
    });

    it('marks exactly the section the current url points at', () => {
        mocks.page.url = SECTION_URLS[3];

        const active = mountLayout().findAll('[data-section-active]');

        expect(active).toHaveLength(1);
        expect(active[0].text()).toBe('Entzogene Rechte');
    });

    it('names the team above the sections', () => {
        expect(mountLayout().text()).toContain('Nubos Company');
    });

    it('disables the record access section with a reason for someone who may not manage access rules', () => {
        mocks.page.props.auth.can = {};

        const disabled = mountLayout().get('[data-section-disabled]');

        expect(disabled.text()).toBe('Datensatzzugriff');
        expect(disabled.attributes('title')).toBe(
            'Nur mit dem Recht, Teams zu verwalten.',
        );
    });

    it('leaves only the details section reachable while creating', () => {
        mocks.page.url = TeamsController.create().url;
        mocks.page.props.team = null;
        mocks.page.props.mode = 'create';

        const wrapper = mountLayout();

        expect(
            wrapper
                .findAll('[data-team-sections] a')
                .map((link) => link.text()),
        ).toEqual(['Details']);
        expect(
            wrapper
                .findAll('[data-team-sections] [data-section-disabled]')
                .map((section) => section.text()),
        ).toEqual(SECTION_TITLES.slice(1));
        expect(wrapper.text()).toContain('Neues Team');
    });
});
