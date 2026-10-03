import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import ReportChart from '@/components/charts/ReportChart.vue';
import DashboardWidget from '@/components/dashboards/DashboardWidget.vue';
import { Card } from '@/components/ui/card';
import { selectStubs } from '@/tests/selectStubs';
import type {
    DashboardWidgetMeta,
    DashboardWidgetTile,
} from '@/types/dashboards';
import type { GoalListRow } from '@/types/goals';
import type { ReportDrillDownSelection, ReportResult } from '@/types/reports';
import { resolveRefusalMessage } from '@/types/reports';

const chart = vi.hoisted(() => ({
    registerChartModules: vi.fn(),
    download: vi.fn(),
}));

const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

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
        data() {
            return { chart: { download: chart.download } };
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
    router: { on: vi.fn(() => vi.fn()), visit: inertia.visit },
    usePage: () => ({ url: '/nubos/dashboards/x', props: {} }),
}));

const WIDGET_ID = '01WIDGET000000000000000A';

const WIDGET_TITLE = 'Umsatz je Region';

const OBJECT_TYPE_NAME = 'Unternehmen';

const FIELD_KEY = 'stage';

const NOTICE_MESSAGE = 'You are not allowed to query the source of this tile.';

const NOW = '2026-08-10T12:00:00Z';

const ENGLISH_WORDS = /\b(the|this|that|your|allowed|source|tile)\b/i;

type Wrapper = VueWrapper;

function meta(
    overrides: Partial<DashboardWidgetMeta> = {},
): DashboardWidgetMeta {
    return {
        id: WIDGET_ID,
        dashboard_id: '01DASHBOARD00000000000001',
        goal_id: null,
        report_id: '01REPORT0000000000000001',
        title: WIDGET_TITLE,
        chart_type: 'metric',
        definition: null,
        position: 0,
        column_span: 1,
        updated_at: '2026-08-10T11:00:00+00:00',
        ...overrides,
    };
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
    overrides: Partial<DashboardWidgetTile> = {},
): DashboardWidgetTile {
    return {
        widget_id: WIDGET_ID,
        title: WIDGET_TITLE,
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

function noticeTile(
    reason: string,
    overrides: Partial<DashboardWidgetTile> = {},
): DashboardWidgetTile {
    return tile({
        result: null,
        object_type: null,
        notice: { reason, message: NOTICE_MESSAGE },
        ...overrides,
    });
}

interface MountOptions {
    widget?: DashboardWidgetMeta;
    tile?: DashboardWidgetTile | null;
    canUpdate?: boolean;
    updateReason?: string;
    isRefreshing?: boolean;
    isDropTarget?: boolean;
}

function mountWidget(options: MountOptions = {}): Wrapper {
    return mount(DashboardWidget, {
        props: {
            widget: options.widget ?? meta(),
            tile: options.tile === undefined ? tile() : options.tile,
            canUpdate: options.canUpdate ?? true,
            updateReason: options.updateReason,
            isRefreshing: options.isRefreshing ?? false,
            isDropTarget: options.isDropTarget ?? false,
        },
        global: { stubs: { ...selectStubs } },
    });
}

function occurrences(haystack: string, needle: string): number {
    return haystack.split(needle).length - 1;
}

let fetchMock = vi.fn();

beforeEach(() => {
    vi.setSystemTime(new Date(NOW));
    inertia.visit.mockReset();
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('DashboardWidget — the visible age of its numbers', () => {
    it('reads a stamp of two minutes ago off the server value', () => {
        const wrapper = mountWidget({
            tile: tile({ generated_at: '2026-08-10T11:58:00Z' }),
        });

        const stamp = wrapper.get('[data-widget-generated-at]');

        expect(stamp.text()).toBe('vor 2 Minuten');
        expect(stamp.attributes('datetime')).toBe('2026-08-10T11:58:00Z');
    });

    it('reads a stamp of three hours ago off the very same server value', () => {
        const wrapper = mountWidget({
            tile: tile({ generated_at: '2026-08-10T09:00:00Z' }),
        });

        expect(wrapper.get('[data-widget-generated-at]').text()).toBe(
            'vor 3 Stunden',
        );
    });
});

describe('DashboardWidget — while the numbers are still on their way', () => {
    it('shows its own skeleton and drops it again once a result arrives', async () => {
        const wrapper = mountWidget({ tile: null });

        expect(wrapper.find('[data-widget-loading]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);

        await wrapper.setProps({ tile: tile() });

        expect(wrapper.find('[data-widget-loading]').exists()).toBe(false);
        expect(
            wrapper.get('[data-metric-value]').text().length,
        ).toBeGreaterThan(0);
    });
});

describe('DashboardWidget — one card, one title', () => {
    it('wraps a metric in exactly one card and prints its title exactly once', () => {
        const wrapper = mountWidget();

        expect(wrapper.findAllComponents(Card)).toHaveLength(1);
        expect(occurrences(wrapper.text(), WIDGET_TITLE)).toBe(1);
    });

    it('wraps a chart in exactly one card as well', () => {
        const wrapper = mountWidget({
            widget: meta({ chart_type: 'bar' }),
            tile: tile({ chart_type: 'bar' }),
        });

        expect(wrapper.findAllComponents(Card)).toHaveLength(1);
        expect(wrapper.findComponent(ReportChart).exists()).toBe(true);
    });
});

describe('DashboardWidget — the grouping period of its own definition', () => {
    it('hands the bucket of an embedded definition to the chart and none for a report bound tile', () => {
        const adhoc = mountWidget({
            widget: meta({
                chart_type: 'bar',
                report_id: null,
                definition: {
                    object_type_id: '01OBJECTTYPE000000000001',
                    filter_definition: null,
                    aggregation_type: 'sum',
                    aggregation_field_key: 'amount',
                    group_by_field_key: 'closed_at',
                    group_by_bucket: 'week',
                    series_field_key: 'stage',
                },
            }),
            tile: tile({ chart_type: 'bar' }),
        });

        expect(adhoc.findComponent(ReportChart).props('bucket')).toBe('week');

        const bound = mountWidget({
            widget: meta({ chart_type: 'bar' }),
            tile: tile({ chart_type: 'bar' }),
        });

        expect(bound.findComponent(ReportChart).props('bucket')).toBeNull();
    });
});

describe('DashboardWidget — marking a tile that runs on foreign rights', () => {
    it('marks a definer bound tile', () => {
        const wrapper = mountWidget({
            tile: tile({ execution_mode: 'definer' }),
        });

        expect(wrapper.find('[data-widget-definer]').exists()).toBe(true);
    });

    it('leaves a tile that runs on the viewer rights unmarked', () => {
        const wrapper = mountWidget({
            tile: tile({ execution_mode: 'viewer' }),
        });

        expect(wrapper.find('[data-widget-definer]').exists()).toBe(false);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(true);
    });

    it('keeps the mark on a definer bound tile that could not be executed', () => {
        const wrapper = mountWidget({
            tile: noticeTile('source_not_visible', {
                execution_mode: 'definer',
            }),
        });

        expect(wrapper.find('[data-widget-definer]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-refusal]').exists()).toBe(true);
    });

    it('claims nothing while no result has arrived yet', () => {
        const wrapper = mountWidget({ tile: null });

        expect(wrapper.find('[data-widget-definer]').exists()).toBe(false);
        expect(wrapper.find('[data-widget-loading]').exists()).toBe(true);
    });
});

describe('DashboardWidget — it says what happened, it never fetches', () => {
    it('emits exactly one refresh carrying its own id and sends no request itself', async () => {
        const wrapper = mountWidget();

        await wrapper.get('[data-widget-refresh]').trigger('click');

        expect(wrapper.emitted('refresh')).toHaveLength(1);
        expect(wrapper.emitted('refresh')?.[0]).toEqual([WIDGET_ID]);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('hands a clicked chart segment up instead of navigating itself', () => {
        const selection: ReportDrillDownSelection = {
            group: 'v:won',
            series: null,
        };
        const wrapper = mountWidget({
            widget: meta({ chart_type: 'bar' }),
            tile: tile({ chart_type: 'bar' }),
        });

        wrapper.findComponent(ReportChart).vm.$emit('drill-down', selection);

        expect(wrapper.emitted('drill-down')).toHaveLength(1);
        expect(wrapper.emitted('drill-down')?.[0]).toEqual([
            { widgetId: WIDGET_ID, selection },
        ]);
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('stays silent when the tile carries no object type to drill into', () => {
        const selection: ReportDrillDownSelection = {
            group: 'v:won',
            series: null,
        };
        const wrapper = mountWidget({
            widget: meta({ chart_type: 'bar' }),
            tile: tile({ chart_type: 'bar', object_type: null }),
        });

        wrapper.findComponent(ReportChart).vm.$emit('drill-down', selection);

        expect(wrapper.emitted('drill-down')).toBeUndefined();
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('emits the edit, remove, span and move intents instead of acting on them', async () => {
        const wrapper = mountWidget();

        await wrapper.get('[data-widget-edit]').trigger('click');
        await wrapper.get('[data-widget-move-down]').trigger('click');
        await wrapper.get('[data-widget-remove]').trigger('click');
        await wrapper.get('[data-widget-span-select]').setValue('2');

        expect(wrapper.emitted('edit-widget')?.[0]).toEqual([WIDGET_ID]);
        expect(wrapper.emitted('move-by')?.[0]).toEqual([
            { widgetId: WIDGET_ID, offset: 1 },
        ]);
        expect(wrapper.emitted('remove-widget')?.[0]).toEqual([WIDGET_ID]);
        expect(wrapper.emitted('span-change')?.[0]).toEqual([
            { widgetId: WIDGET_ID, span: 2 },
        ]);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});

describe('DashboardWidget — a polite German notice instead of a number', () => {
    it('shows the German refusal and never the English server message', () => {
        const wrapper = mountWidget({
            tile: noticeTile('source_not_visible'),
        });

        const refusal = wrapper.get('[data-metric-refusal]').text();

        expect(refusal).toBe(resolveRefusalMessage('source_not_visible'));
        expect(wrapper.text()).not.toContain(NOTICE_MESSAGE);
        expect(wrapper.text()).not.toContain(FIELD_KEY);
        expect(wrapper.text()).not.toContain(OBJECT_TYPE_NAME);
        expect(refusal).not.toMatch(ENGLISH_WORDS);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);
    });

    it('tells a missing report apart from a source it may not read', () => {
        const missing = mountWidget({ tile: noticeTile('report_missing') });
        const invisible = mountWidget({
            tile: noticeTile('source_not_visible'),
        });

        expect(missing.get('[data-metric-refusal]').text()).toBe(
            resolveRefusalMessage('report_missing'),
        );
        expect(missing.get('[data-metric-refusal]').text()).not.toBe(
            invisible.get('[data-metric-refusal]').text(),
        );
    });
});

describe('DashboardWidget — refreshing stays available to a reader', () => {
    it('keeps the refresh button usable while every editing action is blocked', () => {
        const wrapper = mountWidget({
            canUpdate: false,
            updateReason: 'GESPERRT_TEXT',
        });

        expect(
            wrapper.get('[data-widget-refresh]').attributes('disabled'),
        ).toBeUndefined();
        expect(
            wrapper.get('[data-widget-edit]').attributes('disabled'),
        ).toBeDefined();
        expect(wrapper.get('[data-widget-edit]').attributes('title')).toBe(
            'GESPERRT_TEXT',
        );
        expect(
            wrapper.get('[data-widget-remove]').attributes('disabled'),
        ).toBeDefined();
    });
});

describe('DashboardWidget — the arrangement it renders', () => {
    it('carries its identity and its column span for the grid to register on', () => {
        const wrapper = mountWidget({
            widget: meta({ column_span: 3 }),
        });

        const root = wrapper.get(`[data-dashboard-widget="${WIDGET_ID}"]`);

        expect(root.attributes('data-widget-span')).toBe('3');
        expect(root.attributes('data-drop-target')).toBeUndefined();
    });

    it('marks itself as the drop target while a tile hovers over it', () => {
        const wrapper = mountWidget({ isDropTarget: true });

        expect(
            wrapper
                .get(`[data-dashboard-widget="${WIDGET_ID}"]`)
                .attributes('data-drop-target'),
        ).toBeDefined();
    });
});

function goal(overrides: Partial<GoalListRow> = {}): GoalListRow {
    return {
        id: '01GOAL00000000000000001A',
        name: 'Quartalsziel',
        report_id: '01REPORT0000000000000001',
        report: null,
        scope_type: 'user',
        target_user_id: '01USER00000000000000001A',
        target_team_id: null,
        target: null,
        includes_subteams: false,
        scope_field_key: 'owner_id',
        period_field_key: null,
        period_type: 'month',
        direction: 'at_least',
        target_value: '400.0000',
        periods: [
            {
                id: '01PERIOD000000000000001A',
                goal_id: '01GOAL00000000000000001A',
                period_start: '2026-08-01T00:00:00+00:00',
                period_end: '2026-09-01T00:00:00+00:00',
                current_value: '250.0000',
                calculated_at: '2026-08-10T11:00:00+00:00',
            },
        ],
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T11:00:00+00:00',
        ...overrides,
    };
}

describe('DashboardWidget — a tile that shows a goal', () => {
    it('draws the progress of the running period instead of a chart', () => {
        const wrapper = mountWidget({
            widget: meta({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                chart_type: null,
            }),
            tile: tile({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                goal: goal(),
                chart_type: null,
                result: null,
            }),
        });

        expect(wrapper.find('[data-goal-progress-body]').exists()).toBe(true);
        expect(wrapper.findComponent(ReportChart).exists()).toBe(false);
        expect(wrapper.get('[data-goal-value]').text()).toContain('250');
        expect(wrapper.get('[data-goal-value]').text()).toContain('400');
    });

    it('says in German that the figure is still pending when no period was calculated', () => {
        const wrapper = mountWidget({
            widget: meta({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                chart_type: null,
            }),
            tile: tile({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                goal: goal({ periods: [] }),
                chart_type: null,
                result: null,
            }),
        });

        expect(wrapper.find('[data-goal-progress-body]').exists()).toBe(true);
        expect(
            wrapper.get('[data-goal-pending]').text().length,
        ).toBeGreaterThan(20);
        expect(wrapper.find('[data-goal-progress]').exists()).toBe(false);
    });

    it('shows the refusal and no figures when the viewer may not see the goal', () => {
        const wrapper = mountWidget({
            widget: meta({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                chart_type: null,
            }),
            tile: tile({
                report_id: null,
                goal_id: '01GOAL00000000000000001A',
                goal: null,
                chart_type: null,
                result: null,
                notice: {
                    reason: 'goal_not_visible',
                    message: 'Sie dürfen dieses Ziel nicht ansehen.',
                },
            }),
        });

        expect(wrapper.find('[data-goal-progress-body]').exists()).toBe(false);
        expect(wrapper.findComponent(ReportChart).exists()).toBe(false);
        expect(wrapper.text()).not.toContain('250');
    });
});
