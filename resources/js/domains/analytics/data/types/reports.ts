import type { ActionRefusalCatalogue } from '@/shared/lib/actionRefusal';
import { calendarDayKey, formatDateTime } from '@/shared/lib/formatDate';
import type { TranslationParameters } from '@/shared/lib/i18n';
import { formatLocalizedNumber } from '@/shared/lib/localizedNumber';
import type { FieldType } from '@/shared/types/fields';

export type ReportAggregation = 'count' | 'sum' | 'avg' | 'min' | 'max' | 'distinct_count';

export type ReportExecutionMode = 'viewer' | 'definer';

export type ReportGroupingBucket = 'day' | 'week' | 'month' | 'quarter' | 'year';

export type ReportChartType = 'bar' | 'line' | 'area' | 'pie' | 'donut';

export type ReportBarMode = 'grouped' | 'stacked';

export type ReportResultState = 'loading' | 'refused' | 'suppressed' | 'empty' | 'ready';

export interface ReportGroupRow {
  group_value: string | null;
  series_value: string | null;
  value: string | null;
  record_count: number;
  discarded_value_count: number;
  is_other_group: boolean;
  is_other_series: boolean;
}

export interface ReportResult {
  aggregation: ReportAggregation;
  rows: ReportGroupRow[];
  total: string | null;
  record_count: number;
  discarded_value_count: number;
  is_suppressed: boolean;
  generated_at: string;
  execution_mode: ReportExecutionMode;
}

export interface ReportRefusal {
  reason: string;
}

export type ReportPresentation = 'metric' | ReportChartType;

export type ReportActionRefusalReason = 'not_owner' | 'object_type_not_permitted' | 'system';

export interface ReportDefinition {
  object_type_id: string;
  filter_definition: unknown;
  aggregation_type: ReportAggregation;
  aggregation_field_key: string | null;
  group_by_field_key: string | null;
  group_by_bucket: ReportGroupingBucket | null;
  series_field_key: string | null;
  chart_type: ReportPresentation;
  execution_mode: ReportExecutionMode;
}

export interface ReportObjectTypeRef {
  id: string;
  slug: string;
  name: string;
  label: string;
}

export interface ReportListRow extends ReportDefinition {
  id: string;
  name: string;
  description: string | null;
  object_type: ReportObjectTypeRef | null;
  is_owner: boolean;
  is_system: boolean;
  can_update: boolean;
  can_delete: boolean;
  update_reason: string | null;
  delete_reason: string | null;
  updated_at: string | null;
}

export const OTHER_LABEL = 'i18n.types.reports.other_label';

export const UNSPECIFIED_LABEL = 'i18n.types.reports.unspecified_label';

export const REPORT_SUPPRESSED_MESSAGE = 'i18n.types.reports.report_suppressed_message';

export const REPORT_EMPTY_MESSAGE = 'i18n.types.reports.report_empty_message';

export const REPORT_AGGREGATION_LABELS: Record<ReportAggregation, string> = {
  count: 'i18n.types.reports.report_aggregation_labels.count',
  sum: 'i18n.types.reports.report_aggregation_labels.sum',
  avg: 'i18n.types.reports.report_aggregation_labels.avg',
  min: 'i18n.types.reports.report_aggregation_labels.min',
  max: 'i18n.types.reports.report_aggregation_labels.max',
  distinct_count: 'i18n.types.reports.report_aggregation_labels.distinct_count',
};

export const REPORT_PRESENTATION_LABELS: Record<ReportPresentation, string> = {
  metric: 'i18n.types.reports.report_presentation_labels.metric',
  bar: 'i18n.types.reports.report_presentation_labels.bar',
  line: 'i18n.types.reports.report_presentation_labels.line',
  area: 'i18n.types.reports.report_presentation_labels.area',
  pie: 'i18n.types.reports.report_presentation_labels.pie',
  donut: 'i18n.types.reports.report_presentation_labels.donut',
};

export const REPORT_BUCKET_LABELS: Record<ReportGroupingBucket, string> = {
  day: 'i18n.types.reports.report_bucket_labels.day',
  week: 'i18n.types.reports.report_bucket_labels.week',
  month: 'i18n.types.reports.report_bucket_labels.month',
  quarter: 'i18n.types.reports.report_bucket_labels.quarter',
  year: 'i18n.types.reports.report_bucket_labels.year',
};

export const reportActionRefusals: ActionRefusalCatalogue = {
  messages: {
    not_owner: 'i18n.types.reports.action_refusal_messages.not_owner',
    object_type_not_permitted: 'i18n.types.reports.action_refusal_messages.object_type_not_permitted',
    system: 'i18n.types.reports.action_refusal_messages.system',
  },
  fallback: 'i18n.types.reports.action_refusal_fallback',
};

const LINKED_FIELD_DISABLED_REASON = 'i18n.types.reports.linked_field_disabled_reason';

const FIELD_TYPE_DISABLED_REASON = 'i18n.types.reports.field_type_disabled_reason';

const NUMERIC_FIELD_TYPES: FieldType[] = ['number', 'decimal', 'money', 'computed', 'rollup'];

const TEMPORAL_FIELD_TYPES: FieldType[] = ['date', 'datetime'];

const LINKED_AGGREGATIONS: ReportAggregation[] = ['count', 'sum', 'min', 'max'];

const QUALIFIED_FIELD_KEY_PATTERN = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;

const CURRENCY_CODE_PATTERN = /^[A-Z]{3}$/;

const REFUSAL_MESSAGES: Record<string, string> = {
  malformed_definition: 'i18n.types.reports.refusal_messages.malformed_definition',
  unknown_field: 'i18n.types.reports.refusal_messages.unknown_field',
  field_not_readable: 'i18n.types.reports.refusal_messages.field_not_readable',
  encrypted_field: 'i18n.types.reports.refusal_messages.encrypted_field',
  field_not_filterable: 'i18n.types.reports.refusal_messages.field_not_filterable',
  unsupported_aggregation: 'i18n.types.reports.refusal_messages.unsupported_aggregation',
  unsupported_grouping: 'i18n.types.reports.refusal_messages.unsupported_grouping',
  invalid_filter_tree: 'i18n.types.reports.refusal_messages.invalid_filter_tree',
  report_missing: 'i18n.types.reports.refusal_messages.report_missing',
  source_not_visible: 'i18n.types.reports.refusal_messages.source_not_visible',
  tile_source_missing: 'i18n.types.reports.refusal_messages.tile_source_missing',
};

const REFUSAL_FALLBACK = 'i18n.types.reports.refusal_fallback';

const AGGREGATION_FRACTION_DIGITS: Record<ReportAggregation, number> = {
  count: 0,
  distinct_count: 0,
  avg: 2,
  sum: 10,
  min: 10,
  max: 10,
};

const FILE_NAME_LIMIT = 80;

const FILE_NAME_FALLBACK = 'report';

export function resolveReportState(
  result: ReportResult | null | undefined,
  refusal: ReportRefusal | null | undefined,
): ReportResultState {
  if (refusal !== null && refusal !== undefined) {
    return 'refused';
  }

  if (result === null || result === undefined) {
    return 'loading';
  }

  if (result.is_suppressed) {
    return 'suppressed';
  }

  if (result.record_count === 0) {
    return 'empty';
  }

  return 'ready';
}

export function hasPlottableRows(result: ReportResult): boolean {
  return result.rows.some((row) => parseAggregateValue(row.value) !== null);
}

export function parseAggregateValue(value: string | null): number | null {
  if (value === null || value.trim() === '') {
    return null;
  }

  const parsed = Number(value);

  return Number.isFinite(parsed) ? parsed : null;
}

export function formatAggregateValue(
  value: string | null,
  aggregation: ReportAggregation,
  currency: string | null = null,
): string {
  if (value === null) {
    return '—';
  }

  const parsed = parseAggregateValue(value);

  if (parsed !== null && currency !== null && CURRENCY_CODE_PATTERN.test(currency)) {
    return formatLocalizedNumber(parsed, { style: 'currency', currency });
  }

  if (parsed !== null) {
    return formatLocalizedNumber(parsed, {
      minimumFractionDigits: 0,
      maximumFractionDigits: AGGREGATION_FRACTION_DIGITS[aggregation],
    });
  }

  return Number.isNaN(new Date(value).getTime()) ? value : formatDateTime(value);
}

export function resolveRefusalMessage(reason: string, t: (key: string) => string): string {
  return t(REFUSAL_MESSAGES[reason] ?? REFUSAL_FALLBACK);
}

export function plottableChartType(presentation: ReportPresentation): ReportChartType | null {
  return presentation === 'metric' ? null : presentation;
}

export function aggregationAllowsFieldType(aggregation: ReportAggregation, fieldType: FieldType): boolean {
  if (aggregation === 'count' || aggregation === 'distinct_count') {
    return true;
  }

  if (aggregation === 'sum' || aggregation === 'avg') {
    return NUMERIC_FIELD_TYPES.includes(fieldType);
  }

  return NUMERIC_FIELD_TYPES.includes(fieldType) || TEMPORAL_FIELD_TYPES.includes(fieldType);
}

export function linkedAggregationAllowed(aggregation: ReportAggregation): boolean {
  return LINKED_AGGREGATIONS.includes(aggregation);
}

export function isQualifiedFieldKey(key: string): boolean {
  return QUALIFIED_FIELD_KEY_PATTERN.test(key);
}

export function reportFieldDisabledReason(
  aggregation: ReportAggregation,
  qualified: boolean,
  t: (key: string) => string,
): string {
  return t(
    qualified && !linkedAggregationAllowed(aggregation) ? LINKED_FIELD_DISABLED_REASON : FIELD_TYPE_DISABLED_REASON,
  );
}

export function reportDiscardedMessage(
  count: number,
  t: (key: string, parameters?: TranslationParameters) => string,
): string {
  return count === 1 ? t('i18n.types.reports.discarded_one') : t('i18n.types.reports.discarded_many', { count });
}

export function reportImageFileName(title: string, generatedAt: string): string {
  const parts = [sanitizeFileName(title), berlinDay(generatedAt)].filter((part) => part !== '');

  return `${parts.join('-')}.png`;
}

function sanitizeFileName(title: string): string {
  const cleaned = title
    .replace(/[^A-Za-z0-9ÄÖÜäöüß _-]+/g, '-')
    .replace(/[-\s]{2,}/g, '-')
    .slice(0, FILE_NAME_LIMIT)
    .replace(/^[-\s]+|[-\s]+$/g, '');

  return cleaned === '' ? FILE_NAME_FALLBACK : cleaned;
}

function berlinDay(value: string): string {
  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? '' : calendarDayKey(parsed, 'Europe/Berlin');
}
