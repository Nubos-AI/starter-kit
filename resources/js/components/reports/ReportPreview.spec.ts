import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { defineComponent, h } from 'vue';
import ReportPreview from '@/components/reports/ReportPreview.vue';
import type { ReportResult } from '@/types/reports';
import { setUrlDefaults } from '@/wayfinder';

const sonner = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
}));

vi.mock('vue-sonner', () => ({ toast: sonner }));

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    on: vi.fn(() => vi.fn()),
}));

vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    usePage: () => ({
        url: '/nubos/reports/01REPORT0000000000000001/edit',
        props: {},
    }),
}));

const ReportChartStub = defineComponent({
    name: 'ReportChartStub',
    props: {
        title: { type: String, default: '' },
        chartType: { type: String, default: '' },
        result: { type: Object, default: null },
        refusal: { type: Object, default: null },
        barMode: { type: String, default: '' },
        bucket: { type: String, default: null },
    },
    emits: ['drill-down'],
    setup: () => () => h('div', { 'data-report-chart-stub': true }),
});

const MetricTileStub = defineComponent({
    name: 'MetricTileStub',
    props: {
        title: { type: String, default: '' },
        result: { type: Object, default: null },
        refusal: { type: Object, default: null },
    },
    setup: () => () => h('div', { 'data-metric-tile-stub': true }),
});

const ENGLISH_MESSAGE =
    'Grouping by a time span requires a date or a timestamp.';

type FetchHandler = (input: string, init: RequestInit) => Promise<Response>;

type FetchMock = ReturnType<typeof stubFetch>;

function stubFetch(handler: FetchHandler) {
    const mock = vi.fn(handler);

    vi.stubGlobal('fetch', mock);

    return mock;
}

const PAYLOAD: Record<string, unknown> = {
    object_type_id: '01OBJECTTYPE000000000001',
    filter_definition: null,
    aggregation_type: 'count',
    aggregation_field_key: null,
    group_by_field_key: 'stage',
    group_by_bucket: 'month',
    series_field_key: null,
};

function result(overrides: Partial<ReportResult> = {}): ReportResult {
    return {
        aggregation: 'count',
        rows: [
            {
                group_value: 'won',
                series_value: null,
                value: '6',
                record_count: 6,
                discarded_value_count: 0,
                is_other_group: false,
                is_other_series: false,
            },
        ],
        total: '6',
        record_count: 6,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function mountPreview(
    props: Record<string, unknown> = {},
    stubs: Record<string, Component> = {},
): VueWrapper {
    return mount(ReportPreview, {
        props: {
            title: 'Pipeline',
            presentation: 'metric',
            bucket: null,
            payload: () => PAYLOAD,
            ...props,
        },
        global: { stubs },
    });
}

function chartStubs(): Record<string, Component> {
    return { ReportChart: ReportChartStub, MetricTile: MetricTileStub };
}

async function refresh(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    await flushPromises();
}

function requestInit(fetchMock: FetchMock): RequestInit {
    const init = fetchMock.mock.calls[0]?.[1];

    if (init === null || init === undefined || typeof init !== 'object') {
        throw new Error('The preview request carries no request init');
    }

    return init;
}

function sentBody(fetchMock: FetchMock): unknown {
    const body = requestInit(fetchMock).body;

    if (typeof body !== 'string') {
        throw new Error('The preview request carries no JSON body');
    }

    return JSON.parse(body);
}

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=preview-block-token';
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('ReportPreview — resting state', () => {
    it('starts with an explanatory hint and never with a skeleton', () => {
        const wrapper = mountPreview({}, chartStubs());

        expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-loading]').exists()).toBe(false);
        expect(wrapper.findComponent(MetricTileStub).exists()).toBe(false);
        expect(wrapper.findComponent(ReportChartStub).exists()).toBe(false);
        expect(
            wrapper.get('[data-report-preview-idle]').text().length,
        ).toBeGreaterThan(10);
    });

    it('runs nothing before the actor asks for it', () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(200, { data: result() })),
        );

        mountPreview({}, chartStubs());

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('says in German that the preview shows the actor their own view', () => {
        const wrapper = mountPreview({}, chartStubs());

        const scope = wrapper.get('[data-report-preview-scope]').text();

        expect(scope.length).toBeGreaterThan(10);
        expect(scope).not.toMatch(/\bthe\b/i);
    });
});

describe('ReportPreview — loading', () => {
    it('shows the skeleton of the metric tile while loading and drops it once settled', async () => {
        let settle: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            settle = resolve;
        });
        stubFetch(() => pending);

        const wrapper = mountPreview({ presentation: 'metric' });

        await wrapper.get('[data-report-preview-refresh]').trigger('click');

        expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(false);
        expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);

        settle(jsonResponse(200, { data: result() }));
        await flushPromises();

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
        expect(wrapper.get('[data-metric-value]').text()).toBe('6');
    });

    it('drops the settled number back to the skeleton on every further run', async () => {
        let settle: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            settle = resolve;
        });
        let call = 0;

        stubFetch(() => {
            call += 1;

            return call === 1
                ? Promise.resolve(jsonResponse(200, { data: result() }))
                : pending;
        });

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        expect(wrapper.get('[data-metric-value]').text()).toBe('6');

        await wrapper.get('[data-report-preview-refresh]').trigger('click');

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);

        settle(
            jsonResponse(200, {
                data: result({ record_count: 2, total: '2' }),
            }),
        );
        await flushPromises();

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
        expect(wrapper.get('[data-metric-value]').text()).toBe('2');
    });

    it('drops a settled refusal back to the skeleton on every further run', async () => {
        let settle: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            settle = resolve;
        });
        let call = 0;

        stubFetch(() => {
            call += 1;

            return call === 1
                ? Promise.resolve(
                      jsonResponse(422, {
                          message: ENGLISH_MESSAGE,
                          reason: 'unsupported_grouping',
                      }),
                  )
                : pending;
        });

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        expect(wrapper.find('[data-metric-refusal]').exists()).toBe(true);

        await wrapper.get('[data-report-preview-refresh]').trigger('click');

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-refusal]').exists()).toBe(false);

        settle(jsonResponse(200, { data: result() }));
        await flushPromises();

        expect(wrapper.get('[data-metric-value]').text()).toBe('6');
    });

    it('keeps the refresh button disabled while a run is in flight', async () => {
        let settle: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            settle = resolve;
        });
        stubFetch(() => pending);

        const wrapper = mountPreview({}, chartStubs());

        await wrapper.get('[data-report-preview-refresh]').trigger('click');

        expect(
            wrapper.get('[data-report-preview-refresh]').attributes('disabled'),
        ).toBeDefined();

        settle(jsonResponse(200, { data: result() }));
        await flushPromises();

        expect(
            wrapper.get('[data-report-preview-refresh]').attributes('disabled'),
        ).toBeUndefined();
    });
});

describe('ReportPreview — handing the result on', () => {
    it('hands a metric report to the metric tile and never to the chart', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview({ presentation: 'metric' }, chartStubs());

        await refresh(wrapper);

        expect(wrapper.findComponent(ReportChartStub).exists()).toBe(false);

        const tile = wrapper.findComponent(MetricTileStub);

        expect(tile.exists()).toBe(true);
        expect(tile.props('title')).toBe('Pipeline');
        expect(tile.props('result')).toEqual(result());
        expect(tile.props('refusal')).toBeNull();
    });

    it('hands every other presentation to the chart together with the bucket', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview(
            { presentation: 'area', bucket: 'month' },
            chartStubs(),
        );

        await refresh(wrapper);

        expect(wrapper.findComponent(MetricTileStub).exists()).toBe(false);

        const chart = wrapper.findComponent(ReportChartStub);

        expect(chart.exists()).toBe(true);
        expect(chart.props('chartType')).toBe('area');
        expect(chart.props('bucket')).toBe('month');
        expect(chart.props('barMode')).toBe('grouped');
        expect(chart.props('result')).toEqual(result());
    });

    it('never sends a report id along with the edited definition', async () => {
        const fetchMock = stubFetch(() =>
            Promise.resolve(jsonResponse(200, { data: result() })),
        );

        const wrapper = mountPreview({}, chartStubs());

        await refresh(wrapper);

        const body = sentBody(fetchMock);

        expect(body).toEqual(PAYLOAD);
        expect(Object.keys(body ?? {})).not.toContain('report_id');
    });
});

describe('ReportPreview — invalidation', () => {
    it('falls back to the resting state once the definition changes after a run', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        expect(wrapper.get('[data-metric-value]').text()).toBe('6');

        await wrapper.setProps({
            payload: () => ({ ...PAYLOAD, group_by_field_key: 'owner_id' }),
        });
        await flushPromises();

        expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);
        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
    });

    it('falls back to the resting state once the presentation changes after a run', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview({ presentation: 'metric' }, chartStubs());

        await refresh(wrapper);

        expect(wrapper.findComponent(MetricTileStub).exists()).toBe(true);

        await wrapper.setProps({ presentation: 'bar' });
        await flushPromises();

        expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(true);
        expect(wrapper.findComponent(ReportChartStub).exists()).toBe(false);
    });

    it('keeps the result while the definition stays untouched', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        await wrapper.setProps({ title: 'Pipeline 2026' });
        await flushPromises();

        expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(false);
        expect(wrapper.get('[data-metric-value]').text()).toBe('6');
    });
});

describe('ReportPreview — refusals', () => {
    it('renders the German refusal of a 422 and never the English server message', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(422, {
                    message: ENGLISH_MESSAGE,
                    reason: 'unsupported_grouping',
                }),
            ),
        );

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        const refusal = wrapper.get('[data-metric-refusal]').text();

        expect(refusal.length).toBeGreaterThan(10);
        expect(refusal).not.toContain(ENGLISH_MESSAGE);
        expect(refusal).toContain('Zeitraum');
        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
    });

    it('hands the raw reason key on so the chart resolves the copy itself', async () => {
        stubFetch(() =>
            Promise.resolve(
                jsonResponse(422, {
                    message: ENGLISH_MESSAGE,
                    reason: 'unsupported_grouping',
                }),
            ),
        );

        const wrapper = mountPreview({ presentation: 'bar' }, chartStubs());

        await refresh(wrapper);

        expect(wrapper.findComponent(ReportChartStub).props('refusal')).toEqual(
            { reason: 'unsupported_grouping' },
        );
        expect(
            wrapper.findComponent(ReportChartStub).props('result'),
        ).toBeNull();
    });

    it('turns a forbidden answer into the polite fallback rather than a stuck skeleton', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(403, { message: 'no' })));

        const wrapper = mountPreview({ presentation: 'metric' });

        await refresh(wrapper);

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
        expect(
            wrapper.get('[data-metric-refusal]').text().length,
        ).toBeGreaterThan(10);
        expect(sonner.error).not.toHaveBeenCalled();
        expect(sonner.success).not.toHaveBeenCalled();
    });

    it('turns a server fault and a broken connection into the same polite fallback', async () => {
        stubFetch(() =>
            Promise.resolve(jsonResponse(500, { message: 'boom' })),
        );

        const faulty = mountPreview({ presentation: 'metric' });
        await refresh(faulty);

        const faultText = faulty.get('[data-metric-refusal]').text();

        stubFetch(() => Promise.reject(new TypeError('Failed to fetch')));

        const offline = mountPreview({ presentation: 'metric' });
        await refresh(offline);

        expect(offline.get('[data-metric-refusal]').text()).toBe(faultText);
        expect(sonner.error).not.toHaveBeenCalled();
    });
});

describe('ReportPreview — teardown', () => {
    it('aborts an in flight run when the editor is left mid request', async () => {
        let settle: (value: Response) => void = () => undefined;
        const pending = new Promise<Response>((resolve) => {
            settle = resolve;
        });
        const fetchMock = stubFetch(() => pending);

        const wrapper = mountPreview({}, chartStubs());

        await wrapper.get('[data-report-preview-refresh]').trigger('click');

        wrapper.unmount();

        expect(requestInit(fetchMock).signal?.aborted).toBe(true);

        settle(jsonResponse(200, { data: result() }));
        await flushPromises();

        expect(sonner.error).not.toHaveBeenCalled();
    });
});

const DRILL_DOWN_SOURCE = {
    reportId: '01REPORT0000000000000001',
    objectTypeSlug: 'companies',
};

async function emitDrillDown(wrapper: VueWrapper): Promise<void> {
    wrapper
        .findComponent(ReportChartStub)
        .vm.$emit('drill-down', { group: 'v:won', series: null });

    await flushPromises();
}

describe('ReportPreview — drilling into the record list', () => {
    beforeEach(() => {
        setUrlDefaults({ activeTeam: 'nubos' });
    });

    afterEach(() => {
        setUrlDefaults({});
    });

    it('turns a clicked chart segment into a visit to the record list of the object type', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview(
            { presentation: 'bar', drillDownSource: DRILL_DOWN_SOURCE },
            chartStubs(),
        );

        await refresh(wrapper);
        await emitDrillDown(wrapper);

        expect(inertia.visit).toHaveBeenCalledTimes(1);

        const target = String(inertia.visit.mock.calls[0][0]);

        expect(target).toContain('/nubos/records/companies');
        expect(target).toContain(`report=${DRILL_DOWN_SOURCE.reportId}`);
        expect(target).toContain('group=v%3Awon');
    });

    it('stays put when the embedder names no report to drill into', async () => {
        stubFetch(() => Promise.resolve(jsonResponse(200, { data: result() })));

        const wrapper = mountPreview({ presentation: 'bar' }, chartStubs());

        await refresh(wrapper);
        await emitDrillDown(wrapper);

        expect(inertia.visit).not.toHaveBeenCalled();
    });
});
