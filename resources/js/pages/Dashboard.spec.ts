import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import Dashboard from '@/pages/Dashboard.vue';
import PAGE_SOURCE from '@/pages/Dashboard.vue?raw';
import { selectStubs } from '@/tests/selectStubs';
import type { DashboardRow, DashboardWidgetMeta } from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';
import type { SelectOption } from '@/types/ui';
import { setUrlDefaults } from '@/wayfinder';

const inertia = vi.hoisted(() => ({ visit: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        props: ['title'],
        template: '<head-stub :title="title"><slot /></head-stub>',
    },
    Link: { props: ['href', 'only'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: inertia.visit,
        put: inertia.put,
    },
    usePage: () => ({
        url: '/nubos/dashboard',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const ACTIVE_TEAM = 'nubos';

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const SHARED_ID = '01DASHBOARD00000000000002';

type Wrapper = VueWrapper;

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

const GridStub = {
    name: 'DashboardGridStub',
    props: [
        'dashboardId',
        'widgets',
        'canUpdate',
        'updateReason',
        'reportOptions',
        'objectTypeOptions',
        'fieldsByType',
        'linkedFieldsByType',
        'segmentsByType',
    ],
    template: '<div data-dashboard-grid-stub />',
};

function dashboard(overrides: Partial<DashboardRow> = {}): DashboardRow {
    return {
        id: DASHBOARD_ID,
        name: 'Vertriebsübersicht',
        description: 'Kennzahlen des laufenden Quartals',
        owner_id: '01USER00000000000000001A',
        is_owner: true,
        is_tenant_wide: false,
        is_default: false,
        can_update: true,
        can_delete: true,
        can_share: true,
        update_reason: null,
        delete_reason: null,
        share_reason: null,
        has_definer_widget: false,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function widgets(): DashboardWidgetMeta[] {
    return [
        {
            id: 'w1',
            dashboard_id: DASHBOARD_ID,
            report_id: '01REPORT0000000000000001',
            title: 'Erste Kachel',
            chart_type: 'metric',
            goal_id: null,
            definition: null,
            position: 0,
            column_span: 1,
            updated_at: '2026-08-10T11:00:00+00:00',
        },
    ];
}

function mountPage(
    props: {
        dashboard?: DashboardRow | null;
        widgets?: DashboardWidgetMeta[];
        canCreate?: boolean;
        options?: { own: SelectOption[]; shared: SelectOption[] };
        defaultDashboardId?: string | null;
    } = {},
): Wrapper {
    return mount(Dashboard, {
        props: {
            dashboard:
                props.dashboard === undefined ? dashboard() : props.dashboard,
            widgets: props.widgets ?? widgets(),
            canCreate: props.canCreate ?? true,
            options: props.options ?? {
                own: [{ value: DASHBOARD_ID, label: 'Vertriebsübersicht' }],
                shared: [{ value: SHARED_ID, label: 'Geteiltes Board' }],
            },
            defaultDashboardId:
                props.defaultDashboardId === undefined
                    ? null
                    : props.defaultDashboardId,
        },
        global: {
            stubs: {
                DashboardGrid: GridStub,
                ...selectStubs,
            },
        },
    });
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    inertia.visit.mockReset();
    inertia.put.mockReset();
});

describe('Dashboard — what the server resolved reaches the grid', () => {
    it('hands the resolved dashboard down to the reused grid', () => {
        const grid = mountPage().findComponent(GridStub);

        expect(grid.exists()).toBe(true);
        expect(grid.props('dashboardId')).toBe(DASHBOARD_ID);
        expect(grid.props('widgets')).toEqual(widgets());
    });

    it('mounts the grid write protected with a reason that does not blame the viewer', () => {
        const grid = mountPage().findComponent(GridStub);
        const reason = grid.props('updateReason');

        expect(grid.props('canUpdate')).toBe(false);
        expect(typeof reason).toBe('string');
        expect((reason as string).trim().length).toBeGreaterThan(0);
        expect(reason).not.toBe(resolveDashboardActionRefusal('not_owner'));
        expect(reason).not.toBe(resolveDashboardActionRefusal('not_visible'));
    });

    it('serves the editor payload the grid contract demands as empty literals', () => {
        const grid = mountPage().findComponent(GridStub);

        expect(grid.props('reportOptions')).toEqual([]);
        expect(grid.props('objectTypeOptions')).toEqual([]);
        expect(grid.props('fieldsByType')).toEqual({});
        expect(grid.props('linkedFieldsByType')).toEqual({});
        expect(grid.props('segmentsByType')).toEqual({});
    });
});

describe('Dashboard — the two page states are disjoint', () => {
    it('shows the page empty state and no grid when nothing was resolved', () => {
        const wrapper = mountPage({ dashboard: null, widgets: [] });

        expect(wrapper.findComponent(GridStub).exists()).toBe(false);
        expect(wrapper.find('[data-default-dashboard-empty]').exists()).toBe(
            true,
        );
    });

    it('shows the grid and no page empty state when a dashboard was resolved', () => {
        const wrapper = mountPage();

        expect(wrapper.findComponent(GridStub).exists()).toBe(true);
        expect(wrapper.find('[data-default-dashboard-empty]').exists()).toBe(
            false,
        );
    });

    it('keeps the switch on the dashboard alone when the resolved board has no tiles', () => {
        const wrapper = mountPage({ widgets: [] });
        const grid = wrapper.findComponent(GridStub);

        expect(grid.exists()).toBe(true);
        expect(grid.props('widgets')).toEqual([]);
        expect(wrapper.find('[data-default-dashboard-empty]').exists()).toBe(
            false,
        );
    });
});

describe('Dashboard — the empty state invites to create', () => {
    it('offers the create call to action pointing at the dashboard form', () => {
        const wrapper = mountPage({
            dashboard: null,
            widgets: [],
            canCreate: true,
        });

        expect(
            wrapper.get('[data-default-dashboard-create]').attributes('href'),
        ).toBe(DashboardsController.create.url());
    });

    it('drops the create call to action but keeps the explanation without the right', () => {
        const wrapper = mountPage({
            dashboard: null,
            widgets: [],
            canCreate: false,
        });

        expect(wrapper.find('[data-default-dashboard-create]').exists()).toBe(
            false,
        );
        expect(
            wrapper.get('[data-default-dashboard-empty]').text().trim().length,
        ).toBeGreaterThan(0);
    });
});

describe('Dashboard — the way to the detail page', () => {
    it('links to the detail page of the resolved dashboard', () => {
        expect(
            mountPage().get('[data-default-dashboard-open]').attributes('href'),
        ).toBe(DashboardsController.show.url({ dashboard: DASHBOARD_ID }));
    });

    it('carries an icon and an accessible name instead of visible words', () => {
        const open = mountPage().get('[data-default-dashboard-open]');

        expect(open.text()).toBe('');
        expect(open.find('svg').exists()).toBe(true);
        expect(
            (open.attributes('aria-label') ?? '').trim().length,
        ).toBeGreaterThan(0);
    });

    it('stays away while the viewer may not edit the dashboard', () => {
        const wrapper = mountPage({
            dashboard: dashboard({ can_update: false }),
        });

        expect(wrapper.find('[data-default-dashboard-open]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-default-dashboard-make-default]').exists(),
        ).toBe(true);
    });

    it('offers no detail link when nothing was resolved', () => {
        expect(
            mountPage({ dashboard: null, widgets: [] })
                .find('[data-default-dashboard-open]')
                .exists(),
        ).toBe(false);
    });
});

describe('Dashboard — the page shell', () => {
    it('titles the document and heads the page with a title and a description', () => {
        const wrapper = mountPage();
        const title = wrapper.get('head-stub').attributes('title');

        expect(typeof title).toBe('string');
        expect((title as string).trim().length).toBeGreaterThan(0);
        expect(wrapper.get('header h2').text().trim().length).toBeGreaterThan(
            0,
        );
        expect(wrapper.get('header p').text().trim().length).toBeGreaterThan(0);
    });
});

describe('Dashboard — the placeholder boilerplate is gone', () => {
    it('no longer imports the placeholder pattern and reuses the grid instead', () => {
        expect(PAGE_SOURCE).not.toContain('PlaceholderPattern');
        expect(PAGE_SOURCE).toContain('DashboardGrid');
    });

    it('no longer carries the deviating page root of the placeholder page', () => {
        expect(PAGE_SOURCE).not.toContain('overflow-x-auto rounded-xl');
    });

    it('renders nothing between the header and the grid', () => {
        const grid = mountPage().get('[data-dashboard-grid-stub]');
        const body = grid.element.parentElement as HTMLElement;

        expect(body.children).toHaveLength(2);
        expect(body.children[1]).toBe(grid.element);
    });

    it('renders nothing between the header and the empty state either', () => {
        const empty = mountPage({ dashboard: null }).get(
            '[data-default-dashboard-empty]',
        );
        const body = empty.element.parentElement as HTMLElement;

        expect(body.children).toHaveLength(2);
        expect(body.children[1]).toBe(empty.element);
    });

    it('paints no decorative fill pattern anywhere in the rendered page', () => {
        expect(mountPage().html()).not.toContain('<pattern');
        expect(mountPage({ dashboard: null }).html()).not.toContain('<pattern');
    });
});

describe('Dashboard — the switch between the available dashboards', () => {
    function groupLabels(wrapper: Wrapper): string[] {
        return wrapper
            .findAll('[data-select-group-label]')
            .map((label) => label.text());
    }

    function optionValues(wrapper: Wrapper): string[] {
        return wrapper
            .get('[data-default-dashboard-switch]')
            .findAll('option')
            .map((option) => option.attributes('value') ?? '');
    }

    it('groups the choices into the own and the shared dashboards', () => {
        const wrapper = mountPage();

        expect(groupLabels(wrapper)).toEqual([
            'Meine Dashboards',
            'Für mich freigegeben',
        ]);
        expect(optionValues(wrapper)).toEqual([DASHBOARD_ID, SHARED_ID]);
    });

    it('drops a group that holds no dashboard at all', () => {
        const wrapper = mountPage({
            options: {
                own: [{ value: DASHBOARD_ID, label: 'Vertriebsübersicht' }],
                shared: [],
            },
        });

        expect(groupLabels(wrapper)).toEqual(['Meine Dashboards']);
    });

    it('preselects the dashboard the server resolved', () => {
        const select = mountPage().get<HTMLSelectElement>(
            '[data-default-dashboard-switch]',
        );

        expect(select.element.value).toBe(DASHBOARD_ID);
    });

    it('loads the picked dashboard on the start page itself', async () => {
        const wrapper = mountPage();
        const select = wrapper.get('[data-default-dashboard-switch]');

        await select.setValue(SHARED_ID);

        expect(inertia.visit).toHaveBeenCalledTimes(1);
        expect(inertia.visit.mock.calls[0][0]).toContain(SHARED_ID);
        expect(inertia.visit.mock.calls[0][0]).toContain('/dashboard');
    });

    it('shows no switch at all while nothing was resolved', () => {
        const wrapper = mountPage({ dashboard: null, widgets: [] });

        expect(wrapper.find('[data-default-dashboard-switch]').exists()).toBe(
            false,
        );
    });
});

describe('Dashboard — making the shown dashboard the default', () => {
    function button(wrapper: Wrapper): ReturnType<Wrapper['get']> {
        return wrapper.get('[data-default-dashboard-make-default]');
    }

    it('sits between the edit icon and the switch', () => {
        const wrapper = mountPage();
        const row = wrapper.get('[data-default-dashboard-controls]');
        const controls = Array.from(row.element.children);

        expect(controls[0].matches('[data-default-dashboard-open]')).toBe(true);
        expect(
            controls[1].matches('[data-default-dashboard-make-default]'),
        ).toBe(true);
        expect(controls[2].matches('[data-default-dashboard-switch]')).toBe(
            true,
        );
    });

    it('stays enabled while another dashboard is the default', () => {
        const wrapper = mountPage({ defaultDashboardId: SHARED_ID });

        expect(button(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('turns itself off once the shown dashboard already is the default', () => {
        const wrapper = mountPage({ defaultDashboardId: DASHBOARD_ID });

        expect(button(wrapper).attributes('disabled')).toBeDefined();
    });

    it('stores the shown dashboard as the default', async () => {
        const wrapper = mountPage({ defaultDashboardId: SHARED_ID });

        await button(wrapper).trigger('click');

        expect(inertia.put).toHaveBeenCalledTimes(1);
        expect(inertia.put.mock.calls[0][0]).toContain(DASHBOARD_ID);
        expect(inertia.put.mock.calls[0][0]).toContain('/dashboard/default');
    });

    it('offers no button while nothing was resolved', () => {
        const wrapper = mountPage({ dashboard: null, widgets: [] });

        expect(
            wrapper.find('[data-default-dashboard-make-default]').exists(),
        ).toBe(false);
    });
});
