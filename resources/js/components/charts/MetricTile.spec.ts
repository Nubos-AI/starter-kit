import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import MetricTile from '@/components/charts/MetricTile.vue';
import type { ReportGroupRow, ReportResult } from '@/types/reports';

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function row(overrides: Partial<ReportGroupRow> = {}): ReportGroupRow {
    return {
        group_value: null,
        series_value: null,
        value: '1234',
        record_count: 10,
        discarded_value_count: 0,
        is_other_group: false,
        is_other_series: false,
        ...overrides,
    };
}

function result(overrides: Partial<ReportResult> = {}): ReportResult {
    return {
        aggregation: 'count',
        rows: [row()],
        total: '1234',
        record_count: 10,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function mountTile(props: {
    title?: string;
    result?: ReportResult | null;
    refusal?: { reason: string } | null;
}): Wrapper {
    const wrapper = mount(MetricTile, {
        props: {
            title: 'Offene Vorgänge',
            ...props,
        },
    });

    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('MetricTile figure', () => {
    it('shows the label and the total of the result', () => {
        const wrapper = mountTile({ result: result() });

        expect(wrapper.find('[data-slot="card-title"]').text()).toBe(
            'Offene Vorgänge',
        );
        expect(wrapper.find('[data-metric-value]').text()).toBe('1.234');
    });

    it('keeps the scale a summed field brought with it', () => {
        const wrapper = mountTile({
            result: result({ aggregation: 'sum', total: '1234.5678' }),
        });

        expect(wrapper.find('[data-metric-value]').text()).toBe('1.234,5678');
    });

    it('renders a minimum over a date field as a date and never as NaN', () => {
        const wrapper = mountTile({
            result: result({
                aggregation: 'min',
                total: '2026-08-10T09:15:00+02:00',
            }),
        });
        const value = wrapper.find('[data-metric-value]').text();

        expect(value).toMatch(/^\d{1,2}\.\d{1,2}\.\d{4}/);
        expect(value).not.toContain('NaN');
    });

    it('is built from the card kit', () => {
        const wrapper = mountTile({ result: result() });

        expect(wrapper.find('[data-slot="card"]').exists()).toBe(true);
        expect(wrapper.find('[data-slot="card-header"]').exists()).toBe(true);
        expect(wrapper.find('[data-slot="card-title"]').exists()).toBe(true);
        expect(wrapper.find('[data-slot="card-content"]').exists()).toBe(true);
    });

    it('carries neither a chart instance nor a download button', () => {
        const wrapper = mountTile({ result: result() });

        expect(wrapper.find('[data-chart-canvas]').exists()).toBe(false);
        expect(wrapper.find('[data-chart-stub]').exists()).toBe(false);
        expect(wrapper.find('canvas').exists()).toBe(false);
        expect(wrapper.find('[data-chart-download]').exists()).toBe(false);
    });

    it('compares nothing against a previous period', () => {
        const wrapper = mountTile({ result: result() });

        expect(wrapper.find('[data-metric-delta]').exists()).toBe(false);
        expect(wrapper.text()).not.toMatch(/vorperiode|vergleich|%/i);
    });
});

describe('MetricTile states', () => {
    it('shows a skeleton while neither result nor refusal has arrived', () => {
        const wrapper = mountTile({ result: null, refusal: null });

        expect(wrapper.find('[data-metric-loading]').exists()).toBe(true);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);
    });

    it('renders a polite hint instead of a figure when the report is refused', () => {
        const wrapper = mountTile({
            result: result(),
            refusal: { reason: 'field_not_readable' },
        });
        const hint = wrapper.find('[data-metric-refusal]');

        expect(hint.exists()).toBe(true);
        expect(hint.text().length).toBeGreaterThan(10);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('1.234');
        expect(hint.text()).not.toMatch(/\bfeld/i);
        expect(hint.text()).not.toMatch(/field/i);
    });

    it('explains a suppressed result without claiming there is no data', () => {
        const wrapper = mountTile({
            result: result({
                rows: [],
                total: null,
                record_count: 0,
                is_suppressed: true,
            }),
        });

        expect(wrapper.find('[data-metric-suppressed]').text()).toBe(
            'Für diese Auswertung liegen zu wenige Einträge je Gruppe vor, um sie darzustellen.',
        );
        expect(wrapper.find('[data-metric-empty]').exists()).toBe(false);
        expect(wrapper.find('[data-metric-value]').exists()).toBe(false);
    });

    it('explains an empty result with a sentence', () => {
        const wrapper = mountTile({
            result: result({ rows: [], total: '0', record_count: 0 }),
        });

        expect(wrapper.find('[data-metric-empty]').text()).toBe(
            'Für diese Auswertung gibt es noch keine Datensätze.',
        );
        expect(wrapper.find('[data-metric-suppressed]').exists()).toBe(false);
    });

    it('reports discarded records next to the figure', () => {
        const wrapper = mountTile({
            result: result({ discarded_value_count: 3 }),
        });

        expect(wrapper.find('[data-metric-diagnostic]').text()).toBe(
            '3 Datensätze ohne auswertbaren Wert wurden nicht berücksichtigt.',
        );
    });

    it('stays silent when nothing was discarded', () => {
        const wrapper = mountTile({ result: result() });

        expect(wrapper.find('[data-metric-diagnostic]').exists()).toBe(false);
    });

    it('never spells out k-anonymity in any state', () => {
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
            { result: result() },
        ];

        cases.forEach((props) => {
            expect(mountTile(props).text()).not.toMatch(/anonym/i);
        });
    });
});
