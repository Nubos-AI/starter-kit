import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TeamSwitcher from '@/components/teams/TeamSwitcher.vue';
import type { TeamSummary } from '@/types/teams';

const { visitMock, pageState } = vi.hoisted(() => ({
    visitMock: vi.fn(),
    pageState: {
        props: {
            currentTeam: null as TeamSummary | null,
            availableTeams: [] as TeamSummary[],
        },
        url: '/dashboard',
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: visitMock },
    usePage: () => pageState,
}));

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    DropdownMenu: passthrough,
    DropdownMenuTrigger: passthrough,
    DropdownMenuContent: passthrough,
    DropdownMenuLabel: passthrough,
    DropdownMenuSeparator: passthrough,
    DropdownMenuItem: {
        props: ['disabled'],
        emits: ['select'],
        template:
            '<button data-team-option :disabled="disabled" @click="$emit(\'select\')"><slot /></button>',
    },
};

const vertrieb: TeamSummary = {
    id: '01team000000000000000000ve',
    name: 'Vertrieb',
    slug: 'vertrieb',
};
const support: TeamSummary = {
    id: '01team000000000000000000su',
    name: 'Support',
};

function mountSwitcher() {
    return mount(TeamSwitcher, { global: { stubs } });
}

beforeEach(() => {
    visitMock.mockReset();
    pageState.props.currentTeam = vertrieb;
    pageState.props.availableTeams = [vertrieb, support];
    pageState.url = '/01team000000000000000000ve/engine/teams';
});

describe('TeamSwitcher', () => {
    it('names the active team on the trigger', () => {
        expect(mountSwitcher().text()).toContain('Vertrieb');
    });

    it('offers every team the user belongs to', () => {
        const options = mountSwitcher().findAll('[data-team-option]');

        expect(options.map((option) => option.text())).toEqual([
            'Vertrieb',
            'Support',
        ]);
    });

    it('swaps the leading segment of the current url when another team is picked', async () => {
        const wrapper = mountSwitcher();

        await wrapper.findAll('[data-team-option]')[1].trigger('click');

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock.mock.calls[0][0]).toBe(
            '/01team000000000000000000su/engine/teams',
        );
    });

    it('opens the selected team dashboard from a team-free page', async () => {
        pageState.props.currentTeam = null;
        pageState.url = '/settings/profile';

        const wrapper = mountSwitcher();

        await wrapper.findAll('[data-team-option]')[0].trigger('click');

        expect(visitMock.mock.calls[0][0]).toBe(
            '/01team000000000000000000ve/dashboard',
        );
    });

    it('replaces a real team slug and preserves the query', async () => {
        pageState.url = '/vertrieb/engine/teams?sort=name';
        const wrapper = mountSwitcher();
        await wrapper.findAll('[data-team-option]')[1].trigger('click');
        expect(visitMock).toHaveBeenCalledWith(
            '/01team000000000000000000su/engine/teams?sort=name',
        );
    });

    it('says so when the user belongs to no team at all', () => {
        pageState.props.currentTeam = null;
        pageState.props.availableTeams = [];

        const wrapper = mountSwitcher();

        expect(wrapper.text()).toContain('Kein Team');
        expect(wrapper.findAll('[data-team-option]')).toHaveLength(0);
    });

    it('marks the active team as not pickable again', () => {
        const options = mountSwitcher().findAll('[data-team-option]');

        expect(options[0].attributes('disabled')).toBe('');
        expect(options[1].attributes('disabled')).toBeUndefined();
    });
});
