import { normalizeFieldOptions } from '@/composables/useFieldTypeRegistry';
import type { FieldDefinition, FieldType } from '@/types/fields';

const integerFormatter = new Intl.NumberFormat('de-DE', {
    maximumFractionDigits: 0,
});

const decimalFormatter = new Intl.NumberFormat('de-DE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const moneyFormatter = new Intl.NumberFormat('de-DE', {
    style: 'currency',
    currency: 'EUR',
});

const dayFormatter = new Intl.DateTimeFormat('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
});

const dayTimeFormatter = new Intl.DateTimeFormat('de-DE', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

const ISO_MOMENT =
    /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/;

const BOOLEAN_TRUE_LABEL = 'Ja';

const BOOLEAN_FALSE_LABEL = 'Nein';

function isBlank(value: unknown): boolean {
    return value === null || value === undefined || value === '';
}

function formatNumber(value: unknown, formatter: Intl.NumberFormat): string {
    const parsed = Number(value);

    return Number.isNaN(parsed) ? String(value) : formatter.format(parsed);
}

function formatMoment(value: unknown, formatter: Intl.DateTimeFormat): string {
    const parsed = new Date(String(value));

    return Number.isNaN(parsed.getTime())
        ? String(value)
        : formatter.format(parsed);
}

function optionLabels(field: FieldDefinition): Map<string, string> {
    return new Map(
        normalizeFieldOptions(field.config?.options).map((option) => [
            option.value,
            option.label,
        ]),
    );
}

function joinValues(value: unknown, labels: Map<string, string>): string {
    const entries = Array.isArray(value) ? value : [value];

    return entries
        .map((entry) => labels.get(String(entry)) ?? String(entry))
        .join(', ');
}

export function formatFieldValue(
    value: unknown,
    field: FieldDefinition | undefined,
): string {
    if (isBlank(value)) {
        return '';
    }

    if (field === undefined) {
        return formatUntypedValue(value);
    }

    const type: FieldType = field.field_type;

    if (type === 'number') {
        return formatNumber(value, integerFormatter);
    }

    if (type === 'decimal' || type === 'rollup') {
        return formatNumber(value, decimalFormatter);
    }

    if (type === 'money') {
        return formatNumber(value, moneyFormatter);
    }

    if (type === 'date') {
        return formatMoment(value, dayFormatter);
    }

    if (type === 'datetime') {
        return formatMoment(value, dayTimeFormatter);
    }

    if (type === 'boolean') {
        return value === true || value === 'true' || value === 1
            ? BOOLEAN_TRUE_LABEL
            : BOOLEAN_FALSE_LABEL;
    }

    if (type === 'single_select' || type === 'multi_select') {
        return joinValues(value, optionLabels(field));
    }

    return stringify(value);
}

function formatUntypedValue(value: unknown): string {
    if (typeof value !== 'string') {
        return stringify(value);
    }

    if (ISO_DAY.test(value)) {
        return formatMoment(value, dayFormatter);
    }

    if (ISO_MOMENT.test(value)) {
        return formatMoment(value, dayTimeFormatter);
    }

    return value;
}

function stringify(value: unknown): string {
    if (Array.isArray(value)) {
        return value.map((entry) => stringify(entry)).join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
