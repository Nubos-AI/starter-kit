import { afterEach, describe, expect, it } from 'vitest';
import {
  addCalendarDays,
  calendarDayKey,
  formatDateTime,
  formatLocalizedDate,
  formatIsoDate,
  formatRelativeTime,
  formatTimelineMoment,
} from '@/shared/lib/formatDate';
import { t } from '@/shared/testing/i18n';

const now = new Date('2026-08-26T16:00:00Z').getTime();

describe('formatTimelineMoment', () => {
  it.each([
    ['says today for a moment from the same day', '2026-08-26T12:41:00Z', 'heute um 12:41 Uhr'],
    ['says yesterday for the day before', '2026-08-25T07:05:00Z', 'gestern um 07:05 Uhr'],
    ['names the weekday inside the last week', '2026-08-24T12:41:00Z', 'letzten Montag um 12:41 Uhr'],
    ['falls back to the day and month once the week is over', '2026-08-17T11:37:00Z', '17. August 11:37'],
    ['adds the year for a moment from another year', '2025-12-11T07:15:00Z', '11. Dezember 2025 07:15'],
    ['stays silent about an unreadable moment', 'not-a-date', '—'],
    ['stays silent about a missing moment', null, '—'],
  ])('%s', (_case, value, expected) => {
    expect(formatTimelineMoment(value, t, now)).toBe(expected);
  });
});

describe('formatIsoDate — a calendar date never shifts across a time zone', () => {
  it('renders a plain day in German order', () => {
    expect(formatIsoDate('2026-08-29')).toBe('29.08.2026');
  });

  it('keeps the day it was given regardless of the local offset', () => {
    expect(formatIsoDate('2026-01-01')).toBe('01.01.2026');
    expect(formatIsoDate('2026-12-31')).toBe('31.12.2026');
  });

  it('falls back to a dash without a value', () => {
    expect(formatIsoDate(null)).toBe('—');
  });

  it('falls back to a dash on anything that is not a plain day', () => {
    expect(formatIsoDate('2026-08-29T23:59:59.999Z')).toBe('—');
  });
});

describe('dates follow the active language', () => {
  afterEach(() => {
    document.documentElement.lang = '';
  });

  it('writes a calendar day in the American order in English', () => {
    document.documentElement.lang = 'en';

    expect(formatIsoDate('2026-10-06')).toBe('10/06/2026');
  });

  it('writes a moment in the notation of the active language', () => {
    document.documentElement.lang = 'en';

    expect(formatDateTime('2026-10-06T14:05:00Z')).toBe('10/6/2026, 2:05:00 PM');
  });

  it('speaks a relative moment in the active language', () => {
    document.documentElement.lang = 'en';

    expect(formatRelativeTime('2026-08-25T16:00:00Z', now)).toBe('yesterday');
  });
});

describe('formatLocalizedDate — the d() behind useI18n', () => {
  it('writes a moment with the given options in the active language', () => {
    expect(
      formatLocalizedDate('2026-10-06T14:05:00Z', {
        dateStyle: 'medium',
        timeStyle: 'short',
      }),
    ).toBe('06.10.2026, 14:05');
  });

  it('accepts a Date as well as an ISO string', () => {
    expect(
      formatLocalizedDate(new Date('2026-10-06T14:05:00Z'), {
        day: 'numeric',
        month: 'long',
      }),
    ).toBe('6. Oktober');
  });

  it('writes in the locale it was handed instead of the document language', () => {
    expect(formatLocalizedDate('2026-10-06T14:05:00Z', { month: 'long', year: 'numeric' }, 'en')).toBe('October 2026');
  });

  it('falls back to a dash for a missing or unreadable moment', () => {
    expect(formatLocalizedDate(null)).toBe('—');
    expect(formatLocalizedDate(undefined)).toBe('—');
    expect(formatLocalizedDate('')).toBe('—');
    expect(formatLocalizedDate('not-a-date')).toBe('—');
  });
});

describe('calendarDayKey — a fixed technical day key', () => {
  it('names the day of a moment in the given time zone as YYYY-MM-DD', () => {
    expect(calendarDayKey(new Date('2026-10-06T22:30:00Z'), 'Europe/Berlin')).toBe('2026-10-07');
    expect(calendarDayKey(new Date('2026-10-06T22:30:00Z'), 'UTC')).toBe('2026-10-06');
  });

  it('ignores the active language', () => {
    document.documentElement.lang = 'en';

    expect(calendarDayKey(new Date('2026-01-05T12:00:00Z'), 'Europe/Berlin')).toBe('2026-01-05');

    document.documentElement.lang = '';
  });
});

describe('addCalendarDays', () => {
  it('moves a day key forwards and backwards across months', () => {
    expect(addCalendarDays('2026-01-31', 1)).toBe('2026-02-01');
    expect(addCalendarDays('2026-03-01', -1)).toBe('2026-02-28');
  });

  it('answers an empty key for an unreadable day', () => {
    expect(addCalendarDays('not-a-day', 3)).toBe('');
  });
});
