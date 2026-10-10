import { describe, expect, it } from 'vitest';
import { isReactive, reactive, ref } from 'vue';
import type { ReportChartInput } from '@/composables/useReportChartOptions';
import { useReportChartOptions } from '@/composables/useReportChartOptions';
import type {
    ReportChartType,
    ReportDrillDownSelection,
    ReportGroupRow,
    ReportResult,
} from '@/types/reports';

const chartTypes = ['bar', 'line', 'area', 'pie', 'donut'] as const;
const cartesianTypes = ['bar', 'line', 'area'] as const;

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

function result(rows: ReportGroupRow[], total = '10'): ReportResult {
    return {
        aggregation: 'count',
        rows,
        total,
        record_count: 10,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
    };
}

function twoCategories(): ReportResult {
    return result([
        row({ group_value: 'won', value: '6' }),
        row({ group_value: 'lost', value: '4' }),
    ]);
}

function twoDimensions(): ReportResult {
    return result([
        row({ group_value: 'Nord', series_value: '2026', value: '10' }),
        row({ group_value: 'Nord', series_value: '2027', value: '12' }),
        row({ group_value: 'Süd', series_value: '2026', value: '7' }),
    ]);
}

function asRecord(value: unknown): Record<string, unknown> {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error('expected a plain object');
    }

    return value as Record<string, unknown>;
}

function asRecords(value: unknown): Record<string, unknown>[] {
    if (!Array.isArray(value)) {
        throw new Error('expected an array of plain objects');
    }

    return value.map((entry: unknown) => asRecord(entry));
}

function asText(value: unknown): string {
    if (typeof value !== 'string') {
        throw new Error('expected a string');
    }

    return value;
}

function optionsFor(input: ReportChartInput): Record<string, unknown> {
    return asRecord(useReportChartOptions(input).value);
}

function seriesOf(options: Record<string, unknown>): Record<string, unknown>[] {
    return asRecords(options.series);
}

function dataOf(options: Record<string, unknown>): Record<string, unknown>[] {
    return asRecords(options.data);
}

function categoriesOf(options: Record<string, unknown>): string[] {
    return dataOf(options).map((datum) => asText(datum.category));
}

function yKeysOf(options: Record<string, unknown>): string[] {
    return seriesOf(options).map((entry) => asText(entry.yKey));
}

function bucketCategory(
    groupValue: string,
    bucket: ReportChartInput['bucket'],
): string {
    const options = optionsFor({
        result: result([row({ group_value: groupValue, value: '1' })]),
        chartType: 'bar',
        bucket,
    });

    return categoriesOf(options)[0];
}

describe('useReportChartOptions legend and tooltip', () => {
    chartTypes.forEach((chartType: ReportChartType) => {
        it(`switches legend and tooltip on for the ${chartType} chart`, () => {
            const options = optionsFor({
                result: twoCategories(),
                chartType,
            });

            expect(options.legend).toEqual({
                enabled: true,
                position: 'bottom',
            });
            expect(options.tooltip).toEqual({ enabled: true });
        });
    });
});

describe('useReportChartOptions axes', () => {
    cartesianTypes.forEach((chartType: ReportChartType) => {
        it(`hands ${chartType} axes over as a dictionary and never as an array`, () => {
            const options = optionsFor({
                result: twoCategories(),
                chartType,
            });
            const axes = asRecord(options.axes);

            expect(Array.isArray(options.axes)).toBe(false);
            expect(Object.keys(axes).sort()).toEqual(['x', 'y']);
            expect(asRecord(axes.x).type).toBe('category');
            expect(asRecord(axes.y).type).toBe('number');
        });
    });

    it('omits the axes entirely for the pie and the donut chart', () => {
        expect(
            'axes' in optionsFor({ result: twoCategories(), chartType: 'pie' }),
        ).toBe(false);
        expect(
            'axes' in
                optionsFor({ result: twoCategories(), chartType: 'donut' }),
        ).toBe(false);
    });
});

describe('useReportChartOptions bar modes', () => {
    it('groups the bars when the caller asks for grouped bars', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
            barMode: 'grouped',
        });

        seriesOf(options).forEach((entry) => {
            expect(entry.grouped).toBe(true);
            expect('stacked' in entry).toBe(false);
        });
    });

    it('stacks the bars when the caller asks for stacked bars', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
            barMode: 'stacked',
        });

        seriesOf(options).forEach((entry) => {
            expect(entry.stacked).toBe(true);
            expect('grouped' in entry).toBe(false);
        });
    });

    it('groups the bars when the caller states no mode at all', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
        });

        seriesOf(options).forEach((entry) => {
            expect(entry.grouped).toBe(true);
            expect('stacked' in entry).toBe(false);
        });
    });

    it('never stacks a line, not even when a stacked mode is handed in', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'line',
            barMode: 'stacked',
        });

        seriesOf(options).forEach((entry) => {
            expect(entry.type).toBe('line');
            expect('stacked' in entry).toBe(false);
            expect('grouped' in entry).toBe(false);
        });
    });

    it('stacks an area chart when the caller asks for a stacked mode', () => {
        const stacked = optionsFor({
            result: twoDimensions(),
            chartType: 'area',
            barMode: 'stacked',
        });
        const grouped = optionsFor({
            result: twoDimensions(),
            chartType: 'area',
            barMode: 'grouped',
        });

        seriesOf(stacked).forEach((entry) => {
            expect(entry.type).toBe('area');
            expect(entry.stacked).toBe(true);
        });
        seriesOf(grouped).forEach((entry) => {
            expect('stacked' in entry).toBe(false);
        });
    });
});

describe('useReportChartOptions pie and donut', () => {
    it('binds the pie to the angle and names its legend entries', () => {
        const options = optionsFor({
            result: twoCategories(),
            chartType: 'pie',
        });
        const series = seriesOf(options)[0];

        expect(seriesOf(options)).toHaveLength(1);
        expect(series.type).toBe('pie');
        expect(series.angleKey).toBe('value');
        expect(series.legendItemKey).toBe('category');
        expect('title' in series).toBe(false);
        expect('innerRadiusRatio' in series).toBe(false);
    });

    it('cuts the donut open and keeps the pie closed', () => {
        const options = optionsFor({
            result: twoCategories(),
            chartType: 'donut',
        });
        const series = seriesOf(options)[0];

        expect(series.type).toBe('donut');
        expect(typeof series.innerRadiusRatio).toBe('number');
        expect('title' in series).toBe(false);
    });

    it('draws one slice per row and never sums two dimensions together', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'pie',
        });

        expect(dataOf(options)).toHaveLength(3);
        expect(categoriesOf(options)[0]).toContain('Nord');
        expect(categoriesOf(options)[0]).toContain('2026');
        expect(dataOf(options)[0].value).toBe(10);
    });
});

describe('useReportChartOptions pivot', () => {
    it('carries a single series named after the aggregation', () => {
        const options = optionsFor({
            result: twoCategories(),
            chartType: 'bar',
        });
        const series = seriesOf(options)[0];

        expect(seriesOf(options)).toHaveLength(1);
        expect(series.xKey).toBe('category');
        expect(series.yKey).toBe('value');
        expect(series.yName).toBe('Anzahl');
        expect(categoriesOf(options)).toEqual(['won', 'lost']);
        expect(dataOf(options)[0].value).toBe(6);
        expect(dataOf(options)[1].value).toBe(4);
    });

    it('turns the long rows into one wide datum per category', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
        });
        const keys = yKeysOf(options);

        expect(dataOf(options)).toHaveLength(2);
        expect(seriesOf(options)).toHaveLength(2);
        expect(new Set(keys).size).toBe(2);
        expect(keys).not.toContain('category');
        expect(categoriesOf(options)).toEqual(['Nord', 'Süd']);
        expect(dataOf(options)[0][keys[0]]).toBe(10);
        expect(dataOf(options)[0][keys[1]]).toBe(12);
        expect(dataOf(options)[1][keys[0]]).toBe(7);
    });

    it('leaves a missing combination as a gap instead of a zero', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
        });
        const missing = dataOf(options)[1];
        const key = yKeysOf(options)[1];

        expect(Object.hasOwn(missing, key)).toBe(true);
        expect(missing[key]).toBeNull();
    });

    it('names every series after its raw series value', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'bar',
        });

        expect(seriesOf(options).map((entry) => entry.yName)).toEqual([
            '2026',
            '2027',
        ]);
    });

    it('keeps a series called category apart from the category key itself', () => {
        const options = optionsFor({
            result: result([
                row({
                    group_value: 'Nord',
                    series_value: 'category',
                    value: '3',
                }),
            ]),
            chartType: 'bar',
        });

        expect(yKeysOf(options)).toEqual(['series:v:category']);
        expect(dataOf(options)[0].category).toBe('Nord');
        expect(dataOf(options)[0]['series:v:category']).toBe(3);
    });
});

describe('useReportChartOptions collected groups', () => {
    it('labels the collected row Sonstige and moves it to the end', () => {
        const options = optionsFor({
            result: result([
                row({
                    group_value: null,
                    is_other_group: true,
                    value: '3',
                }),
                row({ group_value: 'won', value: '6' }),
            ]),
            chartType: 'bar',
        });

        expect(categoriesOf(options)).toEqual(['won', 'Sonstige']);
        expect(dataOf(options)[1].value).toBe(3);
    });

    it('labels a genuine null group Ohne Angabe and never Sonstige', () => {
        const options = optionsFor({
            result: result([
                row({ group_value: null, is_other_group: false, value: '5' }),
            ]),
            chartType: 'bar',
        });

        expect(categoriesOf(options)).toEqual(['Ohne Angabe']);
    });

    it('keeps a genuine null group and the collected row as two categories', () => {
        const options = optionsFor({
            result: result([
                row({ group_value: null, is_other_group: false, value: '5' }),
                row({ group_value: null, is_other_group: true, value: '3' }),
            ]),
            chartType: 'bar',
        });

        expect(categoriesOf(options)).toEqual(['Ohne Angabe', 'Sonstige']);
        expect(dataOf(options)[0].value).toBe(5);
        expect(dataOf(options)[1].value).toBe(3);
    });

    it('keeps a real category named Sonstige apart from the collected row', () => {
        const options = optionsFor({
            result: result([
                row({ group_value: 'Sonstige', value: '5' }),
                row({ group_value: null, is_other_group: true, value: '3' }),
            ]),
            chartType: 'bar',
        });

        expect(dataOf(options)).toHaveLength(2);
        expect(dataOf(options)[0].value).toBe(5);
        expect(dataOf(options)[1].value).toBe(3);
    });

    it('moves the collected series to the last position', () => {
        const options = optionsFor({
            result: result([
                row({
                    group_value: 'Nord',
                    series_value: null,
                    is_other_series: true,
                    value: '3',
                }),
                row({ group_value: 'Nord', series_value: '2026', value: '10' }),
            ]),
            chartType: 'bar',
        });
        const names = seriesOf(options).map((entry) => entry.yName);

        expect(names).toEqual(['2026', 'Sonstige']);
    });

    it('moves the collected series to the last slice of a pie', () => {
        const options = optionsFor({
            result: result([
                row({
                    group_value: 'Nord',
                    series_value: null,
                    is_other_series: true,
                    value: '1',
                }),
                ...['a', 'b', 'c', 'd', 'e'].map((series) =>
                    row({
                        group_value: 'Nord',
                        series_value: series,
                        value: '1',
                    }),
                ),
            ]),
            chartType: 'pie',
        });
        const categories = categoriesOf(options);

        expect(categories).toHaveLength(6);
        expect(categories[5]).toBe('Nord · Sonstige');
        expect(categories.slice(0, 5)).not.toContain('Nord · Sonstige');
    });

    it('moves the collected group to the last slice of a pie', () => {
        const options = optionsFor({
            result: result([
                row({
                    group_value: null,
                    is_other_group: true,
                    value: '6',
                }),
                ...['a', 'b', 'c', 'd', 'e'].map((group) =>
                    row({ group_value: group, value: '1' }),
                ),
            ]),
            chartType: 'pie',
        });
        const categories = categoriesOf(options);

        expect(categories).toHaveLength(6);
        expect(categories[5]).toBe('Sonstige');
        expect(categories.slice(0, 5)).toEqual(['a', 'b', 'c', 'd', 'e']);
        expect(dataOf(options)[5].value).toBe(6);
    });

    it('known limitation: a sixth series wraps the palette onto the neutral grey', () => {
        const options = optionsFor({
            result: result(
                ['a', 'b', 'c', 'd', 'e', 'f'].map((series) =>
                    row({
                        group_value: 'Nord',
                        series_value: series,
                        value: '1',
                    }),
                ),
            ),
            chartType: 'bar',
        });

        expect(seriesOf(options)).toHaveLength(6);
        expect('palette' in options).toBe(false);
        expect('theme' in options).toBe(false);
    });
});

describe('useReportChartOptions time buckets', () => {
    it('spells a day bucket out in German', () => {
        expect(bucketCategory('2026-08-10T00:00:00+02:00', 'day')).toBe(
            '10.08.2026',
        );
    });

    it('reads a bucket in Europe/Berlin instead of the runner timezone', () => {
        expect(bucketCategory('2026-01-01T00:00:00+01:00', 'day')).toBe(
            '01.01.2026',
        );
    });

    it('spells a week bucket out as a German calendar week', () => {
        expect(bucketCategory('2026-08-10T00:00:00+02:00', 'week')).toBe(
            'KW 33 2026',
        );
    });

    it('follows the ISO week across the turn of the year', () => {
        expect(bucketCategory('2024-12-30T00:00:00+01:00', 'week')).toBe(
            'KW 1 2025',
        );
    });

    it('spells a month bucket out in German', () => {
        expect(bucketCategory('2026-08-01T00:00:00+02:00', 'month')).toBe(
            'Aug. 2026',
        );
    });

    it('spells a quarter bucket out in German', () => {
        expect(bucketCategory('2026-07-01T00:00:00+02:00', 'quarter')).toBe(
            'Q3 2026',
        );
    });

    it('spells a year bucket out as the plain year', () => {
        expect(bucketCategory('2026-01-01T00:00:00+01:00', 'year')).toBe(
            '2026',
        );
    });

    it('falls back to the raw value and never prints Invalid Date', () => {
        expect(bucketCategory('kein Datum', 'month')).toBe('kein Datum');
    });

    it('leaves the group value alone when no bucket is in play', () => {
        expect(bucketCategory('2026-08-10T00:00:00+02:00', null)).toBe(
            '2026-08-10T00:00:00+02:00',
        );
    });
});

describe('useReportChartOptions purity', () => {
    chartTypes.forEach((chartType: ReportChartType) => {
        it(`decides no colour of its own for the ${chartType} chart`, () => {
            const options = optionsFor({
                result: twoCategories(),
                chartType,
            });
            const serialised = JSON.stringify(options);

            expect(serialised).not.toContain('fills');
            expect(serialised).not.toContain('itemStyler');
            expect(serialised).not.toContain('stroke');
            expect(serialised).not.toMatch(/#[0-9a-fA-F]{3,8}|rgba?\(/);
        });
    });

    it('hands out plain data objects and never a reactive proxy', () => {
        const input = reactive<ReportChartInput>({
            result: twoCategories(),
            chartType: 'bar',
        });
        const options = optionsFor(input);

        expect(isReactive(options.data)).toBe(false);
        expect(isReactive(dataOf(options)[0])).toBe(false);
        expect(isReactive(seriesOf(options)[0])).toBe(false);
    });

    it('builds a fresh options object when the input changes', () => {
        const input = ref<ReportChartInput>({
            result: twoCategories(),
            chartType: 'bar',
        });
        const options = useReportChartOptions(input);
        const before = asRecord(options.value);

        input.value = { result: twoCategories(), chartType: 'line' };

        const after = asRecord(options.value);

        expect(after).not.toBe(before);
        expect(seriesOf(before)[0].type).toBe('bar');
        expect(seriesOf(after)[0].type).toBe('line');
    });
});

function drillDownListenerOf(
    options: Record<string, unknown>,
): (event: Record<string, unknown>) => void {
    const listeners = options.listeners;

    if (listeners === null || typeof listeners !== 'object') {
        throw new Error('the options carry no listeners object');
    }

    const handler = (listeners as Record<string, unknown>).seriesNodeClick;

    if (typeof handler !== 'function') {
        throw new Error('the options carry no seriesNodeClick listener');
    }

    return handler as (event: Record<string, unknown>) => void;
}

function clickableOptions(
    payload: ReportResult,
    chartType: ReportChartType,
    clicks: ReportDrillDownSelection[],
): Record<string, unknown> {
    return optionsFor({
        result: payload,
        chartType,
        onSeriesClick: (selection: ReportDrillDownSelection) => {
            clicks.push(selection);
        },
    });
}

function collectedSeries(): ReportResult {
    return result([
        row({ group_value: 'Nord', series_value: '2026', value: '10' }),
        row({
            group_value: 'Nord',
            series_value: null,
            is_other_series: true,
            value: '3',
        }),
    ]);
}

function mixedSeries(): ReportResult {
    return result([
        row({ group_value: 'Nord', series_value: '2026', value: '10' }),
        row({ group_value: 'Süd', series_value: null, value: '4' }),
    ]);
}

describe('useReportChartOptions drill-down identity', () => {
    cartesianTypes.forEach((chartType: ReportChartType) => {
        it(`carries the raw group token on every ${chartType} datum`, () => {
            const options = optionsFor({
                result: twoCategories(),
                chartType,
            });

            expect(dataOf(options).map((datum) => datum.groupToken)).toEqual([
                'v:won',
                'v:lost',
            ]);
        });
    });

    it('keeps the raw ISO value of a bucket while the label stays German', () => {
        const options = optionsFor({
            result: result([
                row({ group_value: '2026-08-01T00:00:00+02:00', value: '1' }),
            ]),
            chartType: 'bar',
            bucket: 'month',
        });

        expect(categoriesOf(options)).toEqual(['Aug. 2026']);
        expect(dataOf(options)[0].groupToken).toBe(
            'v:2026-08-01T00:00:00+02:00',
        );
    });

    it('keeps the collected bucket and the missing value apart on the datum', () => {
        const options = optionsFor({
            result: result([
                row({ group_value: null, is_other_group: false, value: '5' }),
                row({ group_value: null, is_other_group: true, value: '3' }),
            ]),
            chartType: 'bar',
        });

        expect(dataOf(options).map((datum) => datum.groupToken)).toEqual([
            'null',
            'other',
        ]);
    });

    it('carries group and series token on a slice without touching its label', () => {
        const options = optionsFor({
            result: twoDimensions(),
            chartType: 'pie',
        });

        expect(categoriesOf(options)[0]).toBe('Nord · 2026');
        expect(dataOf(options)[0].groupToken).toBe('v:Nord');
        expect(dataOf(options)[0].seriesToken).toBe('v:2026');
    });

    it('leaves the series token empty on a slice of a one dimensional report', () => {
        const options = optionsFor({
            result: twoCategories(),
            chartType: 'pie',
        });

        expect(dataOf(options)[0].groupToken).toBe('v:won');
        expect(dataOf(options)[0].seriesToken).toBeNull();
    });
});

describe('useReportChartOptions drill-down listener', () => {
    it('registers the click listener once on the options root', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(twoDimensions(), 'bar', clicks);

        expect(typeof drillDownListenerOf(options)).toBe('function');

        seriesOf(options).forEach((entry) => {
            expect('listeners' in entry).toBe(false);
        });
    });

    it('adds no listener key at all when nobody listens', () => {
        const options = optionsFor({
            result: twoCategories(),
            chartType: 'bar',
        });

        expect('listeners' in options).toBe(false);
    });

    it('reports a clicked bar as its group and as no series at all', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(twoCategories(), 'bar', clicks);

        drillDownListenerOf(options)({
            datum: dataOf(options)[0],
            yKey: 'value',
        });

        expect(clicks).toEqual([{ group: 'v:won', series: null }]);
    });

    it('reads the series of a clicked stack out of its yKey', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(twoDimensions(), 'bar', clicks);

        drillDownListenerOf(options)({
            datum: dataOf(options)[0],
            yKey: 'series:v:2026',
        });

        expect(clicks).toEqual([{ group: 'v:Nord', series: 'v:2026' }]);
    });

    it('reads a series without a value as its own token', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(mixedSeries(), 'bar', clicks);

        drillDownListenerOf(options)({
            datum: dataOf(options)[1],
            yKey: 'series:null',
        });

        expect(clicks).toEqual([{ group: 'v:Süd', series: 'null' }]);
    });

    it('reports a clicked slice from its datum alone', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(twoDimensions(), 'pie', clicks);

        drillDownListenerOf(options)({ datum: dataOf(options)[0] });

        expect(clicks).toEqual([{ group: 'v:Nord', series: 'v:2026' }]);
    });

    it('offers nothing when the collected bucket itself is clicked', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(
            result([
                row({ group_value: 'won', value: '6' }),
                row({ group_value: null, is_other_group: true, value: '4' }),
            ]),
            'bar',
            clicks,
        );

        drillDownListenerOf(options)({
            datum: dataOf(options)[1],
            yKey: 'value',
        });

        expect(clicks).toEqual([]);
    });

    it('offers nothing when the collected series of a bar is clicked', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(collectedSeries(), 'bar', clicks);

        drillDownListenerOf(options)({
            datum: dataOf(options)[0],
            yKey: 'series:other',
        });

        expect(clicks).toEqual([]);
    });

    it('offers nothing when the collected series of a slice is clicked', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(collectedSeries(), 'pie', clicks);
        const collected = dataOf(options)[1];

        expect(collected.seriesToken).toBe('other');

        drillDownListenerOf(options)({ datum: collected });

        expect(clicks).toEqual([]);
    });

    it('stays silent and throws nothing on a datum it cannot identify', () => {
        const clicks: ReportDrillDownSelection[] = [];
        const options = clickableOptions(twoCategories(), 'bar', clicks);
        const listener = drillDownListenerOf(options);

        expect(() => listener({ datum: {}, yKey: 'value' })).not.toThrow();
        expect(() => listener({ datum: null, yKey: 'value' })).not.toThrow();
        expect(clicks).toEqual([]);
    });
});
