import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import Show from '@/pages/dashboards/Show.vue';
import type { DashboardRow, DashboardWidgetMeta } from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { SelectOption } from '@/types/ui';
import { setUrlDefaults } from '@/wayfinder';

const grid = vi.hoisted(() => ({
    refreshAll: vi.fn(),
    openEditor: vi.fn(),
}));

const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    Head: {
        props: ['title'],
        template: '<head-stub :title="title"><slot /></head-stub>',
    },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: inertia.visit },
    usePage: () => ({
        url: '/nubos/dashboards/01DASHBOARD00000000000001',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const OBJECT_TYPE_ID = '01OBJECTTYPE000000000001';

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
        'goalOptions',
        'objectTypeOptions',
        'fieldsByType',
        'linkedFieldsByType',
        'segmentsByType',
    ],
    data() {
        return { isRefreshingAll: false };
    },
    methods: {
        refreshAll: grid.refreshAll,
        openEditor: grid.openEditor,
    },
    template: '<div data-dashboard-grid-stub />',
};

const ShareSheetStub = {
    name: 'DashboardShareSheetStub',
    props: ['dashboard', 'open'],
    emits: ['close'],
    template: '<div data-share-sheet-stub />',
};

const DetailsSheetStub = {
    name: 'DashboardDetailsSheetStub',
    props: ['dashboard', 'open'],
    emits: ['close'],
    template: '<div data-details-sheet-stub />',
};

const GOAL_OPTIONS: SelectOption[] = [
    { value: '01GOAL00000000000000001A', label: 'Quartalsziel' },
];

const REPORT_OPTIONS: SelectOption[] = [
    { value: '01REPORT0000000000000001', label: 'Umsatzauswertung' },
];

const OBJECT_TYPE_OPTIONS: SelectOption[] = [
    { value: OBJECT_TYPE_ID, label: 'Unternehmen' },
];

const FIELDS_BY_TYPE: Record<string, FieldDefinition[]> = {
    [OBJECT_TYPE_ID]: [],
};

const SEGMENTS_BY_TYPE: Record<string, SelectOption[]> = {
    [OBJECT_TYPE_ID]: [{ value: '01SEGMENT00000000000001', label: 'Aktive' }],
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

function mountShow(overrides: Partial<DashboardRow> = {}): Wrapper {
    return mount(Show, {
        props: {
            dashboard: dashboard(overrides),
            widgets: widgets(),
            reportOptions: REPORT_OPTIONS,
            goalOptions: GOAL_OPTIONS,
            objectTypeOptions: OBJECT_TYPE_OPTIONS,
            fieldsByType: FIELDS_BY_TYPE,
            linkedFieldsByType: FIELDS_BY_TYPE,
            segmentsByType: SEGMENTS_BY_TYPE,
        },
        global: {
            stubs: {
                DashboardGrid: GridStub,
                DashboardShareSheet: ShareSheetStub,
                DashboardDetailsSheet: DetailsSheetStub,
            },
        },
    });
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    grid.refreshAll.mockReset();
    grid.openEditor.mockReset();
    inertia.visit.mockReset();
});

describe('dashboards/Show — what the server sent reaches the grid', () => {
    it('hands every editor payload down to the grid untouched', () => {
        const gridComponent = mountShow().findComponent(GridStub);

        expect(gridComponent.props('dashboardId')).toBe(DASHBOARD_ID);
        expect(gridComponent.props('widgets')).toEqual(widgets());
        expect(gridComponent.props('canUpdate')).toBe(true);
        expect(gridComponent.props('reportOptions')).toEqual(REPORT_OPTIONS);
        expect(gridComponent.props('objectTypeOptions')).toEqual(
            OBJECT_TYPE_OPTIONS,
        );
        expect(gridComponent.props('fieldsByType')).toEqual(FIELDS_BY_TYPE);
        expect(gridComponent.props('linkedFieldsByType')).toEqual(
            FIELDS_BY_TYPE,
        );
        expect(gridComponent.props('segmentsByType')).toEqual(SEGMENTS_BY_TYPE);
    });

    it('hands the dashboard itself to both slide-over panels', () => {
        const wrapper = mountShow();

        expect(
            wrapper.findComponent(ShareSheetStub).props('dashboard'),
        ).toEqual(dashboard());
        expect(
            wrapper.findComponent(DetailsSheetStub).props('dashboard'),
        ).toEqual(dashboard());
    });
});

describe('dashboards/Show — the header actions', () => {
    it('refreshes every tile through the grid exactly once', async () => {
        const wrapper = mountShow();

        await wrapper.get('[data-dashboard-refresh-all]').trigger('click');

        expect(grid.refreshAll).toHaveBeenCalledTimes(1);
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('opens the widget editor instead of navigating anywhere', async () => {
        const wrapper = mountShow();
        const add = wrapper.get('[data-dashboard-add-widget]');

        expect(add.attributes('href')).toBeUndefined();

        await add.trigger('click');

        expect(grid.openEditor).toHaveBeenCalledTimes(1);
        expect(grid.openEditor.mock.calls[0][0]).toBeNull();
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('blocks a second collective refresh while the grid is still fetching', async () => {
        const wrapper = mountShow();
        const gridComponent = wrapper.findComponent(GridStub);

        expect(
            wrapper.get('[data-dashboard-refresh-all]').attributes('disabled'),
        ).toBeUndefined();

        (
            gridComponent.vm as unknown as { isRefreshingAll: boolean }
        ).isRefreshingAll = true;
        await nextTick();

        expect(
            wrapper.get('[data-dashboard-refresh-all]').attributes('disabled'),
        ).toBeDefined();
    });

    it('opens the master data as a slide-over instead of navigating to a page', async () => {
        const wrapper = mountShow();
        const edit = wrapper.get('[data-dashboard-edit]');

        expect(edit.attributes('href')).toBeUndefined();
        expect(wrapper.findComponent(DetailsSheetStub).props('open')).toBe(
            false,
        );

        await edit.trigger('click');

        expect(wrapper.findComponent(DetailsSheetStub).props('open')).toBe(
            true,
        );
        expect(inertia.visit).not.toHaveBeenCalled();

        wrapper.findComponent(DetailsSheetStub).vm.$emit('close');
        await nextTick();

        expect(wrapper.findComponent(DetailsSheetStub).props('open')).toBe(
            false,
        );
    });

    it('opens the shares as a slide-over from a header button', async () => {
        const wrapper = mountShow();
        const shareButton = wrapper.get('[data-dashboard-shares]');

        expect(shareButton.attributes('href')).toBeUndefined();
        expect(wrapper.findComponent(ShareSheetStub).props('open')).toBe(false);

        await shareButton.trigger('click');

        expect(wrapper.findComponent(ShareSheetStub).props('open')).toBe(true);
        expect(inertia.visit).not.toHaveBeenCalled();

        wrapper.findComponent(ShareSheetStub).vm.$emit('close');
        await nextTick();

        expect(wrapper.findComponent(ShareSheetStub).props('open')).toBe(false);
    });

    it('renders no share card on the page any more', () => {
        expect(mountShow().find('[data-share-card]').exists()).toBe(false);
    });
});

describe('dashboards/Show — a viewer who may not share', () => {
    it('disables the share button with a German reason and keeps the panel shut', async () => {
        const reason = resolveDashboardActionRefusal('not_owner');
        const blocked = mountShow({
            can_share: false,
            share_reason: 'not_owner',
            is_owner: false,
        });

        const button = blocked.get('[data-dashboard-shares]');

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('title')).toBe(reason);

        await button.trigger('click');

        expect(blocked.findComponent(ShareSheetStub).props('open')).toBe(false);
    });
});

describe('dashboards/Show — a viewer who may not change the dashboard', () => {
    it('disables editing and adding with a German reason instead of hiding them', async () => {
        const reason = resolveDashboardActionRefusal('not_owner');
        const blocked = mountShow({
            can_update: false,
            update_reason: 'not_owner',
            is_owner: false,
        });

        ['[data-dashboard-edit]', '[data-dashboard-add-widget]'].forEach(
            (hook) => {
                const node = blocked.get(hook);

                expect(node.attributes('disabled')).toBeDefined();
                expect(node.attributes('title')).toBe(reason);
            },
        );

        expect(
            blocked.get('[data-dashboard-edit]').attributes('title'),
        ).not.toBe(
            blocked.get('[data-dashboard-edit]').attributes('aria-label'),
        );
        expect(
            blocked.get('[data-dashboard-add-widget]').attributes('title'),
        ).not.toBe(blocked.get('[data-dashboard-add-widget]').text());

        await blocked.get('[data-dashboard-add-widget]').trigger('click');
        await blocked.get('[data-dashboard-edit]').trigger('click');

        expect(grid.openEditor).not.toHaveBeenCalled();
        expect(blocked.findComponent(DetailsSheetStub).props('open')).toBe(
            false,
        );

        const allowed = mountShow({ can_update: true, update_reason: null });

        expect(
            allowed.get('[data-dashboard-edit]').attributes('disabled'),
        ).toBeUndefined();
        expect(allowed.get('[data-dashboard-edit]').attributes('title')).toBe(
            allowed.get('[data-dashboard-edit]').attributes('aria-label'),
        );
        expect(
            allowed.get('[data-dashboard-add-widget]').attributes('disabled'),
        ).toBeUndefined();
        expect(
            allowed.get('[data-dashboard-refresh-all]').attributes('disabled'),
        ).toBeUndefined();
    });
});

describe('dashboards/Show — the page shell', () => {
    it('titles the document with the dashboard name', () => {
        expect(mountShow().get('head-stub').attributes('title')).toBe(
            dashboard().name,
        );
    });

    it('renders no page level back link to the list', () => {
        const targets = mountShow()
            .findAll('a')
            .map((anchor) => anchor.attributes('href'));

        expect(targets).not.toContain(DashboardsController.index.url());
    });
});
