import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PropType } from 'vue';
import { defineComponent, h, nextTick, watch } from 'vue';
import ReportChart from '@/components/charts/ReportChart.vue';
import type {
    ReportChartType,
    ReportGroupRow,
    ReportResult,
} from '@/types/reports';

const chart = vi.hoisted(() => ({
    received: [] as Record<string, unknown>[],
    registerChartModules: vi.fn(),
    download: vi.fn(),
}));

const sonner = vi.hoisted(() => ({ error: vi.fn() }));

vi.mock('ag-charts-vue3', () => ({
    AgCharts: defineComponent({
        name: 'AgChartsStub',
        props: {
            options: {
                type: Object as PropType<Record<string, unknown>>,
                required: true,
            },
        },
        setup(props) {
            watch(
                () => props.options,
                (options) => {
                    chart.received.push(options);
                },
                { immediate: true },
            );

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
    toast: { error: sonner.error },
}));

const refusalReasons = [
    'malformed_definition',
    'unknown_field',
    'field_not_readable',
    'encrypted_field',
    'field_not_filterable',
    'unsupported_aggregation',
    'unsupported_grouping',
    'invalid_filter_tree',
] as const;

const structuralTerms = [
    /anonym/i,
    /\bfeld/i,
    /field/i,
    /stage/,
    /group_value/,
] as const;

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function row(overrides: Partial<ReportGroupRow> = {}): ReportGroupRow {
    return {
        group_value: 'won',
        series_value: null,
        value: '6',
        record_count: 6,
        discarded_value_count: 0,
        is_other_group: false,
        is_other_series: false,
        ...overrides,
    };
}

function result(overrides: Partial<ReportResult> = {}): ReportResult {
    return {
        aggregation: 'count',
        rows: [
            row({ group_value: 'won', value: '6' }),
            row({ group_value: 'lost', value: '3' }),
            row({ group_value: 'open', value: '1' }),
        ],
        total: '10',
        record_count: 10,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function mountChart(props: {
    title?: string;
    chartType?: ReportChartType;
    result?: ReportResult | null;
    refusal?: { reason: string } | null;
}): Wrapper {
    const wrapper = mount(ReportChart, {
        props: {
            title: 'Umsatz 2026',
            chartType: 'bar',
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

async function settle(): Promise<void> {
    await Promise.resolve();
    await nextTick();
    await nextTick();
}

function installDesignTokens(): void {
    const style = document.createElement('style');

    style.setAttribute('data-chart-tokens', '');
    style.textContent =
        ':root { --chart-1: #357DE8; --chart-2: #82B536;' +
        ' --chart-3: #BF63F3; --chart-4: #F68909; --chart-5: #1558BC;' +
        ' --chart-neutral: #8C8F97;' +
        ' --foreground: #292A2E; --card: #FFFFFF; }';
    document.head.appendChild(style);
}

function lastOptions(): Record<string, unknown> {
    const options = chart.received[chart.received.length - 1];

    if (!options) {
        throw new Error('the chart stub was never handed any options');
    }

    return options;
}

function asRecords(value: unknown): Record<string, unknown>[] {
    if (!Array.isArray(value)) {
        throw new Error('expected an array of plain objects');
    }

    return value.map((entry: unknown) => {
        if (entry === null || typeof entry !== 'object') {
            throw new Error('expected a plain object');
        }

        return entry as Record<string, unknown>;
    });
}

function downloadArgument(): Record<string, unknown> {
    const call: unknown = chart.download.mock.calls[0]?.[0];

    if (call === null || typeof call !== 'object') {
        throw new Error('the download was never handed any options');
    }

    return call as Record<string, unknown>;
}

beforeEach(() => {
    chart.received.length = 0;
    chart.registerChartModules.mockClear();
    chart.download.mockReset();
    chart.download.mockResolvedValue(undefined);
    sonner.error.mockClear();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }

    document
        .querySelectorAll('style[data-chart-tokens]')
        .forEach((style) => style.remove());
});

describe('ReportChart states', () => {
    it('shows a skeleton while neither result nor refusal has arrived', async () => {
        const wrapper = mountChart({ result: null, refusal: null });

        await settle();

        expect(wrapper.find('[data-chart-loading]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-download]').exists()).toBe(false);
    });

    it('explains a suppressed result without claiming there is no data', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [],
                total: null,
                record_count: 0,
                is_suppressed: true,
            }),
        });

        await settle();

        expect(wrapper.find('[data-chart-suppressed]').text()).toBe(
            'Für diese Auswertung liegen zu wenige Einträge je Gruppe vor, um sie darzustellen.',
        );
        expect(wrapper.find('[data-chart-empty]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
    });

    it('explains an empty result with a sentence instead of a blank canvas', async () => {
        const wrapper = mountChart({
            result: result({ rows: [], total: '0', record_count: 0 }),
        });

        await settle();

        expect(wrapper.find('[data-chart-empty]').text()).toBe(
            'Für diese Auswertung gibt es noch keine Datensätze.',
        );
        expect(wrapper.find('[data-chart-suppressed]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
    });

    it('explains a result whose values cannot be plotted at all', async () => {
        const wrapper = mountChart({
            result: result({
                aggregation: 'min',
                rows: [
                    row({
                        group_value: 'won',
                        value: '2026-08-10T09:15:00+02:00',
                    }),
                ],
                total: '2026-08-10T09:15:00+02:00',
            }),
        });

        await settle();

        expect(wrapper.find('[data-chart-unplottable]').text()).toBe(
            'Für diese Auswertung gibt es keine darstellbaren Werte.',
        );
        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
    });

    it('draws the chart once a plottable result is in', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();

        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-stub]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-empty]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-suppressed]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-unplottable]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-refusal]').exists()).toBe(false);
    });
});

describe('ReportChart refusals', () => {
    it('answers every refusal reason with its own German sentence', async () => {
        const texts: string[] = [];

        for (const reason of refusalReasons) {
            const wrapper = mountChart({
                result: null,
                refusal: { reason },
            });

            await settle();

            texts.push(wrapper.find('[data-chart-refusal]').text());
        }

        texts.forEach((text) => {
            expect(text.length).toBeGreaterThan(10);
        });

        expect(new Set(texts).size).toBe(refusalReasons.length);
    });

    it('names nothing about the structure behind the report', async () => {
        for (const reason of refusalReasons) {
            const wrapper = mountChart({
                result: null,
                refusal: { reason },
            });

            await settle();

            const text = wrapper.find('[data-chart-refusal]').text();

            structuralTerms.forEach((term) => {
                expect(text).not.toMatch(term);
            });
        }
    });

    it('renders the refusal instead of a figure even when a result is around', async () => {
        const wrapper = mountChart({
            result: result(),
            refusal: { reason: 'field_not_readable' },
        });

        await settle();

        expect(wrapper.find('[data-chart-refusal]').exists()).toBe(true);
        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-download]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('10');
    });
});

describe('ReportChart notices', () => {
    it('explains a collected group as a display rule', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [
                    row({ group_value: 'won', value: '6' }),
                    row({
                        group_value: null,
                        is_other_group: true,
                        value: '4',
                    }),
                ],
            }),
        });

        await settle();

        const notice = wrapper.find('[data-chart-other-notice]');

        expect(notice.exists()).toBe(true);
        expect(notice.text()).toContain('Sonstige');
        expect(notice.text()).toContain('zusammengefasst');
    });

    it('explains a collected series just as it explains a collected group', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [
                    row({
                        group_value: 'won',
                        series_value: '2026',
                        value: '6',
                    }),
                    row({
                        group_value: 'won',
                        series_value: null,
                        is_other_series: true,
                        value: '4',
                    }),
                ],
            }),
        });

        await settle();

        expect(wrapper.find('[data-chart-other-notice]').exists()).toBe(true);
    });

    it('stays silent about collected groups when a group value is merely absent', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [
                    row({ group_value: 'won', value: '6' }),
                    row({
                        group_value: null,
                        is_other_group: false,
                        value: '4',
                    }),
                ],
            }),
        });

        await settle();

        expect(wrapper.find('[data-chart-other-notice]').exists()).toBe(false);
    });

    it('reports discarded records in the plural', async () => {
        const wrapper = mountChart({
            result: result({ discarded_value_count: 3 }),
        });

        await settle();

        expect(wrapper.find('[data-chart-diagnostic]').text()).toBe(
            '3 Datensätze ohne auswertbaren Wert wurden nicht berücksichtigt.',
        );
    });

    it('reports a single discarded record in the singular', async () => {
        const wrapper = mountChart({
            result: result({ discarded_value_count: 1 }),
        });

        await settle();

        const text = wrapper.find('[data-chart-diagnostic]').text();

        expect(text).toContain('1 Datensatz ');
        expect(text).not.toContain('Datensätze');
    });

    it('stays silent when nothing was discarded', async () => {
        const wrapper = mountChart({
            result: result({ discarded_value_count: 0 }),
        });

        await settle();

        expect(wrapper.find('[data-chart-diagnostic]').exists()).toBe(false);
    });

    it('never spells out k-anonymity in any state', async () => {
        const cases = [
            { result: null, refusal: null },
            { result: null, refusal: { reason: 'field_not_readable' } },
            {
                result: result({
                    rows: [],
                    total: null,
                    record_count: 0,
                    is_suppressed: true,
                }),
            },
            { result: result({ rows: [], total: '0', record_count: 0 }) },
            {
                result: result({
                    rows: [
                        row({ group_value: 'won', value: '6' }),
                        row({
                            group_value: null,
                            is_other_group: true,
                            value: '4',
                        }),
                    ],
                }),
            },
        ];

        for (const props of cases) {
            const wrapper = mountChart(props);

            await settle();

            expect(wrapper.text()).not.toMatch(/anonym/i);
        }
    });
});

describe('ReportChart accessibility', () => {
    it('describes the chart for screen readers next to the canvas', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();

        const summary = wrapper.find('[data-chart-summary]');

        expect(summary.exists()).toBe(true);
        expect(summary.classes()).toContain('sr-only');
        expect(summary.text()).toContain('Umsatz 2026');
        expect(summary.text()).toContain('Anzahl');
        expect(summary.text()).toMatch(/\b3\b/);
    });

    it('hides nothing of the canvas from the accessibility tree', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();

        expect(
            wrapper.find('[data-chart-canvas]').attributes('aria-hidden'),
        ).toBeUndefined();
    });
});

describe('ReportChart image download', () => {
    it('asks the chart instance for a PNG with a speaking file name', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();
        await wrapper.find('[data-chart-download]').trigger('click');
        await flushPromises();

        const fileName = downloadArgument().fileName;

        expect(chart.download).toHaveBeenCalledTimes(1);
        expect(typeof fileName).toBe('string');
        expect(String(fileName)).toContain('Umsatz 2026');
        expect(String(fileName)).toContain('2026-08-10');
        expect(String(fileName).endsWith('.png')).toBe(true);
    });

    it('pins the requested image format to png so the extension cannot lie', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();
        await wrapper.find('[data-chart-download]').trigger('click');
        await flushPromises();

        expect(downloadArgument().fileFormat).toBe('png');
    });

    it('blocks a second click while the first download is still running', async () => {
        let release: () => void = () => undefined;

        chart.download.mockReturnValue(
            new Promise<void>((resolve) => {
                release = () => resolve();
            }),
        );

        const wrapper = mountChart({ result: result() });

        await settle();
        await wrapper.find('[data-chart-download]').trigger('click');
        await settle();

        expect(
            wrapper.find('[data-chart-download]').attributes('disabled'),
        ).toBeDefined();

        release();
        await flushPromises();

        expect(chart.download).toHaveBeenCalledTimes(1);
    });

    it('reports a failed download as a toast and lets the user try again', async () => {
        chart.download.mockRejectedValue(new Error('canvas is unavailable'));

        const wrapper = mountChart({ result: result() });

        await settle();
        await wrapper.find('[data-chart-download]').trigger('click');
        await flushPromises();

        expect(sonner.error).toHaveBeenCalledTimes(1);
        expect(
            wrapper.find('[data-chart-download]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('offers no download button while the chart is not drawn', async () => {
        const cases = [
            { result: null, refusal: null },
            { result: null, refusal: { reason: 'unknown_field' } },
            {
                result: result({
                    rows: [],
                    total: null,
                    record_count: 0,
                    is_suppressed: true,
                }),
            },
            { result: result({ rows: [], total: '0', record_count: 0 }) },
            {
                result: result({
                    rows: [row({ group_value: 'won', value: null })],
                }),
            },
        ];

        for (const props of cases) {
            const wrapper = mountChart(props);

            await settle();

            expect(wrapper.find('[data-chart-download]').exists()).toBe(false);
        }
    });
});

describe('ReportChart palette', () => {
    it('leaves the collected slice on the neutral grey of the token palette', async () => {
        installDesignTokens();

        const wrapper = mountChart({
            chartType: 'pie',
            result: result({
                rows: [
                    row({
                        group_value: null,
                        is_other_group: true,
                        value: '6',
                    }),
                    row({ group_value: 'a', value: '5' }),
                    row({ group_value: 'b', value: '4' }),
                    row({ group_value: 'c', value: '3' }),
                    row({ group_value: 'd', value: '2' }),
                    row({ group_value: 'e', value: '1' }),
                ],
            }),
        });

        await settle();

        const options = lastOptions();
        const theme = options.theme;

        if (theme === null || typeof theme !== 'object') {
            throw new Error('the chart options carry no theme object');
        }

        const fills = (theme as { palette?: { fills?: string[] } }).palette
            ?.fills;
        const data = asRecords(options.data);

        expect(wrapper.find('[data-chart-stub]').exists()).toBe(true);
        expect(fills?.[5]).toBe('#8C8F97');
        expect(data).toHaveLength(6);
        expect(data[5].category).toBe('Sonstige');
        expect(data[5].value).toBe(6);
    });
});

function chartClickListener(): (event: Record<string, unknown>) => void {
    const listeners = lastOptions().listeners;

    if (listeners === null || typeof listeners !== 'object') {
        throw new Error('the chart options carry no listeners object');
    }

    const handler = (listeners as Record<string, unknown>).seriesNodeClick;

    if (typeof handler !== 'function') {
        throw new Error('the chart options carry no seriesNodeClick listener');
    }

    return handler as (event: Record<string, unknown>) => void;
}

function chartData(): Record<string, unknown>[] {
    return asRecords(lastOptions().data);
}

describe('ReportChart drill-down', () => {
    it('emits the clicked group once and leaves the navigation to the embedder', async () => {
        const wrapper = mountChart({ result: result() });

        await settle();

        chartClickListener()({ datum: chartData()[0], yKey: 'value' });
        await nextTick();

        expect(wrapper.emitted('drill-down')).toHaveLength(1);
        expect(wrapper.emitted('drill-down')?.[0]).toEqual([
            { group: 'v:won', series: null },
        ]);
    });

    it('carries the clicked series along with the group', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [
                    row({
                        group_value: 'Nord',
                        series_value: '2026',
                        value: '10',
                    }),
                    row({
                        group_value: 'Nord',
                        series_value: '2027',
                        value: '12',
                    }),
                ],
            }),
        });

        await settle();

        chartClickListener()({
            datum: chartData()[0],
            yKey: 'series:v:2027',
        });
        await nextTick();

        expect(wrapper.emitted('drill-down')).toHaveLength(1);
        expect(wrapper.emitted('drill-down')?.[0]).toEqual([
            { group: 'v:Nord', series: 'v:2027' },
        ]);
    });

    it('offers no drill-down on the collected bucket', async () => {
        const wrapper = mountChart({
            result: result({
                rows: [
                    row({ group_value: 'won', value: '6' }),
                    row({
                        group_value: null,
                        is_other_group: true,
                        value: '4',
                    }),
                ],
            }),
        });

        await settle();

        chartClickListener()({ datum: chartData()[1], yKey: 'value' });
        await nextTick();

        expect(wrapper.emitted('drill-down')).toBeUndefined();
        expect(wrapper.find('[data-chart-other-notice]').exists()).toBe(true);
    });

    it('draws no canvas at all in every state that carries nothing to click', async () => {
        const cases = [
            { result: null, refusal: null },
            { result: null, refusal: { reason: 'field_not_readable' } },
            {
                result: result({
                    rows: [],
                    total: null,
                    record_count: 0,
                    is_suppressed: true,
                }),
            },
            { result: result({ rows: [], total: '0', record_count: 0 }) },
            {
                result: result({
                    rows: [row({ group_value: 'won', value: null })],
                }),
            },
        ];

        for (const props of cases) {
            const wrapper = mountChart(props);

            await settle();

            expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
            expect(wrapper.emitted('drill-down')).toBeUndefined();
        }

        expect(chart.received).toHaveLength(0);
    });
});
