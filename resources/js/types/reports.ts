import type { FilterGroupNode } from '@/composables/useFilterTree';
import { formatDateTime } from '@/lib/formatDate';
import type { FieldType } from '@/types/fields';

export type ReportAggregation =
    | 'count'
    | 'sum'
    | 'avg'
    | 'min'
    | 'max'
    | 'distinct_count';

export type ReportExecutionMode = 'viewer' | 'definer';

export type ReportGroupingBucket =
    | 'day'
    | 'week'
    | 'month'
    | 'quarter'
    | 'year';

export type ReportChartType = 'bar' | 'line' | 'area' | 'pie' | 'donut';

export type ReportBarMode = 'grouped' | 'stacked';

export type ReportResultState =
    | 'loading'
    | 'refused'
    | 'suppressed'
    | 'empty'
    | 'ready';

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

export type ReportActionRefusalReason =
    | 'not_owner'
    | 'object_type_not_permitted';

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
}

export interface ReportListRow extends ReportDefinition {
    id: string;
    name: string;
    description: string | null;
    object_type: ReportObjectTypeRef | null;
    is_owner: boolean;
    can_update: boolean;
    can_delete: boolean;
    update_reason: string | null;
    delete_reason: string | null;
    updated_at: string | null;
}

export interface ReportDrillDownSelection {
    group: string;
    series: string | null;
}

export interface ReportDrillDownReportParams {
    report: string;
    dashboard?: null;
    widget?: null;
    group: string;
    series: string | null;
}

export interface ReportDrillDownWidgetParams {
    report?: null;
    dashboard: string;
    widget: string;
    group: string;
    series: string | null;
}

export type ReportDrillDownParams =
    | ReportDrillDownReportParams
    | ReportDrillDownWidgetParams;

export const OTHER_LABEL = 'Sonstige';

export const UNSPECIFIED_LABEL = 'Ohne Angabe';

export const REPORT_SUPPRESSED_MESSAGE =
    'Für diese Auswertung liegen zu wenige Einträge je Gruppe vor, um sie darzustellen.';

export const REPORT_EMPTY_MESSAGE =
    'Für diese Auswertung gibt es noch keine Datensätze.';

export const REPORT_AGGREGATION_LABELS: Record<ReportAggregation, string> = {
    count: 'Anzahl',
    sum: 'Summe',
    avg: 'Durchschnitt',
    min: 'Minimum',
    max: 'Maximum',
    distinct_count: 'Verschiedene Werte',
};

export const REPORT_PRESENTATION_LABELS: Record<ReportPresentation, string> = {
    metric: 'Kennzahl',
    bar: 'Balken',
    line: 'Linie',
    area: 'Fläche',
    pie: 'Torte',
    donut: 'Donut',
};

export const REPORT_BUCKET_LABELS: Record<ReportGroupingBucket, string> = {
    day: 'Tag',
    week: 'Woche',
    month: 'Monat',
    quarter: 'Quartal',
    year: 'Jahr',
};

const ACTION_REFUSAL_MESSAGES: Record<string, string> = {
    not_owner:
        'Nur die Person, der diese Auswertung gehört, darf sie ändern oder löschen.',
    object_type_not_permitted:
        'Für den Objekttyp dieser Auswertung fehlt Ihnen die Leseberechtigung.',
};

const ACTION_REFUSAL_FALLBACK =
    'Diese Auswertung lässt sich mit Ihren Rechten nicht bearbeiten.';

const LINKED_FIELD_DISABLED_REASON =
    'Verknüpfte Felder lassen sich nur zählen, summieren oder als Minimum und Maximum auswerten.';

const FIELD_TYPE_DISABLED_REASON =
    'Dieses Feld passt nicht zur gewählten Berechnung.';

const NUMERIC_FIELD_TYPES: FieldType[] = [
    'number',
    'decimal',
    'money',
    'computed',
    'rollup',
];

const TEMPORAL_FIELD_TYPES: FieldType[] = ['date', 'datetime'];

const LINKED_AGGREGATIONS: ReportAggregation[] = ['count', 'sum', 'min', 'max'];

const QUALIFIED_FIELD_KEY_PATTERN = /^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/;

const REFUSAL_MESSAGES: Record<string, string> = {
    malformed_definition:
        'Diese Auswertung ist unvollständig oder widersprüchlich eingerichtet. Bitte prüfen Sie ihre Einstellungen.',
    unknown_field:
        'Diese Auswertung greift auf etwas zurück, das es nicht mehr gibt. Bitte richten Sie sie neu ein.',
    field_not_readable:
        'Sie dürfen einen der Werte, auf denen diese Auswertung beruht, nicht sehen.',
    encrypted_field:
        'Verschlüsselte Werte lassen sich weder zählen noch zusammenfassen oder gruppieren.',
    field_not_filterable:
        'Einer der Werte, auf denen diese Auswertung beruht, lässt sich nicht zum Filtern verwenden.',
    unsupported_aggregation:
        'Diese Berechnung lässt sich auf die gewählte Art von Wert nicht anwenden.',
    unsupported_grouping:
        'Eine Gruppierung nach Zeitraum setzt ein Datum oder einen Zeitpunkt voraus.',
    invalid_filter_tree:
        'Die Einschränkung dieser Auswertung konnte nicht angewendet werden. Bitte prüfen Sie sie und versuchen Sie es erneut.',
    report_missing:
        'Die Auswertung hinter dieser Kachel gibt es nicht mehr. Bitte wählen Sie eine andere aus.',
    source_not_visible:
        'Sie dürfen die Datenquelle dieser Kachel nicht auswerten.',
};

const REFUSAL_FALLBACK =
    'Diese Auswertung kann derzeit nicht ausgeführt werden.';

const AGGREGATION_FRACTION_DIGITS: Record<ReportAggregation, number> = {
    count: 0,
    distinct_count: 0,
    avg: 2,
    sum: 10,
    min: 10,
    max: 10,
};

const COLLECTOR_TOKEN = 'other';

const EMPTY_TOKEN = 'null';

const VALUE_TOKEN_PREFIX = 'v:';

const FILE_NAME_LIMIT = 80;

const FILE_NAME_FALLBACK = 'report';

const berlinDayFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

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
): string {
    if (value === null) {
        return '—';
    }

    const parsed = parseAggregateValue(value);

    if (parsed !== null) {
        return new Intl.NumberFormat('de-DE', {
            minimumFractionDigits: 0,
            maximumFractionDigits: AGGREGATION_FRACTION_DIGITS[aggregation],
        }).format(parsed);
    }

    return Number.isNaN(new Date(value).getTime())
        ? value
        : formatDateTime(value);
}

export function resolveRefusalMessage(reason: string): string {
    return REFUSAL_MESSAGES[reason] ?? REFUSAL_FALLBACK;
}

export function plottableChartType(
    presentation: ReportPresentation,
): ReportChartType | null {
    return presentation === 'metric' ? null : presentation;
}

export function drillDownToken(value: string | null, isOther: boolean): string {
    if (isOther) {
        return COLLECTOR_TOKEN;
    }

    return value === null ? EMPTY_TOKEN : `${VALUE_TOKEN_PREFIX}${value}`;
}

export function isCollectorToken(token: string): boolean {
    return token === COLLECTOR_TOKEN;
}

export function resolveReportActionRefusal(
    reason: string | null,
): string | undefined {
    if (reason === null) {
        return undefined;
    }

    return ACTION_REFUSAL_MESSAGES[reason] ?? ACTION_REFUSAL_FALLBACK;
}

export function aggregationAllowsFieldType(
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

export function linkedAggregationAllowed(
    aggregation: ReportAggregation,
): boolean {
    return LINKED_AGGREGATIONS.includes(aggregation);
}

export function isQualifiedFieldKey(key: string): boolean {
    return QUALIFIED_FIELD_KEY_PATTERN.test(key);
}

export function reportFieldDisabledReason(
    aggregation: ReportAggregation,
    qualified: boolean,
): string {
    return qualified && !linkedAggregationAllowed(aggregation)
        ? LINKED_FIELD_DISABLED_REASON
        : FIELD_TYPE_DISABLED_REASON;
}

export function hydratableFilterTree(
    value: unknown,
): FilterGroupNode | undefined {
    return isFilterGroupNode(value) ? value : undefined;
}

export function normalizeFilterTree(
    tree: FilterGroupNode | null,
): FilterGroupNode | null {
    return tree !== null && carriesCondition(tree) ? tree : null;
}

function isFilterGroupNode(value: unknown): value is FilterGroupNode {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }

    return (
        'combinator' in value &&
        'conditions' in value &&
        (value.combinator === 'and' || value.combinator === 'or') &&
        Array.isArray(value.conditions)
    );
}

function carriesCondition(node: FilterGroupNode): boolean {
    return node.conditions.some((child) =>
        'combinator' in child ? carriesCondition(child) : true,
    );
}

export function reportDiscardedMessage(count: number): string {
    return count === 1
        ? '1 Datensatz ohne auswertbaren Wert wurde nicht berücksichtigt.'
        : `${count} Datensätze ohne auswertbaren Wert wurden nicht berücksichtigt.`;
}

export function reportImageFileName(
    title: string,
    generatedAt: string,
): string {
    const parts = [sanitizeFileName(title), berlinDay(generatedAt)].filter(
        (part) => part !== '',
    );

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

    return Number.isNaN(parsed.getTime())
        ? ''
        : berlinDayFormatter.format(parsed);
}
