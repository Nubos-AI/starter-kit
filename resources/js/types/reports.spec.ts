import { describe, expect, it } from 'vitest';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import type { FieldType } from '@/types/fields';
import type {
    ReportAggregation,
    ReportDrillDownParams,
    ReportDrillDownSelection,
    ReportGroupingBucket,
    ReportGroupRow,
    ReportPresentation,
    ReportRefusal,
    ReportResult,
} from '@/types/reports';
import {
    aggregationAllowsFieldType,
    drillDownToken,
    formatAggregateValue,
    hasPlottableRows,
    hydratableFilterTree,
    isCollectorToken,
    isQualifiedFieldKey,
    linkedAggregationAllowed,
    normalizeFilterTree,
    OTHER_LABEL,
    parseAggregateValue,
    plottableChartType,
    REPORT_AGGREGATION_LABELS,
    REPORT_BUCKET_LABELS,
    REPORT_PRESENTATION_LABELS,
    reportFieldDisabledReason,
    reportImageFileName,
    resolveRefusalMessage,
    resolveReportActionRefusal,
    resolveReportState,
    UNSPECIFIED_LABEL,
} from '@/types/reports';

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
    /\bspalte/i,
    /secret_amount/,
    /group_value/,
] as const;

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
        rows: [row()],
        total: '6',
        record_count: 6,
        discarded_value_count: 0,
        is_suppressed: false,
        generated_at: '2026-08-10T14:23:45+02:00',
        execution_mode: 'viewer',
        ...overrides,
    };
}

function refusal(reason: string): ReportRefusal {
    return { reason };
}

describe('resolveReportState', () => {
    it('reports loading while neither a result nor a refusal has arrived', () => {
        expect(resolveReportState(null, null)).toBe('loading');
        expect(resolveReportState(undefined, undefined)).toBe('loading');
    });

    it('reports the refusal even when a result is present alongside it', () => {
        expect(resolveReportState(result(), refusal('unknown_field'))).toBe(
            'refused',
        );
    });

    it('reports suppression before emptiness when both would apply', () => {
        const suppressed = result({
            rows: [],
            total: null,
            record_count: 0,
            is_suppressed: true,
        });

        expect(resolveReportState(suppressed, null)).toBe('suppressed');
    });

    it('reports emptiness when nothing was counted at all', () => {
        const empty = result({
            rows: [],
            total: '0',
            record_count: 0,
            is_suppressed: false,
        });

        expect(resolveReportState(empty, null)).toBe('empty');
    });

    it('reports readiness for a populated result', () => {
        expect(resolveReportState(result(), null)).toBe('ready');
    });
});

describe('hasPlottableRows', () => {
    it('rejects a result without any group rows', () => {
        expect(hasPlottableRows(result({ rows: [] }))).toBe(false);
    });

    it('rejects a result whose every aggregate value is missing', () => {
        const rows = [row({ value: null }), row({ value: null })];

        expect(hasPlottableRows(result({ rows }))).toBe(false);
    });

    it('rejects a minimum over a date field, whose values are timestamps', () => {
        const rows = [
            row({ value: '2026-08-10T09:15:00+02:00' }),
            row({ value: '2026-08-11T09:15:00+02:00' }),
        ];

        expect(hasPlottableRows(result({ aggregation: 'min', rows }))).toBe(
            false,
        );
    });

    it('accepts a result as soon as a single finite value is present', () => {
        const rows = [row({ value: null }), row({ value: '4' })];

        expect(hasPlottableRows(result({ rows }))).toBe(true);
    });
});

describe('parseAggregateValue', () => {
    it('turns a decimal string into a number', () => {
        expect(parseAggregateValue('6')).toBe(6);
        expect(parseAggregateValue('1234.5678')).toBe(1234.5678);
        expect(parseAggregateValue('-3.5')).toBe(-3.5);
    });

    it('treats a missing value as a gap', () => {
        expect(parseAggregateValue(null)).toBeNull();
    });

    it('treats an empty string as a gap rather than as zero', () => {
        expect(parseAggregateValue('')).toBeNull();
    });

    it('treats a timestamp and other unparsable text as a gap', () => {
        expect(parseAggregateValue('2026-08-10T09:15:00+02:00')).toBeNull();
        expect(parseAggregateValue('abc')).toBeNull();
    });
});

describe('formatAggregateValue', () => {
    it('renders a count without decimals and with a German thousands separator', () => {
        expect(formatAggregateValue('1234', 'count')).toBe('1.234');
        expect(formatAggregateValue('12', 'distinct_count')).toBe('12');
    });

    it('cuts an average down to two decimals', () => {
        expect(formatAggregateValue('1234.5678901235', 'avg')).toBe('1.234,57');
    });

    it('keeps the scale of a sum the field brought with it', () => {
        expect(formatAggregateValue('1234.5678', 'sum')).toBe('1.234,5678');
    });

    it('renders a missing value as a dash', () => {
        expect(formatAggregateValue(null, 'sum')).toBe('—');
    });

    it('renders a minimum over a date field as a German date, never as NaN', () => {
        const formatted = formatAggregateValue(
            '2026-08-10T09:15:00+02:00',
            'min',
        );

        expect(formatted).toMatch(/^\d{1,2}\.\d{1,2}\.\d{4}/);
        expect(formatted).not.toContain('NaN');
        expect(formatted).not.toBe('—');
    });

    it('falls back to the raw value when it is neither a number nor a date', () => {
        expect(formatAggregateValue('n/a', 'max')).toBe('n/a');
    });
});

describe('resolveRefusalMessage', () => {
    it('answers every refusal reason with a distinguishable German sentence', () => {
        const messages = refusalReasons.map((reason) =>
            resolveRefusalMessage(reason),
        );

        messages.forEach((message) => {
            expect(message.length).toBeGreaterThan(10);
        });

        expect(new Set(messages).size).toBe(refusalReasons.length);
    });

    it('answers an unknown reason with its own fallback instead of undefined', () => {
        const fallback = resolveRefusalMessage('something_new_from_the_server');
        const known = refusalReasons.map((reason) =>
            resolveRefusalMessage(reason),
        );

        expect(fallback.length).toBeGreaterThan(10);
        expect(known).not.toContain(fallback);
    });

    it('never leaks a field name or the word anonymity into a refusal text', () => {
        const messages = [
            ...refusalReasons.map((reason) => resolveRefusalMessage(reason)),
            resolveRefusalMessage('something_new_from_the_server'),
        ];

        messages.forEach((message) => {
            structuralTerms.forEach((term) => {
                expect(message).not.toMatch(term);
            });
        });
    });

    it('answers every refusal reason with its own sentence', () => {
        const messages = refusalReasons.map((reason) =>
            resolveRefusalMessage(reason),
        );

        expect(new Set(messages).size).toBe(refusalReasons.length);
    });
});

describe('reportImageFileName', () => {
    it('names the file after the report and its date and ends it with .png', () => {
        const fileName = reportImageFileName(
            'Umsätze Süd',
            '2026-01-01T00:00:00+01:00',
        );

        expect(fileName).toContain('Umsätze Süd');
        expect(fileName).toContain('2026-01-01');
        expect(fileName.endsWith('.png')).toBe(true);
    });

    it('strips path traversal and separators out of the user supplied title', () => {
        const fileName = reportImageFileName(
            'Umsatz/2026: "Test" ../../etc/passwd',
            '2026-01-01T00:00:00+01:00',
        );

        expect(fileName).toContain('Umsatz');
        expect(fileName).not.toContain('/');
        expect(fileName).not.toContain('\\');
        expect(fileName).not.toContain('..');
        expect(fileName).not.toContain(':');
        expect(fileName).not.toContain('"');
        expect(fileName.endsWith('.png')).toBe(true);
    });

    it('falls back to a generic name when the title carries nothing usable', () => {
        expect(reportImageFileName('   ', '2026-01-01T00:00:00+01:00')).toMatch(
            /^report/,
        );
        expect(reportImageFileName('///', '2026-01-01T00:00:00+01:00')).toMatch(
            /^report/,
        );
    });

    it('caps an overlong title without losing the date or the extension', () => {
        const fileName = reportImageFileName(
            'A'.repeat(200),
            '2026-01-01T00:00:00+01:00',
        );

        expect(fileName.length).toBeLessThanOrEqual(100);
        expect(fileName).toContain('2026-01-01');
        expect(fileName.endsWith('.png')).toBe(true);
    });

    it('reads the date in Europe/Berlin instead of the runner timezone', () => {
        const fileName = reportImageFileName(
            'Nacht',
            '2026-08-10T00:30:00+02:00',
        );

        expect(fileName).toContain('2026-08-10');
        expect(fileName).not.toContain('2026-08-09');
    });
});

describe('report labels', () => {
    it('carries a German label for every aggregation the server can send', () => {
        const labels: Record<ReportAggregation, string> = {
            count: 'Anzahl',
            sum: 'Summe',
            avg: 'Durchschnitt',
            min: 'Minimum',
            max: 'Maximum',
            distinct_count: 'Verschiedene Werte',
        };

        expect(REPORT_AGGREGATION_LABELS).toEqual(labels);
    });

    it('names the collected bucket and the missing value in German', () => {
        expect(OTHER_LABEL).toBe('Sonstige');
        expect(UNSPECIFIED_LABEL).toBe('Ohne Angabe');
    });
});

const PRESENTATIONS: ReportPresentation[] = [
    'metric',
    'bar',
    'line',
    'area',
    'pie',
    'donut',
];

const BUCKETS: ReportGroupingBucket[] = [
    'day',
    'week',
    'month',
    'quarter',
    'year',
];

const AGGREGATIONS: ReportAggregation[] = [
    'count',
    'sum',
    'avg',
    'min',
    'max',
    'distinct_count',
];

const FIELD_TYPES: FieldType[] = [
    'text_short',
    'text_long',
    'number',
    'decimal',
    'money',
    'date',
    'datetime',
    'boolean',
    'single_select',
    'multi_select',
    'relation_has_many',
    'relation_many_to_many',
    'email',
    'phone',
    'url',
    'file',
    'geo_address',
    'computed',
    'rollup',
];

const NUMERIC_FIELD_TYPES: FieldType[] = [
    'number',
    'decimal',
    'money',
    'computed',
    'rollup',
];

const TEMPORAL_FIELD_TYPES: FieldType[] = ['date', 'datetime'];

const ENGLISH_WORDS = [
    /\bthe\b/i,
    /\byou\b/i,
    /\bthis\b/i,
    /\bcannot\b/i,
    /\bowner\b/i,
];

function serverSideAllows(
    aggregation: ReportAggregation,
    fieldType: FieldType,
): boolean {
    if (aggregation === 'count' || aggregation === 'distinct_count') {
        return true;
    }

    if (aggregation === 'sum' || aggregation === 'avg') {
        return NUMERIC_FIELD_TYPES.includes(fieldType);
    }

    return (
        NUMERIC_FIELD_TYPES.includes(fieldType) ||
        TEMPORAL_FIELD_TYPES.includes(fieldType)
    );
}

function group(
    conditions: FilterGroupNode['conditions'] = [],
): FilterGroupNode {
    return { combinator: 'and', conditions };
}

describe('report presentation labels', () => {
    it('names every presentation the server can store with a distinct German label', () => {
        const labels = PRESENTATIONS.map(
            (presentation) => REPORT_PRESENTATION_LABELS[presentation],
        );

        expect(Object.keys(REPORT_PRESENTATION_LABELS).sort()).toEqual(
            [...PRESENTATIONS].sort(),
        );
        expect(new Set(labels).size).toBe(PRESENTATIONS.length);

        labels.forEach((label) => {
            expect(label.trim().length).toBeGreaterThan(2);
        });

        expect(REPORT_PRESENTATION_LABELS.metric).toBe('Kennzahl');
        expect(REPORT_PRESENTATION_LABELS.area).toBe('Fläche');
        expect(REPORT_PRESENTATION_LABELS.donut).toBe('Donut');
    });

    it('names every grouping bucket with a distinct German label', () => {
        const labels = BUCKETS.map((bucket) => REPORT_BUCKET_LABELS[bucket]);

        expect(Object.keys(REPORT_BUCKET_LABELS).sort()).toEqual(
            [...BUCKETS].sort(),
        );
        expect(new Set(labels).size).toBe(BUCKETS.length);
        expect(REPORT_BUCKET_LABELS.day).toBe('Tag');
        expect(REPORT_BUCKET_LABELS.quarter).toBe('Quartal');
    });
});

describe('plottableChartType', () => {
    it('refuses to plot the metric presentation', () => {
        expect(plottableChartType('metric')).toBeNull();
    });

    it('passes every genuine chart type through unchanged', () => {
        expect(plottableChartType('bar')).toBe('bar');
        expect(plottableChartType('line')).toBe('line');
        expect(plottableChartType('area')).toBe('area');
        expect(plottableChartType('pie')).toBe('pie');
        expect(plottableChartType('donut')).toBe('donut');
    });
});

describe('resolveReportActionRefusal', () => {
    it('separates the two refusal reasons the server can send', () => {
        const notOwner = resolveReportActionRefusal('not_owner');
        const notPermitted = resolveReportActionRefusal(
            'object_type_not_permitted',
        );

        expect(notOwner).toBeDefined();
        expect(notPermitted).toBeDefined();
        expect(notOwner).not.toBe(notPermitted);
        expect(String(notOwner).length).toBeGreaterThan(10);
        expect(String(notPermitted).length).toBeGreaterThan(10);
    });

    it('answers an unknown reason with a fallback instead of undefined', () => {
        const fallback = resolveReportActionRefusal('something_new');

        expect(fallback).toBeDefined();
        expect(String(fallback).length).toBeGreaterThan(10);
    });

    it('answers a missing reason with undefined so the action carries no tooltip', () => {
        expect(resolveReportActionRefusal(null)).toBeUndefined();
    });

    it('never renders an English server sentence and never a blanket phrase', () => {
        const messages = [
            resolveReportActionRefusal('not_owner'),
            resolveReportActionRefusal('object_type_not_permitted'),
            resolveReportActionRefusal('something_new'),
        ];

        messages.forEach((message) => {
            expect(String(message).length).toBeGreaterThan(10);
            expect(message).not.toBe('Keine Berechtigung');
            expect(message).not.toBe(
                'Only the owner of this report can change it.',
            );
            expect(message).not.toBe(
                'Only the owner of this report can delete it.',
            );

            ENGLISH_WORDS.forEach((word) => {
                expect(String(message)).not.toMatch(word);
            });
        });
    });
});

describe('aggregationAllowsFieldType', () => {
    it('mirrors the server matrix for every aggregation and field type', () => {
        expect(FIELD_TYPES).toHaveLength(19);
        expect(AGGREGATIONS).toHaveLength(6);

        AGGREGATIONS.forEach((aggregation) => {
            FIELD_TYPES.forEach((fieldType) => {
                expect(aggregationAllowsFieldType(aggregation, fieldType)).toBe(
                    serverSideAllows(aggregation, fieldType),
                );
            });
        });
    });

    it('accepts every field type for a count and for a distinct count', () => {
        FIELD_TYPES.forEach((fieldType) => {
            expect(aggregationAllowsFieldType('count', fieldType)).toBe(true);
            expect(
                aggregationAllowsFieldType('distinct_count', fieldType),
            ).toBe(true);
        });
    });

    it('rejects text and dates for a sum but accepts dates for a minimum', () => {
        expect(aggregationAllowsFieldType('sum', 'text_short')).toBe(false);
        expect(aggregationAllowsFieldType('sum', 'date')).toBe(false);
        expect(aggregationAllowsFieldType('sum', 'boolean')).toBe(false);
        expect(aggregationAllowsFieldType('sum', 'rollup')).toBe(true);
        expect(aggregationAllowsFieldType('avg', 'datetime')).toBe(false);
        expect(aggregationAllowsFieldType('min', 'date')).toBe(true);
        expect(aggregationAllowsFieldType('max', 'datetime')).toBe(true);
        expect(aggregationAllowsFieldType('max', 'text_long')).toBe(false);
    });
});

describe('linkedAggregationAllowed', () => {
    it('permits a linked field only for count, sum, minimum and maximum', () => {
        expect(linkedAggregationAllowed('count')).toBe(true);
        expect(linkedAggregationAllowed('sum')).toBe(true);
        expect(linkedAggregationAllowed('min')).toBe(true);
        expect(linkedAggregationAllowed('max')).toBe(true);
        expect(linkedAggregationAllowed('avg')).toBe(false);
        expect(linkedAggregationAllowed('distinct_count')).toBe(false);
    });
});

describe('isQualifiedFieldKey', () => {
    it('accepts exactly one dot between two snake_case segments', () => {
        expect(isQualifiedFieldKey('contacts.email')).toBe(true);
        expect(isQualifiedFieldKey('c.e')).toBe(true);
        expect(isQualifiedFieldKey('contacts_2.email_1')).toBe(true);
    });

    it('rejects a plain key, two dots, empty segments, capitals and leading digits', () => {
        expect(isQualifiedFieldKey('stage')).toBe(false);
        expect(isQualifiedFieldKey('a.b.c')).toBe(false);
        expect(isQualifiedFieldKey('.email')).toBe(false);
        expect(isQualifiedFieldKey('contacts.')).toBe(false);
        expect(isQualifiedFieldKey('Contacts.email')).toBe(false);
        expect(isQualifiedFieldKey('contacts.Email')).toBe(false);
        expect(isQualifiedFieldKey('1contacts.email')).toBe(false);
        expect(isQualifiedFieldKey('contacts.1email')).toBe(false);
        expect(isQualifiedFieldKey('')).toBe(false);
    });
});

describe('reportFieldDisabledReason', () => {
    it('explains the two causes in German and keeps them apart', () => {
        const wrongType = reportFieldDisabledReason('sum', false);
        const linked = reportFieldDisabledReason('avg', true);

        expect(wrongType.trim().length).toBeGreaterThan(10);
        expect(linked.trim().length).toBeGreaterThan(10);
        expect(wrongType).not.toBe(linked);

        [wrongType, linked].forEach((message) => {
            ENGLISH_WORDS.forEach((word) => {
                expect(message).not.toMatch(word);
            });
        });
    });
});

describe('hydratableFilterTree', () => {
    it('turns every empty shape the server can send into an absent seed', () => {
        expect(hydratableFilterTree([])).toBeUndefined();
        expect(hydratableFilterTree({})).toBeUndefined();
        expect(hydratableFilterTree(null)).toBeUndefined();
        expect(hydratableFilterTree(undefined)).toBeUndefined();
        expect(hydratableFilterTree('')).toBeUndefined();
    });

    it('passes a stored tree through so the builder can hydrate it', () => {
        const stored = group([
            { field: 'stage', operator: 'equals', value: 'won' },
        ]);

        expect(hydratableFilterTree(stored)).toEqual(stored);
    });

    it('keeps an empty group as a hydratable seed rather than dropping it', () => {
        expect(hydratableFilterTree(group())).toEqual(group());
    });
});

describe('normalizeFilterTree', () => {
    it('treats a tree without conditions as no filter at all', () => {
        expect(normalizeFilterTree(null)).toBeNull();
        expect(normalizeFilterTree(group())).toBeNull();
        expect(
            normalizeFilterTree({ combinator: 'or', conditions: [] }),
        ).toBeNull();
    });

    it('treats a group whose only child is an empty group as no filter either', () => {
        expect(normalizeFilterTree(group([group()]))).toBeNull();
    });

    it('keeps a tree that carries a condition', () => {
        const filled = group([
            { field: 'stage', operator: 'equals', value: 'won' },
        ]);

        expect(normalizeFilterTree(filled)).toEqual(filled);
    });
});

const DRILL_DOWN_REPORT_ID = '01REPORT0000000000000001';

describe('drill-down wire contract', () => {
    it('assembles the record list parameters out of the token vocabulary', () => {
        const params: ReportDrillDownParams = {
            report: DRILL_DOWN_REPORT_ID,
            group: drillDownToken('won', false),
            series: drillDownToken(null, false),
        };

        expect(params.group).toBe('v:won');
        expect(params.series).toBe('null');
        expect(isCollectorToken(params.group)).toBe(false);
        expect(isCollectorToken(String(params.series))).toBe(false);
    });

    it('marks a selection the client must never offer as collected', () => {
        const selection: ReportDrillDownSelection = {
            group: drillDownToken('won', true),
            series: drillDownToken('2026', true),
        };

        expect(isCollectorToken(selection.group)).toBe(true);
        expect(isCollectorToken(String(selection.series))).toBe(true);
    });
});

describe('drillDownToken', () => {
    it('prefixes a genuine group value so it stays readable as a value', () => {
        expect(drillDownToken('won', false)).toBe('v:won');
        expect(drillDownToken('', false)).toBe('v:');
    });

    it('keeps a value that carries colons of its own intact', () => {
        expect(drillDownToken('2026-08-01T00:00:00+02:00', false)).toBe(
            'v:2026-08-01T00:00:00+02:00',
        );
        expect(drillDownToken('a:b:c', false)).toBe('v:a:b:c');
    });

    it('gives a group without any value a token of its own', () => {
        expect(drillDownToken(null, false)).toBe('null');
    });

    it('gives the collected bucket a token of its own, whatever it carries', () => {
        expect(drillDownToken(null, true)).toBe('other');
        expect(drillDownToken('won', true)).toBe('other');
    });

    it('never lets a genuine value collide with one of the two markers', () => {
        expect(drillDownToken('null', false)).toBe('v:null');
        expect(drillDownToken('other', false)).toBe('v:other');
        expect(drillDownToken('null', false)).not.toBe(
            drillDownToken(null, false),
        );
        expect(drillDownToken('other', false)).not.toBe(
            drillDownToken('other', true),
        );
    });
});

describe('isCollectorToken', () => {
    it('recognises the collected bucket', () => {
        expect(isCollectorToken('other')).toBe(true);
    });

    it('recognises nothing else as collected', () => {
        expect(isCollectorToken('v:other')).toBe(false);
        expect(isCollectorToken('null')).toBe(false);
        expect(isCollectorToken('v:null')).toBe(false);
        expect(isCollectorToken('v:won')).toBe(false);
        expect(isCollectorToken('')).toBe(false);
    });
});
