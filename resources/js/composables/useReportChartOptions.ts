import type {
    AgCartesianChartOptions,
    AgCartesianSeriesOptions,
    AgChartOptions,
    AgPolarChartOptions,
    AgPolarSeriesOptions,
} from 'ag-charts-community';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import { computed, toValue } from 'vue';
import type {
    ReportBarMode,
    ReportChartType,
    ReportDrillDownSelection,
    ReportGroupingBucket,
    ReportGroupRow,
    ReportResult,
} from '@/types/reports';
import {
    drillDownToken,
    isCollectorToken,
    OTHER_LABEL,
    parseAggregateValue,
    REPORT_AGGREGATION_LABELS,
    UNSPECIFIED_LABEL,
} from '@/types/reports';

export interface ReportChartInput {
    result: ReportResult;
    chartType: ReportChartType;
    barMode?: ReportBarMode;
    bucket?: ReportGroupingBucket | null;
    onSeriesClick?: (selection: ReportDrillDownSelection) => void;
}

interface PivotKey {
    key: string;
    label: string;
    isOther: boolean;
}

interface SeriesClickEvent {
    datum?: unknown;
    yKey?: unknown;
}

interface SeriesClickListeners {
    seriesNodeClick: (event: SeriesClickEvent) => void;
}

type ChartDatum = Record<string, string | number | null>;

const CATEGORY_KEY = 'category';

const VALUE_KEY = 'value';

const SERIES_PREFIX = 'series:';

const GROUP_TOKEN_KEY = 'groupToken';

const SERIES_TOKEN_KEY = 'seriesToken';

const DONUT_INNER_RADIUS_RATIO = 0.6;

const MILLISECONDS_PER_WEEK = 7 * 24 * 60 * 60 * 1000;

const berlinDayFormatter = new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

const berlinMonthFormatter = new Intl.DateTimeFormat('de-DE', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: 'short',
});

const berlinPartsFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

export function useReportChartOptions(
    input: MaybeRefOrGetter<ReportChartInput>,
): ComputedRef<AgChartOptions> {
    return computed(() => buildOptions(toValue(input)));
}

export function countReportCategories(result: ReportResult): number {
    return collect(result.rows, (row) => categoryOf(row, null)).length;
}

function buildOptions(input: ReportChartInput): AgChartOptions {
    const bucket = input.bucket ?? null;
    const withSeries = hasSeriesDimension(input.result.rows);

    if (input.chartType === 'pie' || input.chartType === 'donut') {
        const polar: AgPolarChartOptions = {
            data: polarData(input.result, bucket, withSeries),
            series: polarSeries(input.chartType),
            legend: { enabled: true, position: 'bottom' },
            tooltip: { enabled: true },
        };

        if (input.onSeriesClick !== undefined) {
            polar.listeners = clickListeners(input.onSeriesClick);
        }

        return polar;
    }

    const categories = collect(input.result.rows, (row) =>
        categoryOf(row, bucket),
    );
    const series = withSeries
        ? collect(input.result.rows, seriesOf)
        : [aggregationSeries(input.result)];
    const cartesian: AgCartesianChartOptions = {
        data: cartesianData(
            input.result,
            bucket,
            categories,
            series,
            withSeries,
        ),
        series: cartesianSeries(
            input.chartType,
            input.barMode ?? 'grouped',
            series,
        ),
        axes: { x: { type: 'category' }, y: { type: 'number' } },
        legend: { enabled: true, position: 'bottom' },
        tooltip: { enabled: true },
    };

    if (input.onSeriesClick !== undefined) {
        cartesian.listeners = clickListeners(input.onSeriesClick);
    }

    return cartesian;
}

function clickListeners(
    onSeriesClick: (selection: ReportDrillDownSelection) => void,
): SeriesClickListeners {
    return {
        seriesNodeClick: (event: SeriesClickEvent): void => {
            const selection = clickSelection(event);

            if (selection !== null) {
                onSeriesClick(selection);
            }
        },
    };
}

function clickSelection(
    event: SeriesClickEvent,
): ReportDrillDownSelection | null {
    const datum = event.datum;

    if (datum === null || typeof datum !== 'object') {
        return null;
    }

    const group = (datum as ChartDatum)[GROUP_TOKEN_KEY];

    if (typeof group !== 'string' || isCollectorToken(group)) {
        return null;
    }

    const series = clickedSeries(event, datum as ChartDatum);

    if (series !== null && isCollectorToken(series)) {
        return null;
    }

    return { group, series };
}

function clickedSeries(
    event: SeriesClickEvent,
    datum: ChartDatum,
): string | null {
    const yKey = event.yKey;

    if (typeof yKey === 'string') {
        return yKey === VALUE_KEY ? null : yKey.slice(SERIES_PREFIX.length);
    }

    const token = datum[SERIES_TOKEN_KEY];

    return typeof token === 'string' ? token : null;
}

function hasSeriesDimension(rows: ReportGroupRow[]): boolean {
    return rows.some((row) => row.series_value !== null || row.is_other_series);
}

function isCollectedRow(row: ReportGroupRow): boolean {
    return row.is_other_group || row.is_other_series;
}

function aggregationSeries(result: ReportResult): PivotKey {
    return {
        key: VALUE_KEY,
        label: REPORT_AGGREGATION_LABELS[result.aggregation],
        isOther: false,
    };
}

function categoryOf(
    row: ReportGroupRow,
    bucket: ReportGroupingBucket | null,
): PivotKey {
    const key = drillDownToken(row.group_value, row.is_other_group);

    if (row.is_other_group) {
        return { key, label: OTHER_LABEL, isOther: true };
    }

    if (row.group_value === null) {
        return { key, label: UNSPECIFIED_LABEL, isOther: false };
    }

    return {
        key,
        label: bucketLabel(row.group_value, bucket),
        isOther: false,
    };
}

function seriesOf(row: ReportGroupRow): PivotKey {
    const token = drillDownToken(row.series_value, row.is_other_series);
    const key = `${SERIES_PREFIX}${token}`;

    if (row.is_other_series) {
        return { key, label: OTHER_LABEL, isOther: true };
    }

    if (row.series_value === null) {
        return { key, label: UNSPECIFIED_LABEL, isOther: false };
    }

    return { key, label: row.series_value, isOther: false };
}

function collect(
    rows: ReportGroupRow[],
    resolve: (row: ReportGroupRow) => PivotKey,
): PivotKey[] {
    const seen = new Map<string, PivotKey>();

    rows.forEach((row) => {
        const entry = resolve(row);

        if (!seen.has(entry.key)) {
            seen.set(entry.key, entry);
        }
    });

    const entries = [...seen.values()];

    return [
        ...entries.filter((entry) => !entry.isOther),
        ...entries.filter((entry) => entry.isOther),
    ];
}

function cartesianData(
    result: ReportResult,
    bucket: ReportGroupingBucket | null,
    categories: PivotKey[],
    series: PivotKey[],
    withSeries: boolean,
): ChartDatum[] {
    const cells = new Map<string, Map<string, number | null>>();

    result.rows.forEach((row) => {
        const categoryKey = categoryOf(row, bucket).key;
        const seriesKey = withSeries ? seriesOf(row).key : VALUE_KEY;
        const cell = cells.get(categoryKey) ?? new Map<string, number | null>();

        cell.set(seriesKey, parseAggregateValue(row.value));
        cells.set(categoryKey, cell);
    });

    return categories.map((category) => {
        const datum: ChartDatum = {
            [CATEGORY_KEY]: category.label,
            [GROUP_TOKEN_KEY]: category.key,
        };
        const cell = cells.get(category.key);

        series.forEach((entry) => {
            datum[entry.key] = cell?.get(entry.key) ?? null;
        });

        return datum;
    });
}

function polarData(
    result: ReportResult,
    bucket: ReportGroupingBucket | null,
    withSeries: boolean,
): ChartDatum[] {
    const rows = [
        ...result.rows.filter((row) => !isCollectedRow(row)),
        ...result.rows.filter((row) => isCollectedRow(row)),
    ];

    return rows.map((row) => {
        const category = categoryOf(row, bucket);
        const label = withSeries
            ? `${category.label} · ${seriesOf(row).label}`
            : category.label;

        return {
            [CATEGORY_KEY]: label,
            [VALUE_KEY]: parseAggregateValue(row.value),
            [GROUP_TOKEN_KEY]: category.key,
            [SERIES_TOKEN_KEY]: withSeries
                ? drillDownToken(row.series_value, row.is_other_series)
                : null,
        };
    });
}

function polarSeries(chartType: 'pie' | 'donut'): AgPolarSeriesOptions[] {
    if (chartType === 'donut') {
        return [
            {
                type: 'donut',
                angleKey: VALUE_KEY,
                legendItemKey: CATEGORY_KEY,
                innerRadiusRatio: DONUT_INNER_RADIUS_RATIO,
            },
        ];
    }

    return [{ type: 'pie', angleKey: VALUE_KEY, legendItemKey: CATEGORY_KEY }];
}

function cartesianSeries(
    chartType: 'bar' | 'line' | 'area',
    barMode: ReportBarMode,
    series: PivotKey[],
): AgCartesianSeriesOptions[] {
    if (chartType === 'line') {
        return series.map((entry) => baseSeries(entry, 'line'));
    }

    if (chartType === 'area') {
        return series.map((entry) =>
            barMode === 'stacked'
                ? { ...baseSeries(entry, 'area'), stacked: true }
                : baseSeries(entry, 'area'),
        );
    }

    return series.map((entry) =>
        barMode === 'stacked'
            ? { ...baseSeries(entry, 'bar'), stacked: true }
            : { ...baseSeries(entry, 'bar'), grouped: true },
    );
}

function baseSeries<T extends 'bar' | 'line' | 'area'>(
    entry: PivotKey,
    type: T,
): { type: T; xKey: string; yKey: string; yName: string } {
    return { type, xKey: CATEGORY_KEY, yKey: entry.key, yName: entry.label };
}

function bucketLabel(
    value: string,
    bucket: ReportGroupingBucket | null,
): string {
    if (bucket === null) {
        return value;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    if (bucket === 'day') {
        return berlinDayFormatter.format(parsed);
    }

    if (bucket === 'month') {
        return berlinMonthFormatter.format(parsed);
    }

    const parts = berlinParts(parsed);

    if (bucket === 'quarter') {
        return `Q${Math.ceil(parts.month / 3)} ${parts.year}`;
    }

    if (bucket === 'year') {
        return `${parts.year}`;
    }

    const week = isoWeek(parts);

    return `KW ${week.week} ${week.year}`;
}

function berlinParts(date: Date): { year: number; month: number; day: number } {
    const [year, month, day] = berlinPartsFormatter.format(date).split('-');

    return { year: Number(year), month: Number(month), day: Number(day) };
}

function isoWeek(parts: { year: number; month: number; day: number }): {
    week: number;
    year: number;
} {
    const thursday = thursdayOfWeek(
        Date.UTC(parts.year, parts.month - 1, parts.day),
    );
    const year = thursday.getUTCFullYear();
    const firstThursday = thursdayOfWeek(Date.UTC(year, 0, 4));
    const week =
        1 +
        Math.round(
            (thursday.getTime() - firstThursday.getTime()) /
                MILLISECONDS_PER_WEEK,
        );

    return { week, year };
}

function thursdayOfWeek(timestamp: number): Date {
    const date = new Date(timestamp);
    const weekday = (date.getUTCDay() + 6) % 7;

    date.setUTCDate(date.getUTCDate() - weekday + 3);

    return date;
}
