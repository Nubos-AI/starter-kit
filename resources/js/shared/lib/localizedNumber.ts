import { activeLocale, numberFormatter } from '@/shared/lib/locale';
import type { FieldType } from '@/shared/types/fields';

interface NumberSeparators {
  group: string;
  decimal: string;
}

const numberInputFormats: Partial<Record<FieldType, Intl.NumberFormatOptions>> = {
  number: { maximumFractionDigits: 0 },
  decimal: { maximumFractionDigits: 20 },
  money: { minimumFractionDigits: 2, maximumFractionDigits: 2 },
};

export function isDecimalText(value: string): value is `${number}` {
  return /^-?\d+(\.\d+)?$/.test(value);
}

function separators(): NumberSeparators {
  const parts = numberFormatter().formatToParts(12345.6);

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
  locale: string = activeLocale(),
): string {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  if (typeof value === 'string' && isDecimalText(value)) {
    return numberFormatter(options, locale).format(value);
  }

  const parsed = typeof value === 'number' ? value : Number(value);

  return Number.isFinite(parsed) ? numberFormatter(options, locale).format(parsed) : String(value);
}

export function parseLocalizedNumber(text: string): string {
  const trimmed = text.trim();

  if (trimmed === '') {
    return '';
  }

  const { group, decimal } = separators();
  const canonical = trimmed.replace(/\s/g, '').split(group).join('').replace(decimal, '.');

  return Number.isFinite(Number(canonical)) ? canonical : trimmed;
}
