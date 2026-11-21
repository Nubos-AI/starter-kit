import { afterEach, describe, expect, it } from 'vitest';
import { formatLocalizedNumber, numberInputFormat, parseLocalizedNumber } from '@/shared/lib/localizedNumber';

afterEach(() => {
  document.documentElement.lang = '';
});

describe('parseLocalizedNumber', () => {
  it('reads German notation into the canonical value', () => {
    expect(parseLocalizedNumber('23.123,45')).toBe('23123.45');
    expect(parseLocalizedNumber('-12,5')).toBe('-12.5');
  });

  it('reads American notation into the canonical value in English', () => {
    document.documentElement.lang = 'en';

    expect(parseLocalizedNumber('23,123.45')).toBe('23123.45');
  });

  it('keeps an empty entry empty and leaves text that is no number alone', () => {
    expect(parseLocalizedNumber('  ')).toBe('');
    expect(parseLocalizedNumber('abc')).toBe('abc');
  });
});

describe('formatLocalizedNumber', () => {
  it('writes a value in the notation of the active language', () => {
    expect(formatLocalizedNumber('23123.45', numberInputFormat('money'))).toBe('23.123,45');

    document.documentElement.lang = 'en';

    expect(formatLocalizedNumber(23123.45, numberInputFormat('money'))).toBe('23,123.45');
  });

  it('leaves a blank or unreadable value as it is', () => {
    expect(formatLocalizedNumber(null)).toBe('');
    expect(formatLocalizedNumber('abc')).toBe('abc');
  });
});

describe('numberInputFormat', () => {
  it('keeps whole numbers whole, amounts at two digits and decimals as entered', () => {
    expect(formatLocalizedNumber(1234.6, numberInputFormat('number'))).toBe('1.235');
    expect(formatLocalizedNumber(12, numberInputFormat('money'))).toBe('12,00');
    expect(formatLocalizedNumber('0.123456', numberInputFormat('decimal'))).toBe('0,123456');
  });
});

describe('formatLocalizedNumber — the n() behind useI18n', () => {
  it('writes an amount of money with its currency', () => {
    expect(
      formatLocalizedNumber(1234.5, {
        style: 'currency',
        currency: 'EUR',
      }),
    ).toBe('1.234,50\u00a0€');
  });

  it('writes a share as a percentage', () => {
    expect(
      formatLocalizedNumber(0.256, {
        style: 'percent',
        maximumFractionDigits: 1,
      }),
    ).toBe('25,6\u00a0%');
  });

  it('writes in the locale it was handed instead of the document language', () => {
    expect(formatLocalizedNumber(1234.5, {}, 'en')).toBe('1,234.5');
  });

  it('keeps every digit of a decimal string such as a stored amount', () => {
    expect(
      formatLocalizedNumber('12345678901234567.89', {
        minimumFractionDigits: 2,
      }),
    ).toBe('12.345.678.901.234.567,89');
  });
});
