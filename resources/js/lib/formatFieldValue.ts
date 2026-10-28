import { normalizeFieldOptions } from '@/composables/useFieldTypeRegistry';
import { activeLocale } from '@/lib/locale';
import { formatLocalizedNumber } from '@/lib/localizedNumber';
import type { FieldDefinition, FieldType } from '@/types/fields';
import { formulaErrorLabels, isFormulaErrorValue } from '@/types/formulas';

const INTEGER_FORMAT: Intl.NumberFormatOptions = {
    maximumFractionDigits: 0,
};

const DECIMAL_FORMAT: Intl.NumberFormatOptions = {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
};

export function fieldCurrency(
    field: FieldDefinition,
    data: Record<string, unknown> = {},
): string | null {
    const key = field.config?.currency_field;
    const currency =
        typeof key === 'string' ? data[key] : (field.config?.currency ?? 'EUR');

    return typeof currency === 'string' && /^[A-Z]{3}$/.test(currency)
        ? currency
        : null;
}

const DAY_FORMAT: Intl.DateTimeFormatOptions = {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
};

const DAY_TIME_FORMAT: Intl.DateTimeFormatOptions = {
    dateStyle: 'medium',
    timeStyle: 'short',
};

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

const ISO_MOMENT =
    /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/;

const BOOLEAN_TRUE_LABEL = 'Ja';

const BOOLEAN_FALSE_LABEL = 'Nein';

function isBlank(value: unknown): boolean {
    return value === null || value === undefined || value === '';
}

function formatMoment(
    value: unknown,
    options: Intl.DateTimeFormatOptions,
): string {
    const parsed = new Date(String(value));

    return Number.isNaN(parsed.getTime())
        ? String(value)
        : new Intl.DateTimeFormat(activeLocale(), options).format(parsed);
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
    data: Record<string, unknown> = {},
): string {
    if (isBlank(value)) {
        return '';
    }

    if (isFormulaErrorValue(value)) {
        return formulaErrorLabels[value.code];
    }

    if (field === undefined) {
        return formatUntypedValue(value);
    }

    const type: FieldType = field.field_type;

    if (type === 'number') {
        return formatLocalizedNumber(value, INTEGER_FORMAT);
    }

    if (type === 'decimal' || type === 'rollup') {
        return formatLocalizedNumber(value, DECIMAL_FORMAT);
    }

    if (type === 'money') {
        const currency = fieldCurrency(field, data);

        return formatLocalizedNumber(
            value,
            currency === null
                ? DECIMAL_FORMAT
                : { style: 'currency', currency },
        );
    }

    if (type === 'date') {
        return formatMoment(value, DAY_FORMAT);
    }

    if (type === 'datetime') {
        return formatMoment(value, DAY_TIME_FORMAT);
    }

    if (type === 'boolean') {
        return formatBoolean(value);
    }

    if (type === 'computed') {
        return formatFormulaResult(value, field);
    }

    if (type === 'single_select' || type === 'multi_select') {
        return joinValues(value, optionLabels(field));
    }

    return stringify(value);
}

function formatBoolean(value: unknown): string {
    return value === true || value === 'true' || value === 1
        ? BOOLEAN_TRUE_LABEL
        : BOOLEAN_FALSE_LABEL;
}

function scaleFormat(scale: unknown): Intl.NumberFormatOptions {
    return typeof scale === 'number'
        ? { minimumFractionDigits: scale, maximumFractionDigits: scale }
        : {};
}

function formatFormulaResult(value: unknown, field: FieldDefinition): string {
    const resultType = field.config?.result_type;

    if (resultType === 'number') {
        return formatLocalizedNumber(value, scaleFormat(field.config?.scale));
    }

    if (resultType === 'date') {
        return formatMoment(value, DAY_FORMAT);
    }

    if (resultType === 'boolean') {
        return formatBoolean(value);
    }

    return stringify(value);
}

function formatUntypedValue(value: unknown): string {
    if (typeof value !== 'string') {
        return stringify(value);
    }

    if (ISO_DAY.test(value)) {
        return formatMoment(value, DAY_FORMAT);
    }

    if (ISO_MOMENT.test(value)) {
        return formatMoment(value, DAY_TIME_FORMAT);
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
