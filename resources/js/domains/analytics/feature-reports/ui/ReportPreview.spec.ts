import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { defineComponent, h } from 'vue';
import { preview } from '@/routes/reports';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { ReportResult } from '../../data/types/reports';
import ReportPreview from './ReportPreview.vue';

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

const ENGLISH_MESSAGE = 'Grouping by a time span requires a date or a timestamp.';

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

function serveResult(value: ReportResult = result(), options: { held?: boolean; once?: boolean } = {}): ServedRoute {
  return serveJson(preview(), { data: value }, options);
}

function serveRefusal(options: { once?: boolean } = {}): ServedRoute {
  return serveJson(
    preview(),
    { message: ENGLISH_MESSAGE, reason: 'unsupported_grouping' },
    { ...options, status: 422 },
  );
}

function mountPreview(props: Record<string, unknown> = {}, stubs: Record<string, Component> = {}): VueWrapper {
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

async function settled(wrapper: VueWrapper): Promise<void> {
  await vi.waitFor(() => expect(wrapper.get('[data-report-preview-refresh]').attributes('disabled')).toBeUndefined());
  await flushPromises();
}

async function refresh(wrapper: VueWrapper): Promise<void> {
  await wrapper.get('[data-report-preview-refresh]').trigger('click');
  await settled(wrapper);
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=preview-block-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
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
    expect(wrapper.get('[data-report-preview-idle]').text().length).toBeGreaterThan(10);
  });

  it('runs nothing before the actor asks for it', async () => {
    const served = serveResult();

    mountPreview({}, chartStubs());
    await flushPromises();

    expect(served.requests).toHaveLength(0);
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
    const served = serveResult(result(), { held: true });

    const wrapper = mountPreview({ presentation: 'metric' });

    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(false);
    expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(preview.url());
    expect(served.lastRequest()?.body).toEqual(PAYLOAD);
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('preview-block-token');

    served.release();
    await settled(wrapper);

    expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
    expect(wrapper.get('[data-metric-value]').text()).toBe('6');
  });

  it('drops the settled number back to the skeleton on every further run', async () => {
    const second = serveResult(result({ record_count: 2, total: '2' }), {
      held: true,
    });
    serveResult(result(), { once: true });

    const wrapper = mountPreview({ presentation: 'metric' });

    await refresh(wrapper);

    expect(wrapper.get('[data-metric-value]').text()).toBe('6');

    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    await vi.waitFor(() => expect(second.requests).toHaveLength(1));

    expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
    expect(wrapper.find('[data-metric-value]').exists()).toBe(false);

    second.release();
    await settled(wrapper);

    expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
    expect(wrapper.get('[data-metric-value]').text()).toBe('2');
  });

  it('drops a settled refusal back to the skeleton on every further run', async () => {
    const second = serveResult(result(), { held: true });
    serveRefusal({ once: true });

    const wrapper = mountPreview({ presentation: 'metric' });

    await refresh(wrapper);

    expect(wrapper.find('[data-metric-refusal]').exists()).toBe(true);

    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    await vi.waitFor(() => expect(second.requests).toHaveLength(1));

    expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
    expect(wrapper.find('[data-metric-refusal]').exists()).toBe(false);

    second.release();
    await settled(wrapper);

    expect(wrapper.get('[data-metric-value]').text()).toBe('6');
  });

  it('keeps the refresh button disabled while a run is in flight', async () => {
    const served = serveResult(result(), { held: true });

    const wrapper = mountPreview({}, chartStubs());

    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(wrapper.get('[data-report-preview-refresh]').attributes('disabled')).toBeDefined();

    await wrapper.get('[data-report-preview-refresh]').trigger('click');
    served.release();
    await settled(wrapper);

    expect(served.requests).toHaveLength(1);
    expect(wrapper.get('[data-report-preview-refresh]').attributes('disabled')).toBeUndefined();
  });
});

describe('ReportPreview — handing the result on', () => {
  it('hands a metric report to the metric tile and never to the chart', async () => {
    serveResult();

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
    serveResult();

    const wrapper = mountPreview({ presentation: 'area', bucket: 'month' }, chartStubs());

    await refresh(wrapper);

    expect(wrapper.findComponent(MetricTileStub).exists()).toBe(false);

    const chart = wrapper.findComponent(ReportChartStub);

    expect(chart.exists()).toBe(true);
    expect(chart.props('chartType')).toBe('area');
    expect(chart.props('bucket')).toBe('month');
    expect(chart.props('barMode')).toBe('grouped');
    expect(chart.props('result')).toEqual(result());
  });
});

describe('ReportPreview — invalidation', () => {
  it('falls back to the resting state once the definition changes after a run', async () => {
    serveResult();

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
    serveResult();

    const wrapper = mountPreview({ presentation: 'metric' }, chartStubs());

    await refresh(wrapper);

    expect(wrapper.findComponent(MetricTileStub).exists()).toBe(true);

    await wrapper.setProps({ presentation: 'bar' });
    await flushPromises();

    expect(wrapper.find('[data-report-preview-idle]').exists()).toBe(true);
    expect(wrapper.findComponent(ReportChartStub).exists()).toBe(false);
  });

  it('keeps the result while the definition stays untouched', async () => {
    serveResult();

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
    serveRefusal();

    const wrapper = mountPreview({ presentation: 'metric' });

    await refresh(wrapper);

    const refusal = wrapper.get('[data-metric-refusal]').text();

    expect(refusal.length).toBeGreaterThan(10);
    expect(refusal).not.toContain(ENGLISH_MESSAGE);
    expect(refusal).toContain('Zeitraum');
    expect(wrapper.find('[data-metric-loading]').exists()).toBe(false);
  });

  it('hands the raw reason key on so the chart resolves the copy itself', async () => {
    serveRefusal();

    const wrapper = mountPreview({ presentation: 'bar' }, chartStubs());

    await refresh(wrapper);

    expect(wrapper.findComponent(ReportChartStub).props('refusal')).toEqual({ reason: 'unsupported_grouping' });
    expect(wrapper.findComponent(ReportChartStub).props('result')).toBeNull();
  });

  it('turns a forbidden answer, a server fault and a broken connection into the same polite fallback rather than a stuck skeleton', async () => {
    serveJson(preview(), { message: 'no' }, { status: 403, once: true });

    const forbidden = mountPreview({ presentation: 'metric' });
    await refresh(forbidden);

    const forbiddenText = forbidden.get('[data-metric-refusal]').text();

    expect(forbidden.find('[data-metric-loading]').exists()).toBe(false);
    expect(forbiddenText.length).toBeGreaterThan(10);

    serveServerError(preview(), { message: 'boom' }, { once: true });

    const faulty = mountPreview({ presentation: 'metric' });
    await refresh(faulty);

    serveNetworkError(preview(), { once: true });

    const offline = mountPreview({ presentation: 'metric' });
    await refresh(offline);

    expect(faulty.get('[data-metric-refusal]').text()).toBe(forbiddenText);
    expect(offline.get('[data-metric-refusal]').text()).toBe(forbiddenText);
    expect(sonner.error).not.toHaveBeenCalled();
    expect(sonner.success).not.toHaveBeenCalled();
  });
});

const DRILL_DOWN_SOURCE = {
  reportId: '01REPORT0000000000000001',
  objectTypeSlug: 'companies',
};

async function emitDrillDown(wrapper: VueWrapper): Promise<void> {
  wrapper.findComponent(ReportChartStub).vm.$emit('drill-down', { group: 'v:won', series: null });

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
    serveResult();

    const wrapper = mountPreview({ presentation: 'bar', drillDownSource: DRILL_DOWN_SOURCE }, chartStubs());

    await refresh(wrapper);
    await emitDrillDown(wrapper);

    expect(inertia.visit).toHaveBeenCalledTimes(1);

    const target = String(inertia.visit.mock.calls[0][0]);

    expect(target).toContain('/nubos/records/companies');
    expect(target).toContain(`report=${DRILL_DOWN_SOURCE.reportId}`);
    expect(target).toContain('group=v%3Awon');
  });

  it('stays put when the embedder names no report to drill into', async () => {
    serveResult();

    const wrapper = mountPreview({ presentation: 'bar' }, chartStubs());

    await refresh(wrapper);
    await emitDrillDown(wrapper);

    expect(inertia.visit).not.toHaveBeenCalled();
  });
});
