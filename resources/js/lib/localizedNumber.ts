import { activeLocale } from '@/lib/locale';
import type { FieldType } from '@/types/fields';

interface NumberSeparators {
    group: string;
    decimal: string;
}

const numberInputFormats: Partial<Record<FieldType, Intl.NumberFormatOptions>> =
    {
        number: { maximumFractionDigits: 0 },
        decimal: { maximumFractionDigits: 20 },
        money: { minimumFractionDigits: 2, maximumFractionDigits: 2 },
    };

function separators(): NumberSeparators {
    const parts = new Intl.NumberFormat(activeLocale()).formatToParts(12345.6);

    return {
        group: parts.find((part) => part.type === 'group')?.value ?? '',
        decimal: parts.find((part) => part.type === 'decimal')?.value ?? '.',
    };
}

export function numberInputFormat(type: FieldType): Intl.NumberFormatOptions {
    return numberInputFormats[type] ?? {};
}

export function formatLocalizedNumber(
    value: unknown,
    options: Intl.NumberFormatOptions = {},
): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const parsed = typeof value === 'number' ? value : Number(value);

    return Number.isFinite(parsed)
        ? new Intl.NumberFormat(activeLocale(), options).format(parsed)
        : String(value);
}

export function parseLocalizedNumber(text: string): string {
    const trimmed = text.trim();

    if (trimmed === '') {
        return '';
    }

    const { group, decimal } = separators();
    const canonical = trimmed
        .replace(/\s/g, '')
        .split(group)
        .join('')
        .replace(decimal, '.');

    return Number.isFinite(Number(canonical)) ? canonical : trimmed;
}
