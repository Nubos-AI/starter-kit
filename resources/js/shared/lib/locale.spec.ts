import { afterEach, describe, expect, it, vi } from 'vitest';
import { activeLocale, dateTimeFormatter, numberFormatter } from '@/shared/lib/locale';

afterEach(() => {
  document.documentElement.lang = '';
});

describe('activeLocale', () => {
  it('follows the language the server rendered the document in', () => {
    document.documentElement.lang = 'en';

    expect(activeLocale()).toBe('en');
  });

  it('falls back to German while the document names no language', () => {
    expect(activeLocale()).toBe('de');
  });

  it('answers German during server rendering, where no document exists', () => {
    vi.stubGlobal('document', undefined);

    try {
      expect(activeLocale()).toBe('de');
    } finally {
      vi.unstubAllGlobals();
    }
  });
});

describe('shared Intl formatters', () => {
  it('builds a number formatter for the active language unless a locale is named', () => {
    expect(numberFormatter().format(1234.5)).toBe('1.234,5');
    expect(numberFormatter({}, 'en').format(1234.5)).toBe('1,234.5');
  });

  it('builds a date formatter for the active language unless a locale is named', () => {
    const options: Intl.DateTimeFormatOptions = {
      month: 'long',
      timeZone: 'UTC',
    };

    expect(dateTimeFormatter(options).format(new Date('2026-03-01T00:00:00Z'))).toBe('März');
    expect(dateTimeFormatter(options, 'en').format(new Date('2026-03-01T00:00:00Z'))).toBe('March');
  });

  it('reuses one formatter per locale and options', () => {
    expect(numberFormatter({ style: 'percent' }, 'de')).toBe(numberFormatter({ style: 'percent' }, 'de'));
    expect(dateTimeFormatter({ year: 'numeric' }, 'en')).toBe(dateTimeFormatter({ year: 'numeric' }, 'en'));
    expect(numberFormatter({}, 'de')).not.toBe(numberFormatter({}, 'en'));
  });
});
