import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PropType } from 'vue';
import { defineComponent, h, nextTick } from 'vue';
import DashboardWidgetResultsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetResultsController';
import DashboardWidgetsController from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetsController';
import DashboardGrid from '@/components/dashboards/DashboardGrid.vue';
import DashboardWidget from '@/components/dashboards/DashboardWidget.vue';
import WidgetEditorSheet from '@/components/dashboards/WidgetEditorSheet.vue';
import {
    LAYOUT_ERROR_MESSAGE,
    WIDGET_CREATE_ERROR_MESSAGE,
    WIDGET_REMOVE_ERROR_MESSAGE,
    WIDGET_UPDATE_ERROR_MESSAGE,
} from '@/composables/useDashboardLayout';
import { RESULTS_ERROR_MESSAGE } from '@/composables/useWidgetResults';
import { comboboxStubs, selectStubs } from '@/tests/selectStubs';
import type {
    DashboardWidgetMeta,
    DashboardWidgetTile,
} from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';
import type { ReportResult } from '@/types/reports';
import { resolveRefusalMessage } from '@/types/reports';
import { setUrlDefaults } from '@/wayfinder';

const dnd = vi.hoisted(() => {
    const captured: { onDrop: ((event: unknown) => void) | null } = {
        onDrop: null,
    };
    const dragCleanup = vi.fn();
    const dropCleanup = vi.fn();
    const monitorCleanup = vi.fn();

    return {
        captured,
        dragCleanup,
        dropCleanup,
        monitorCleanup,
        draggable: vi.fn(() => dragCleanup),
        dropTargetForElements: vi.fn(() => dropCleanup),
        monitorForElements: vi.fn(
            (config: { onDrop: (event: unknown) => void }) => {
                captured.onDrop = config.onDrop;

                return monitorCleanup;
            },
        ),
    };
});

const chart = vi.hoisted(() => ({ registerChartModules: vi.fn() }));

const inertia = vi.hoisted(() => ({ visit: vi.fn(), put: vi.fn() }));

vi.mock('@atlaskit/pragmatic-drag-and-drop/element/adapter', () => ({
    draggable: dnd.draggable,
    dropTargetForElements: dnd.dropTargetForElements,
    monitorForElements: dnd.monitorForElements,
}));

vi.mock('ag-charts-vue3', () => ({
    AgCharts: defineComponent({
        name: 'AgChartsStub',
        props: {
            options: {
                type: Object as PropType<Record<string, unknown>>,
                required: true,
            },
        },
        setup() {
            return () => h('div', { 'data-chart-stub': true });
        },
    }),
}));

vi.mock('@/lib/charts/moduleRegistry', () => ({
    registerChartModules: chart.registerChartModules,
}));

vi.mock('vue-sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: inertia.visit,
        put: inertia.put,
    },
    usePage: () => ({
        url: '/nubos/dashboards/01DASHBOARD00000000000001',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const ACTIVE_TEAM = 'nubos';

const ENGLISH_WORDS = /\b(the|this|that|your|moved|please|could|failed)\b/i;

const NOTICE_MESSAGE = 'You are not allowed to query the source of this tile.';

const FIELD_KEY = 'stage';

const OBJECT_TYPE_NAME = 'Unternehmen';

type FetchMock = ReturnType<typeof vi.fn>;

type Wrapper = VueWrapper;

interface GridFetchOptions {
    tiles?: DashboardWidgetTile[];
    showPromise?: Promise<Response>;
    indexPromise?: Promise<Response>;
    layoutStatus?: number;
    resultsStatus?: number;
    createStatus?: number;
    createBody?: unknown;
    updateStatus?: number;
    destroyStatus?: number;
}

setUrlDefaults({ activeTeam: ACTIVE_TEAM });

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function widget(
    overrides: Partial<DashboardWidgetMeta> = {},
): DashboardWidgetMeta {
    return {
        id: 'w1',
        dashboard_id: DASHBOARD_ID,
        report_id: '01REPORT0000000000000001',
        goal_id: null,
        title: 'Erste Kachel',
        chart_type: 'metric',
        definition: null,
        position: 0,
        column_span: 1,
        updated_at: '2026-08-10T11:00:00+00:00',
        ...overrides,
    };
}

function adhocWidget(
    overrides: Partial<DashboardWidgetMeta> = {},
): DashboardWidgetMeta {
    return widget({
        id: 'w1',
        report_id: null,
        chart_type: 'bar',
        definition: {
            object_type_id: '01OBJECTTYPE000000000001',
            filter_definition: null,
            aggregation_type: 'sum',
            aggregation_field_key: 'amount',
            group_by_field_key: 'closed_at',
            group_by_bucket: 'week',
            series_field_key: 'stage',
        },
        ...overrides,
    });
}

function threeWidgets(): DashboardWidgetMeta[] {
    return [
        widget({
            id: 'w1',
            title: 'Erste Kachel',
            position: 0,
            column_span: 1,
        }),
        widget({
            id: 'w2',
            title: 'Zweite Kachel',
            position: 1,
            column_span: 1,
        }),
        widget({
            id: 'w3',
            title: 'Dritte Kachel',
            position: 2,
            column_span: 1,
        }),
    ];
}

function widgetsWithGap(): DashboardWidgetMeta[] {
    return [
        widget({
            id: 'w1',
            title: 'Erste Kachel',
            position: 0,
            column_span: 1,
        }),
        widget({
            id: 'w2',
            title: 'Zweite Kachel',
            position: 1,
            column_span: 1,
        }),
        widget({
            id: 'w3',
            title: 'Dritte Kachel',
            position: 2,
            column_span: 2,
        }),
    ];
}

function result(overrides: Partial<ReportResult> = {}): ReportResult {
    return {
        aggregation: 'count',
        rows: [
            {
                group_value: 'won',
                series_value: null,
                value: '42',
                record_count: 42,
                discarded_value_count: 0,
                is_other_group: false,
                is_other_series: false,
            },
        ],
        total: '42',
        record_count: 42,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T11:58:00Z',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function tile(
    widgetId: string,
    overrides: Partial<DashboardWidgetTile> = {},
): DashboardWidgetTile {
    return {
        widget_id: widgetId,
        title: 'Erste Kachel',
        chart_type: 'metric',
        goal_id: null,
        goal: null,
        position: 0,
        column_span: 1,
        report_id: '01REPORT0000000000000001',
        object_type: {
            id: '01OBJECTTYPE000000000001',
            slug: 'companies',
            name: OBJECT_TYPE_NAME,
        },
        execution_mode: 'viewer',
        generated_at: '2026-08-10T11:58:00Z',
        result: result(),
        notice: null,
        ...overrides,
    };
}

function pathOf(url: string): string {
    return new URL(url, 'http://localhost').pathname;
}

function layoutRoute(): string {
    return DashboardWidgetsController.updateLayout.url({
        dashboard: DASHBOARD_ID,
    });
}

function storeRoute(): string {
    return DashboardWidgetsController.store.url({ dashboard: DASHBOARD_ID });
}

function updateRoute(widgetId: string): string {
    return DashboardWidgetsController.update.url({
        dashboard: DASHBOARD_ID,
        widget: widgetId,
    });
}

function destroyRoute(widgetId: string): string {
    return DashboardWidgetsController.destroy.url({
        dashboard: DASHBOARD_ID,
        widget: widgetId,
    });
}

function resultsIndexRoute(): string {
    return DashboardWidgetResultsController.index.url({
        dashboard: DASHBOARD_ID,
    });
}

function resultsShowRoute(widgetId: string): string {
    return DashboardWidgetResultsController.show.url({
        dashboard: DASHBOARD_ID,
        widget: widgetId,
    });
}

function callsTo(
    fetchMock: FetchMock,
    method: string,
    route: string,
): Array<[string, RequestInit]> {
    return fetchMock.mock.calls.filter(([url, init]) => {
        const used = (init as RequestInit | undefined)?.method ?? 'GET';

        return used === method && pathOf(String(url)) === pathOf(route);
    }) as Array<[string, RequestInit]>;
}

function layoutCalls(fetchMock: FetchMock): Array<[string, RequestInit]> {
    return callsTo(fetchMock, 'PUT', layoutRoute());
}

function indexCalls(fetchMock: FetchMock): Array<[string, RequestInit]> {
    return callsTo(fetchMock, 'POST', resultsIndexRoute());
}

function showCalls(
    fetchMock: FetchMock,
    widgetId: string,
): Array<[string, RequestInit]> {
    return callsTo(fetchMock, 'POST', resultsShowRoute(widgetId));
}

function arrangementOf(
    call: [string, RequestInit],
): Array<Record<string, unknown>> {
    const body = call[1].body;

    if (typeof body !== 'string') {
        throw new Error('the arrangement request carries no JSON body');
    }

    const parsed = JSON.parse(body) as { widgets?: unknown };

    if (!Array.isArray(parsed.widgets)) {
        throw new Error('the arrangement request carries no widget list');
    }

    return parsed.widgets as Array<Record<string, unknown>>;
}

function idsOf(call: [string, RequestInit]): string[] {
    return arrangementOf(call).map((entry) => String(entry.id));
}

function gridFetch(options: GridFetchOptions = {}): FetchMock {
    const tiles = options.tiles ?? [];
    const showRoutes = new Map(
        ['w1', 'w2', 'w3', 'w4'].map((id) => [
            pathOf(resultsShowRoute(id)),
            id,
        ]),
    );
    const destroyRoutes = new Set(
        ['w1', 'w2', 'w3', 'w4'].map((id) => pathOf(destroyRoute(id))),
    );

    const mock = vi.fn((url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        const path = pathOf(String(url));

        if (method === 'POST' && path === pathOf(resultsIndexRoute())) {
            if (options.indexPromise !== undefined) {
                return options.indexPromise;
            }

            const status = options.resultsStatus ?? 200;

            return Promise.resolve(
                jsonResponse(
                    status,
                    status < 300 ? { data: tiles } : { message: 'refused' },
                ),
            );
        }

        if (method === 'POST' && path === pathOf(storeRoute())) {
            return Promise.resolve(
                jsonResponse(
                    options.createStatus ?? 201,
                    options.createBody ?? {
                        data: widget({ id: 'w4', title: 'Vierte Kachel' }),
                    },
                ),
            );
        }

        if (method === 'PUT' && path === pathOf(updateRoute('w2'))) {
            const status = options.updateStatus ?? 200;

            return Promise.resolve(
                jsonResponse(
                    status,
                    status < 300
                        ? { data: widget({ id: 'w2', title: 'Zweite Kachel' }) }
                        : {},
                ),
            );
        }

        if (method === 'DELETE' && destroyRoutes.has(path)) {
            return Promise.resolve(
                jsonResponse(options.destroyStatus ?? 200, { data: null }),
            );
        }

        const showId = showRoutes.get(path);

        if (method === 'POST' && showId !== undefined) {
            if (options.showPromise !== undefined) {
                return options.showPromise;
            }

            return Promise.resolve(jsonResponse(200, { data: tile(showId) }));
        }

        if (method === 'PUT' && path === pathOf(layoutRoute())) {
            return Promise.resolve(
                jsonResponse(options.layoutStatus ?? 200, {
                    data: { id: DASHBOARD_ID },
                }),
            );
        }

        return Promise.resolve(jsonResponse(200, { data: null }));
    });

    vi.stubGlobal('fetch', mock);

    return mock;
}

interface MountOptions {
    widgets?: DashboardWidgetMeta[];
    canUpdate?: boolean;
    updateReason?: string;
}

async function mountGrid(options: MountOptions = {}): Promise<Wrapper> {
    const wrapper = mount(DashboardGrid, {
        props: {
            dashboardId: DASHBOARD_ID,
            widgets: options.widgets ?? threeWidgets(),
            canUpdate: options.canUpdate ?? true,
            updateReason: options.updateReason,
            goalOptions: [],
            reportOptions: [{ value: 'r-1', label: 'Umsatzauswertung' }],
            objectTypeOptions: [
                { value: '01OBJECTTYPE000000000001', label: OBJECT_TYPE_NAME },
            ],
            fieldsByType: {},
            linkedFieldsByType: {},
            segmentsByType: {},
        },
        global: {
            stubs: {
                ...selectStubs,
                ...comboboxStubs,
                Sheet: { template: '<div><slot /></div>' },
                SheetContent: { template: '<div><slot /></div>' },
                SheetHeader: { template: '<div><slot /></div>' },
                SheetFooter: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<h2><slot /></h2>' },
                SheetDescription: { template: '<p><slot /></p>' },
                FilterBuilder: { template: '<div data-filter-builder />' },
            },
        },
    });

    await flushPromises();

    return wrapper;
}

function dropEvent(sourceId: string, targetId: string): unknown {
    return {
        source: { data: { type: 'dashboard-widget', widgetId: sourceId } },
        location: {
            current: {
                dropTargets: [
                    { data: { type: 'dashboard-widget', widgetId: targetId } },
                ],
            },
        },
    };
}

function performDrop(sourceId: string, targetId: string): boolean {
    if (dnd.captured.onDrop === null) {
        return false;
    }

    dnd.captured.onDrop(dropEvent(sourceId, targetId));

    return true;
}

function renderedIds(wrapper: Wrapper): string[] {
    return wrapper
        .findAll('[data-dashboard-widget]')
        .map((node) => String(node.attributes('data-dashboard-widget')));
}

interface ExposedGrid {
    refreshAll: () => Promise<void>;
    openEditor: (widgetId: string | null) => void;
    isRefreshingAll: boolean;
}

function exposedGrid(wrapper: Wrapper): ExposedGrid {
    return wrapper.vm as unknown as ExposedGrid;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: ACTIVE_TEAM });
    dnd.captured.onDrop = null;
    dnd.draggable.mockClear();
    dnd.dropTargetForElements.mockClear();
    dnd.monitorForElements.mockClear();
    dnd.dragCleanup.mockClear();
    dnd.dropCleanup.mockClear();
    dnd.monitorCleanup.mockClear();
    inertia.visit.mockReset();
    inertia.put.mockReset();
});

afterEach(() => {
    vi.unstubAllGlobals();
    setUrlDefaults({});
});

describe('DashboardGrid — dropping a tile', () => {
    it('sends one arrangement listing every tile once with the dropped one in front', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        expect(performDrop('w3', 'w1')).toBe(true);
        await flushPromises();

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(1);

        const entries = arrangementOf(calls[0]);
        const ids = entries.map((entry) => String(entry.id));

        expect([...ids].sort()).toEqual([...['w1', 'w2', 'w3']].sort());
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids[0]).toBe('w3');
        expect(renderedIds(wrapper)[0]).toBe('w3');

        entries.forEach((entry) => {
            expect(Object.keys(entry).sort()).toEqual(['column_span', 'id']);
        });
    });

    it('announces the move in German naming the tile and its new position', async () => {
        gridFetch();
        const wrapper = await mountGrid();

        performDrop('w3', 'w1');
        await nextTick();
        await nextTick();

        const region = wrapper.get('[data-dashboard-announcer]');

        expect(region.attributes('role')).toBe('status');
        expect(region.attributes('aria-live')).toBe('polite');
        expect(region.text().length).toBeGreaterThan(10);
        expect(region.text()).toContain('Dritte Kachel');
        expect(region.text()).toContain('1');
        expect(region.text()).not.toMatch(ENGLISH_WORDS);
    });
});

describe('DashboardGrid — the update right is the only barrier', () => {
    it('registers every tile as draggable and rearranges only with the update right', async () => {
        const allowedFetch = gridFetch();
        const allowed = await mountGrid({ canUpdate: true });

        expect(dnd.draggable).toHaveBeenCalledTimes(3);
        expect(performDrop('w3', 'w1')).toBe(true);
        await flushPromises();

        expect(layoutCalls(allowedFetch)).toHaveLength(1);
        expect(renderedIds(allowed)[0]).toBe('w3');

        allowed.unmount();
        dnd.draggable.mockClear();
        dnd.captured.onDrop = null;
        vi.unstubAllGlobals();

        const blockedFetch = gridFetch();
        const blocked = await mountGrid({
            canUpdate: false,
            updateReason: resolveDashboardActionRefusal('not_owner'),
        });

        expect(dnd.draggable).not.toHaveBeenCalled();

        performDrop('w3', 'w1');
        await flushPromises();

        expect(layoutCalls(blockedFetch)).toHaveLength(0);
        expect(renderedIds(blocked)).toEqual(['w1', 'w2', 'w3']);
    });

    it('disables the forbidden actions with a German tooltip instead of hiding them', async () => {
        const reason = resolveDashboardActionRefusal('not_owner');

        gridFetch();
        const blocked = await mountGrid({
            canUpdate: false,
            updateReason: reason,
        });

        const hooks = [
            '[data-widget-span-select]',
            '[data-widget-edit]',
            '[data-widget-remove]',
            '[data-dashboard-add-widget]',
        ];

        hooks.forEach((hook) => {
            const node = blocked.get(hook);

            expect(node.attributes('disabled')).toBeDefined();
            expect(node.attributes('title')).toBe(reason);
        });

        const labelled = ['[data-widget-edit]', '[data-widget-remove]'];

        labelled.forEach((hook) => {
            const node = blocked.get(hook);

            expect(node.attributes('title')).not.toBe(
                node.attributes('aria-label'),
            );
        });

        expect(
            blocked.get('[data-dashboard-add-widget]').attributes('title'),
        ).not.toBe(blocked.get('[data-dashboard-add-widget]').text());

        blocked.unmount();
        vi.unstubAllGlobals();

        gridFetch();
        const allowed = await mountGrid({ canUpdate: true });

        labelled.forEach((hook) => {
            const node = allowed.get(hook);

            expect(node.attributes('disabled')).toBeUndefined();
            expect(node.attributes('title')).toBe(
                node.attributes('aria-label'),
            );
        });

        expect(
            allowed.get('[data-widget-span-select]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('keeps refreshing a single tile available to a reader', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid({
            canUpdate: false,
            updateReason: resolveDashboardActionRefusal('not_owner'),
        });

        const refresh = wrapper.findAll('[data-widget-refresh]')[0];

        expect(refresh.attributes('disabled')).toBeUndefined();

        await refresh.trigger('click');
        await flushPromises();

        expect(showCalls(fetchMock, 'w1')).toHaveLength(1);
    });
});

describe('DashboardGrid — the column step from the tile menu', () => {
    it('sends one arrangement in which only the chosen tile changed its span', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        await wrapper
            .findAll('[data-dashboard-widget]')[1]
            .get('[data-widget-span-select]')
            .setValue('2');
        await flushPromises();

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(1);
        expect(arrangementOf(calls[0])).toEqual([
            { id: 'w1', column_span: 1 },
            { id: 'w2', column_span: 2 },
            { id: 'w3', column_span: 1 },
        ]);
    });
});

describe('DashboardGrid — one tile with a notice leaves the others alone', () => {
    it('shows the German notice on one tile while the other two keep their numbers', async () => {
        const fetchMock = gridFetch({
            tiles: [
                tile('w1'),
                tile('w2'),
                tile('w3', {
                    result: null,
                    object_type: null,
                    notice: {
                        reason: 'source_not_visible',
                        message: NOTICE_MESSAGE,
                    },
                }),
            ],
        });
        const wrapper = await mountGrid();

        expect(wrapper.findAll('[data-metric-value]')).toHaveLength(2);
        expect(wrapper.findAll('[data-metric-refusal]')).toHaveLength(1);
        expect(wrapper.get('[data-metric-refusal]').text()).toBe(
            resolveRefusalMessage('source_not_visible'),
        );
        expect(wrapper.text()).not.toContain(NOTICE_MESSAGE);
        expect(wrapper.text()).not.toContain(FIELD_KEY);
        expect(wrapper.text()).not.toContain(OBJECT_TYPE_NAME);

        await wrapper.findAll('[data-widget-refresh]')[0].trigger('click');
        await flushPromises();

        expect(showCalls(fetchMock, 'w1')).toHaveLength(1);
        expect(wrapper.findAll('[data-metric-refusal]')).toHaveLength(1);
    });
    it('shows the notice on both tiles that lean on the same hidden field', async () => {
        gridFetch({
            tiles: [
                tile('w1', {
                    result: null,
                    object_type: null,
                    notice: {
                        reason: 'source_not_visible',
                        message: NOTICE_MESSAGE,
                    },
                }),
                tile('w2', {
                    result: null,
                    object_type: null,
                    notice: {
                        reason: 'source_not_visible',
                        message: NOTICE_MESSAGE,
                    },
                }),
                tile('w3'),
            ],
        });
        const wrapper = await mountGrid();

        expect(wrapper.findAll('[data-metric-refusal]')).toHaveLength(2);
        expect(wrapper.findAll('[data-metric-value]')).toHaveLength(1);

        wrapper.findAll('[data-metric-refusal]').forEach((node) => {
            expect(node.text()).toBe(
                resolveRefusalMessage('source_not_visible'),
            );
        });
    });
});

describe('DashboardGrid — marking the definer bound tiles', () => {
    it('marks exactly the definer bound tile of a mixed dashboard', async () => {
        gridFetch({
            tiles: [
                tile('w1', { execution_mode: 'definer' }),
                tile('w2', { execution_mode: 'viewer' }),
                tile('w3', { execution_mode: 'viewer' }),
            ],
        });
        const wrapper = await mountGrid();

        const marked = wrapper.findAll('[data-widget-definer]');

        expect(marked).toHaveLength(1);
        expect(
            wrapper
                .findAll('[data-dashboard-widget]')[0]
                .find('[data-widget-definer]')
                .exists(),
        ).toBe(true);
    });
});

describe('DashboardGrid — fetching the numbers', () => {
    it('refreshes every tile through the collective route and none through the single one', async () => {
        const fetchMock = gridFetch({ tiles: [tile('w1'), tile('w2')] });
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        await exposedGrid(wrapper).refreshAll();
        await flushPromises();

        expect(indexCalls(fetchMock)).toHaveLength(1);
        expect(showCalls(fetchMock, 'w1')).toHaveLength(0);
        expect(showCalls(fetchMock, 'w2')).toHaveLength(0);
    });

    it('refreshes a single tile through the single route and none through the collective one', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        await wrapper.findAll('[data-widget-refresh]')[1].trigger('click');
        await flushPromises();

        expect(showCalls(fetchMock, 'w2')).toHaveLength(1);
        expect(indexCalls(fetchMock)).toHaveLength(0);
    });

    it('reports the collective refresh as running until the answer arrives', async () => {
        gridFetch({ tiles: [tile('w1'), tile('w2'), tile('w3')] });
        const wrapper = await mountGrid();

        expect(exposedGrid(wrapper).isRefreshingAll).toBe(false);

        let release: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            release = resolve;
        });
        const fetchMock = gridFetch({ indexPromise: pending });
        const running = exposedGrid(wrapper).refreshAll();

        await nextTick();

        expect(indexCalls(fetchMock)).toHaveLength(1);
        expect(exposedGrid(wrapper).isRefreshingAll).toBe(true);

        release(jsonResponse(200, { data: [tile('w1')] }));
        await running;
        await flushPromises();

        expect(exposedGrid(wrapper).isRefreshingAll).toBe(false);
    });

    it('answers two quick clicks on the same tile with one request', async () => {
        let release: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            release = resolve;
        });

        const fetchMock = gridFetch({ showPromise: pending });
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        const refresh = wrapper.findAll('[data-widget-refresh]')[0];

        void refresh.trigger('click');
        void refresh.trigger('click');
        await flushPromises();

        expect(showCalls(fetchMock, 'w1')).toHaveLength(1);

        release(jsonResponse(200, { data: tile('w1') }));
        await flushPromises();
    });
});

describe('DashboardGrid — from a chart segment into the record list', () => {
    it('carries the dashboard, the widget and the clicked group into the list URL', async () => {
        gridFetch({ tiles: [tile('w1'), tile('w2'), tile('w3')] });
        const wrapper = await mountGrid();

        wrapper.findAllComponents(DashboardWidget)[0].vm.$emit('drill-down', {
            widgetId: 'w1',
            selection: { group: 'v:won', series: null },
        });
        await flushPromises();

        expect(inertia.visit).toHaveBeenCalledTimes(1);

        const target = String(inertia.visit.mock.calls[0][0]);
        const query = new URLSearchParams(target.slice(target.indexOf('?')));

        expect(query.get('dashboard')).toBe(DASHBOARD_ID);
        expect(query.get('widget')).toBe('w1');
        expect(query.get('group')).toBe('v:won');
        expect(query.has('report')).toBe(false);
        expect(target).toContain('/records/companies');
    });

    it('carries the very same parameters for a tile with its own definition', async () => {
        gridFetch({ tiles: [tile('w1')] });
        const wrapper = await mountGrid({ widgets: [adhocWidget()] });

        wrapper.findAllComponents(DashboardWidget)[0].vm.$emit('drill-down', {
            widgetId: 'w1',
            selection: { group: 'v:won', series: null },
        });
        await flushPromises();

        expect(inertia.visit).toHaveBeenCalledTimes(1);

        const target = String(inertia.visit.mock.calls[0][0]);
        const query = new URLSearchParams(target.slice(target.indexOf('?')));

        expect(query.get('dashboard')).toBe(DASHBOARD_ID);
        expect(query.get('widget')).toBe('w1');
        expect(query.get('group')).toBe('v:won');
        expect(query.has('report')).toBe(false);
        expect(target).toContain('/records/companies');
    });

    it('stays put when the tile has no object type to drill into', async () => {
        gridFetch({
            tiles: [
                tile('w1', {
                    object_type: null,
                    result: null,
                    notice: {
                        reason: 'source_not_visible',
                        message: NOTICE_MESSAGE,
                    },
                }),
            ],
        });
        const wrapper = await mountGrid({ widgets: [widget({ id: 'w1' })] });

        wrapper.findAllComponents(DashboardWidget)[0].vm.$emit('drill-down', {
            widgetId: 'w1',
            selection: { group: 'v:won', series: null },
        });
        await flushPromises();

        expect(inertia.visit).not.toHaveBeenCalled();
    });
});

describe('DashboardGrid — a dashboard without a single widget', () => {
    it('explains the empty dashboard and offers to add the first widget', async () => {
        gridFetch();
        const wrapper = await mountGrid({ widgets: [] });

        const empty = wrapper.get('[data-dashboard-empty]');

        expect(empty.text().length).toBeGreaterThan(20);
        expect(empty.text()).not.toMatch(ENGLISH_WORDS);
        expect(wrapper.find('[data-dashboard-add-widget]').exists()).toBe(true);
        expect(wrapper.find('[data-dashboard-grid]').exists()).toBe(false);
        expect(wrapper.findAll('[data-widget-loading]')).toHaveLength(0);
    });
});

describe('DashboardGrid — adding, editing and removing a tile', () => {
    it('creates the submitted tile and fetches exactly its own numbers afterwards', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        exposedGrid(wrapper).openEditor(null);
        await nextTick();

        expect(wrapper.find('[data-widget-editor]').exists()).toBe(true);

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: null,
            chart_type: 'metric',
            column_span: 1,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(1);
        expect(showCalls(fetchMock, 'w4')).toHaveLength(1);
        expect(indexCalls(fetchMock)).toHaveLength(0);
        expect(wrapper.find('[data-widget-editor]').exists()).toBe(false);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3', 'w4']);
    });

    it('updates the tile it was opened on instead of creating a second one', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        exposedGrid(wrapper).openEditor('w2');
        await nextTick();

        const sheet = wrapper.findComponent(WidgetEditorSheet);

        expect(sheet.props('widget')).toEqual(threeWidgets()[1]);

        sheet.vm.$emit('submit', {
            title: 'Zweite Kachel',
            chart_type: 'metric',
            column_span: 2,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'PUT', updateRoute('w2'))).toHaveLength(1);
        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(0);
        expect(layoutCalls(fetchMock)).toHaveLength(0);
        expect(showCalls(fetchMock, 'w2')).toHaveLength(1);
        expect(wrapper.find('[data-widget-editor]').exists()).toBe(false);
    });

    it('keeps the editor open and hands the refused field back to it', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({
            createStatus: 422,
            createBody: {
                message: 'refused',
                errors: { report_id: ['FEHLER_REPORT'] },
            },
        });
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        exposedGrid(wrapper).openEditor(null);
        await nextTick();

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: null,
            chart_type: 'metric',
            column_span: 1,
            report_id: null,
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(1);
        expect(wrapper.find('[data-widget-editor]').exists()).toBe(true);
        expect(
            wrapper.findComponent(WidgetEditorSheet).props('errors'),
        ).toEqual({ report_id: 'FEHLER_REPORT' });
        expect(showCalls(fetchMock, 'w4')).toHaveLength(0);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);

        consoleError.mockRestore();
    });

    it('removes the tile its menu pointed at through exactly one delete', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-remove]')
            .trigger('click');
        await flushPromises();

        expect(callsTo(fetchMock, 'DELETE', destroyRoute('w1'))).toHaveLength(
            1,
        );
        expect(renderedIds(wrapper)).toEqual(['w2', 'w3']);
        expect(layoutCalls(fetchMock)).toHaveLength(0);
    });
});

describe('DashboardGrid — arranging without a mouse', () => {
    it('moves a tile backwards through its keyboard action and announces it', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-move-down]')
            .trigger('click');
        await flushPromises();

        const calls = layoutCalls(fetchMock);

        expect(calls).toHaveLength(1);
        expect(idsOf(calls[0])).toEqual(['w2', 'w1', 'w3']);
        expect(renderedIds(wrapper)).toEqual(['w2', 'w1', 'w3']);

        const region = wrapper.get('[data-dashboard-announcer]');

        expect(region.text()).toContain('Erste Kachel');
        expect(region.text()).toContain('2');
        expect(region.text()).not.toMatch(ENGLISH_WORDS);
    });

    it('changes nothing and sends nothing at the front edge', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-move-up]')
            .trigger('click');
        await flushPromises();

        expect(layoutCalls(fetchMock)).toHaveLength(0);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);
    });
});

describe('DashboardGrid — when the numbers cannot be loaded at all', () => {
    it('says so in German and keeps the arrangement usable', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({ resultsStatus: 500 });
        const wrapper = await mountGrid();

        const line = wrapper.get('[data-dashboard-error]');

        expect(line.text()).toBe(RESULTS_ERROR_MESSAGE);
        expect(line.text()).not.toMatch(ENGLISH_WORDS);
        expect(wrapper.findAll('[data-dashboard-widget]')).toHaveLength(3);

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-move-down]')
            .trigger('click');
        await flushPromises();

        expect(layoutCalls(fetchMock)).toHaveLength(1);

        consoleError.mockRestore();
    });

    it('shows no error line while every endpoint answers', async () => {
        gridFetch();
        const wrapper = await mountGrid();

        expect(wrapper.find('[data-dashboard-error]').exists()).toBe(false);
        expect(wrapper.findAll('[data-dashboard-widget]')).toHaveLength(3);
    });
});

describe('DashboardGrid — when a write is refused', () => {
    it('says the arrangement was refused and puts the order back', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({ layoutStatus: 500 });
        const wrapper = await mountGrid();

        expect(wrapper.find('[data-dashboard-error]').exists()).toBe(false);

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-move-down]')
            .trigger('click');
        await flushPromises();

        expect(layoutCalls(fetchMock)).toHaveLength(1);
        expect(wrapper.get('[data-dashboard-error]').text()).toBe(
            LAYOUT_ERROR_MESSAGE,
        );
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);

        consoleError.mockRestore();
    });

    it('keeps the editor open and names the refusal when the tile cannot be created', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({ createStatus: 500 });
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        exposedGrid(wrapper).openEditor(null);
        await nextTick();

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: null,
            chart_type: 'metric',
            column_span: 1,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(1);
        expect(wrapper.find('[data-widget-editor]').exists()).toBe(true);
        expect(
            wrapper.findComponent(WidgetEditorSheet).props('errors'),
        ).toEqual({});
        expect(wrapper.get('[data-dashboard-error]').text()).toBe(
            WIDGET_CREATE_ERROR_MESSAGE,
        );
        expect(showCalls(fetchMock, 'w4')).toHaveLength(0);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);

        consoleError.mockRestore();
    });

    it('keeps the editor open and names the refusal when the tile cannot be updated', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({ updateStatus: 500 });
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        expect(wrapper.find('[data-dashboard-error]').exists()).toBe(false);

        exposedGrid(wrapper).openEditor('w2');
        await nextTick();

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: 'Zweite Kachel',
            chart_type: 'metric',
            column_span: 2,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'PUT', updateRoute('w2'))).toHaveLength(1);
        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(0);
        expect(wrapper.find('[data-widget-editor]').exists()).toBe(true);
        expect(
            wrapper.findComponent(WidgetEditorSheet).props('errors'),
        ).toEqual({});
        expect(wrapper.get('[data-dashboard-error]').text()).toBe(
            WIDGET_UPDATE_ERROR_MESSAGE,
        );
        expect(showCalls(fetchMock, 'w2')).toHaveLength(0);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);

        consoleError.mockRestore();
    });

    it('keeps the tile and names the refusal when it cannot be removed', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const fetchMock = gridFetch({ destroyStatus: 500 });
        const wrapper = await mountGrid();

        await wrapper
            .findAll('[data-dashboard-widget]')[0]
            .get('[data-widget-remove]')
            .trigger('click');
        await flushPromises();

        expect(callsTo(fetchMock, 'DELETE', destroyRoute('w1'))).toHaveLength(
            1,
        );
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3']);
        expect(wrapper.get('[data-dashboard-error]').text()).toBe(
            WIDGET_REMOVE_ERROR_MESSAGE,
        );

        consoleError.mockRestore();
    });
});

describe('DashboardGrid — it releases every listener it registered', () => {
    it('releases drag, drop and monitor when the grid goes away', async () => {
        gridFetch();
        const wrapper = await mountGrid();

        expect(dnd.dragCleanup).not.toHaveBeenCalled();
        expect(dnd.monitorCleanup).not.toHaveBeenCalled();

        wrapper.unmount();
        await flushPromises();

        expect(dnd.dragCleanup).toHaveBeenCalledTimes(3);
        expect(dnd.dropCleanup).toHaveBeenCalledTimes(3);
        expect(dnd.monitorCleanup).toHaveBeenCalledTimes(1);
    });

    it('releases the old registrations before it registers a changed set', async () => {
        gridFetch();
        const wrapper = await mountGrid();

        expect(dnd.draggable).toHaveBeenCalledTimes(3);
        expect(dnd.dragCleanup).not.toHaveBeenCalled();

        await wrapper.setProps({
            widgets: [...threeWidgets(), widget({ id: 'w4', position: 3 })],
        });
        await flushPromises();

        expect(dnd.dragCleanup).toHaveBeenCalledTimes(3);
        expect(dnd.draggable).toHaveBeenCalledTimes(7);
        expect(dnd.monitorForElements).toHaveBeenCalledTimes(1);
    });
});

describe('DashboardGrid — the free columns of a row', () => {
    it('offers its own add tile in the columns a wider tile left free', async () => {
        gridFetch();
        const wrapper = await mountGrid({ widgets: widgetsWithGap() });

        const placed = wrapper
            .findAll('[data-dashboard-widget], [data-dashboard-add-slot]')
            .map((node) => node.attributes('data-dashboard-widget') ?? 'add');

        expect(placed).toEqual(['w1', 'w2', 'add', 'w3', 'add']);

        const slots = wrapper.findAll('[data-dashboard-add-slot]');

        expect(slots[0].classes()).toContain('md:col-span-1');
        expect(slots[0].find('[data-dashboard-add-widget]').exists()).toBe(
            true,
        );
    });

    it('creates the tile into that gap instead of behind the wider one', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid({ widgets: widgetsWithGap() });

        fetchMock.mockClear();

        await wrapper
            .findAll('[data-dashboard-add-slot]')[0]
            .get('[data-dashboard-add-widget]')
            .trigger('click');
        await nextTick();

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: null,
            chart_type: 'metric',
            column_span: 1,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(callsTo(fetchMock, 'POST', storeRoute())).toHaveLength(1);
        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w4', 'w3']);

        const arrangement = layoutCalls(fetchMock);

        expect(arrangement).toHaveLength(1);
        expect(idsOf(arrangement[0])).toEqual(['w1', 'w2', 'w4', 'w3']);
    });

    it('appends without an arrangement request when the last row is full', async () => {
        const fetchMock = gridFetch();
        const wrapper = await mountGrid();

        fetchMock.mockClear();

        const slots = wrapper.findAll('[data-dashboard-add-slot]');

        expect(slots).toHaveLength(1);

        await slots[0].get('[data-dashboard-add-widget]').trigger('click');
        await nextTick();

        wrapper.findComponent(WidgetEditorSheet).vm.$emit('submit', {
            title: null,
            chart_type: 'metric',
            column_span: 1,
            report_id: '01REPORT0000000000000001',
        });
        await flushPromises();

        expect(renderedIds(wrapper)).toEqual(['w1', 'w2', 'w3', 'w4']);
        expect(layoutCalls(fetchMock)).toHaveLength(0);
    });
});
