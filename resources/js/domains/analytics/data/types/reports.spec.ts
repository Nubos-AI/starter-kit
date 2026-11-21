import { afterEach, describe, expect, it } from 'vitest';
import { resolveActionRefusal } from '@/shared/lib/actionRefusal';
import { t } from '@/shared/testing/i18n';
import type { FieldType } from '@/shared/types/fields';
import type {
  ReportAggregation,
  ReportGroupingBucket,
  ReportGroupRow,
  ReportPresentation,
  ReportRefusal,
  ReportResult,
} from './reports';
import {
  aggregationAllowsFieldType,
  formatAggregateValue,
  hasPlottableRows,
  isQualifiedFieldKey,
  linkedAggregationAllowed,
  OTHER_LABEL,
  parseAggregateValue,
  plottableChartType,
  REPORT_BUCKET_LABELS,
  REPORT_EMPTY_MESSAGE,
  REPORT_PRESENTATION_LABELS,
  REPORT_SUPPRESSED_MESSAGE,
  reportDiscardedMessage,
  reportFieldDisabledReason,
  reportImageFileName,
  resolveRefusalMessage,
  reportActionRefusals,
  resolveReportState,
  UNSPECIFIED_LABEL,
} from './reports';

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

const structuralTerms = [/anonym/i, /\bfeld/i, /field/i, /\bspalte/i, /secret_amount/, /group_value/] as const;

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
    expect(resolveReportState(result(), refusal('unknown_field'))).toBe('refused');
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
    const rows = [row({ value: '2026-08-10T09:15:00+02:00' }), row({ value: '2026-08-11T09:15:00+02:00' })];

    expect(hasPlottableRows(result({ aggregation: 'min', rows }))).toBe(false);
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

  it.each([
    ['a missing value', null],
    ['an empty string rather than zero', ''],
    ['a timestamp', '2026-08-10T09:15:00+02:00'],
    ['other unparsable text', 'abc'],
  ])('treats %s as a gap', (_case, value) => {
    expect(parseAggregateValue(value)).toBeNull();
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

  it('renders an amount in the currency a module tile names', () => {
    expect(formatAggregateValue('1250.5', 'sum', 'EUR')).toBe('1.250,50\u00a0€');
    expect(formatAggregateValue('1250', 'sum', 'JPY')).toBe('1.250\u00a0¥');
  });

  it('ignores a currency that is no ISO code instead of failing', () => {
    expect(formatAggregateValue('1250.5', 'sum', 'euro')).toBe('1.250,5');
  });

  it('renders a minimum over a date field as a German date, never as NaN', () => {
    const formatted = formatAggregateValue('2026-08-10T09:15:00+02:00', 'min');

    expect(formatted).toMatch(/^\d{1,2}\.\d{1,2}\.\d{4}/);
    expect(formatted).not.toContain('NaN');
    expect(formatted).not.toBe('—');
  });

  it('falls back to the raw value when it is neither a number nor a date', () => {
    expect(formatAggregateValue('n/a', 'max')).toBe('n/a');
  });
});

describe('formatAggregateValue in English', () => {
  afterEach(() => {
    document.documentElement.lang = '';
  });

  it('groups and separates an aggregate the American way', () => {
    document.documentElement.lang = 'en';

    expect(formatAggregateValue('23123.45', 'avg')).toBe('23,123.45');
  });
});

describe('resolveRefusalMessage', () => {
  it('answers every refusal reason with a distinguishable German sentence', () => {
    const messages = refusalReasons.map((reason) => resolveRefusalMessage(reason, t));

    messages.forEach((message) => {
      expect(message.length).toBeGreaterThan(10);
    });

    expect(new Set(messages).size).toBe(refusalReasons.length);
  });

  it('answers an unknown reason with its own fallback instead of undefined', () => {
    const fallback = resolveRefusalMessage('something_new_from_the_server', t);
    const known = refusalReasons.map((reason) => resolveRefusalMessage(reason, t));

    expect(fallback.length).toBeGreaterThan(10);
    expect(known).not.toContain(fallback);
  });

  it('never leaks a field name or the word anonymity into a refusal text', () => {
    const messages = [
      ...refusalReasons.map((reason) => resolveRefusalMessage(reason, t)),
      resolveRefusalMessage('something_new_from_the_server', t),
    ];

    messages.forEach((message) => {
      structuralTerms.forEach((term) => {
        expect(message).not.toMatch(term);
      });
    });
  });
});

describe('report state messages', () => {
  it('never spells out k-anonymity in any message a report state can show', () => {
    const messages = [
      t(REPORT_SUPPRESSED_MESSAGE),
      t(REPORT_EMPTY_MESSAGE),
      t(OTHER_LABEL),
      t(UNSPECIFIED_LABEL),
      reportDiscardedMessage(1, t),
      reportDiscardedMessage(3, t),
      ...refusalReasons.map((reason) => resolveRefusalMessage(reason, t)),
      resolveRefusalMessage('something_new_from_the_server', t),
    ];

    messages.forEach((message) => {
      expect(message).not.toMatch(/anonym/i);
    });
  });
});

describe('reportImageFileName', () => {
  it('names the file after the report and its date and ends it with .png', () => {
    const fileName = reportImageFileName('Umsätze Süd', '2026-01-01T00:00:00+01:00');

    expect(fileName).toContain('Umsätze Süd');
    expect(fileName).toContain('2026-01-01');
    expect(fileName.endsWith('.png')).toBe(true);
  });

  it('strips path traversal and separators out of the user supplied title', () => {
    const fileName = reportImageFileName('Umsatz/2026: "Test" ../../etc/passwd', '2026-01-01T00:00:00+01:00');

    expect(fileName).toContain('Umsatz');
    expect(fileName).not.toContain('/');
    expect(fileName).not.toContain('\\');
    expect(fileName).not.toContain('..');
    expect(fileName).not.toContain(':');
    expect(fileName).not.toContain('"');
    expect(fileName.endsWith('.png')).toBe(true);
  });

  it('falls back to a generic name when the title carries nothing usable', () => {
    expect(reportImageFileName('   ', '2026-01-01T00:00:00+01:00')).toMatch(/^report/);
    expect(reportImageFileName('///', '2026-01-01T00:00:00+01:00')).toMatch(/^report/);
  });

  it('caps an overlong title without losing the date or the extension', () => {
    const fileName = reportImageFileName('A'.repeat(200), '2026-01-01T00:00:00+01:00');

    expect(fileName.length).toBeLessThanOrEqual(100);
    expect(fileName).toContain('2026-01-01');
    expect(fileName.endsWith('.png')).toBe(true);
  });

  it('reads the date in Europe/Berlin instead of the runner timezone', () => {
    const fileName = reportImageFileName('Nacht', '2026-08-10T00:30:00+02:00');

    expect(fileName).toContain('2026-08-10');
    expect(fileName).not.toContain('2026-08-09');
  });
});

const PRESENTATIONS: ReportPresentation[] = ['metric', 'bar', 'line', 'area', 'pie', 'donut'];

const BUCKETS: ReportGroupingBucket[] = ['day', 'week', 'month', 'quarter', 'year'];

const AGGREGATIONS: ReportAggregation[] = ['count', 'sum', 'avg', 'min', 'max', 'distinct_count'];

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

const NUMERIC_AND_FORMULA: FieldType[] = ['number', 'decimal', 'money', 'computed', 'rollup'];

const ALLOWED_FIELD_TYPES: Record<ReportAggregation, FieldType[]> = {
  count: FIELD_TYPES,
  distinct_count: FIELD_TYPES,
  sum: NUMERIC_AND_FORMULA,
  avg: NUMERIC_AND_FORMULA,
  min: [...NUMERIC_AND_FORMULA, 'date', 'datetime'],
  max: [...NUMERIC_AND_FORMULA, 'date', 'datetime'],
};

const ENGLISH_WORDS = [/\bthe\b/i, /\byou\b/i, /\bthis\b/i, /\bcannot\b/i, /\bowner\b/i];

describe('report presentation labels', () => {
  it('covers every presentation the server can store', () => {
    expect(Object.keys(REPORT_PRESENTATION_LABELS).sort()).toEqual([...PRESENTATIONS].sort());
  });

  it('covers every grouping bucket', () => {
    expect(Object.keys(REPORT_BUCKET_LABELS).sort()).toEqual([...BUCKETS].sort());
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

describe('the report action refusals', () => {
  it('separates the two refusal reasons the server can send', () => {
    const notOwner = resolveActionRefusal('not_owner', reportActionRefusals, t);
    const notPermitted = resolveActionRefusal('object_type_not_permitted', reportActionRefusals, t);

    expect(notOwner).toBeDefined();
    expect(notPermitted).toBeDefined();
    expect(notOwner).not.toBe(notPermitted);
    expect(String(notOwner).length).toBeGreaterThan(10);
    expect(String(notPermitted).length).toBeGreaterThan(10);
  });

  it('explains that a shipped report cannot be deleted', () => {
    const system = resolveActionRefusal('system', reportActionRefusals, t);

    expect(system).toBe(t('i18n.types.reports.action_refusal_messages.system'));
    expect(system).not.toBe(resolveActionRefusal('something_new', reportActionRefusals, t));
  });

  it('answers a missing reason with undefined so the action carries no tooltip', () => {
    expect(resolveActionRefusal(null, reportActionRefusals, t)).toBeUndefined();
  });

  it('never renders an English server sentence and never a blanket phrase', () => {
    const messages = [
      resolveActionRefusal('not_owner', reportActionRefusals, t),
      resolveActionRefusal('object_type_not_permitted', reportActionRefusals, t),
      resolveActionRefusal('something_new', reportActionRefusals, t),
    ];

    messages.forEach((message) => {
      expect(String(message).length).toBeGreaterThan(10);
      expect(message).not.toBe('Keine Berechtigung');
      expect(message).not.toBe('Only the owner of this report can change it.');
      expect(message).not.toBe('Only the owner of this report can delete it.');

      ENGLISH_WORDS.forEach((word) => {
        expect(String(message)).not.toMatch(word);
      });
    });
  });
});

describe('aggregationAllowsFieldType', () => {
  it('allows exactly the field types of the explicit truth table for every aggregation', () => {
    expect(FIELD_TYPES).toHaveLength(19);
    expect(AGGREGATIONS).toHaveLength(6);

    AGGREGATIONS.forEach((aggregation) => {
      FIELD_TYPES.forEach((fieldType) => {
        expect(aggregationAllowsFieldType(aggregation, fieldType)).toBe(
          ALLOWED_FIELD_TYPES[aggregation].includes(fieldType),
        );
      });
    });
  });

  it('accepts every field type for a count and for a distinct count', () => {
    FIELD_TYPES.forEach((fieldType) => {
      expect(aggregationAllowsFieldType('count', fieldType)).toBe(true);
      expect(aggregationAllowsFieldType('distinct_count', fieldType)).toBe(true);
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
    const wrongType = reportFieldDisabledReason('sum', false, t);
    const linked = reportFieldDisabledReason('avg', true, t);

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
