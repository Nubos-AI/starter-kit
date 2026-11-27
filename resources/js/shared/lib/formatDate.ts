import type { TranslationParameters } from '@/shared/lib/i18n';
import { activeLocale, dateTimeFormatter } from '@/shared/lib/locale';

const RELATIVE_TIME_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
  ['year', 365 * 24 * 60 * 60],
  ['month', 30 * 24 * 60 * 60],
  ['week', 7 * 24 * 60 * 60],
  ['day', 24 * 60 * 60],
  ['hour', 60 * 60],
  ['minute', 60],
];

const ISO_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

const DATE_TIME_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'numeric',
  day: 'numeric',
  hour: 'numeric',
  minute: 'numeric',
  second: 'numeric',
};

const DAY_KEY_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
};

export type DateInput = string | number | Date | null | undefined;

export function formatLocalizedDate(
  value: DateInput,
  options: Intl.DateTimeFormatOptions = {},
  locale: string = activeLocale(),
): string {
  if (value === null || value === undefined || value === '') {
    return '—';
  }

  const parsed = value instanceof Date ? value : new Date(value);

  return Number.isNaN(parsed.getTime()) ? '—' : dateTimeFormatter(options, locale).format(parsed);
}

const millisecondsPerDay = 86400000;

export function addCalendarDays(key: string, days: number): string {
  const start = Date.parse(`${key}T00:00:00Z`);

  if (Number.isNaN(start)) {
    return '';
  }

  return new Date(start + days * millisecondsPerDay).toISOString().slice(0, 10);
}

export function calendarDayKey(value: Date, timeZone: string): string {
  return dateTimeFormatter({ ...DAY_KEY_FORMAT, timeZone }, 'en-CA').format(value);
}

export function formatIsoDate(value: string | null): string {
  const match = value === null ? null : ISO_DATE_PATTERN.exec(value);

  if (match === null) {
    return '—';
  }

  return formatLocalizedDate(`${match[1]}-${match[2]}-${match[3]}T00:00:00Z`, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
  });
}

export function formatDateTime(value: string | null): string {
  return formatLocalizedDate(value, DATE_TIME_FORMAT);
}

export function formatRelativeTime(value: string, now: number = Date.now()): string {
  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return '—';
  }

  const relativeTimeFormatter = new Intl.RelativeTimeFormat(activeLocale(), {
    numeric: 'auto',
  });
  const seconds = Math.round((parsed.getTime() - now) / 1000);
  const distance = Math.abs(seconds);

  for (const [unit, size] of RELATIVE_TIME_UNITS) {
    if (distance >= size) {
      return relativeTimeFormatter.format(Math.round(seconds / size), unit);
    }
  }

  return relativeTimeFormatter.format(seconds, 'second');
}

const WEEKDAY_FORMAT: Intl.DateTimeFormatOptions = {
  weekday: 'long',
};

const CLOCK_FORMAT: Intl.DateTimeFormatOptions = {
  hour: '2-digit',
  minute: '2-digit',
};

const DAY_FORMAT: Intl.DateTimeFormatOptions = {
  day: 'numeric',
  month: 'long',
};

const DAY_WITH_YEAR_FORMAT: Intl.DateTimeFormatOptions = {
  day: 'numeric',
  month: 'long',
  year: 'numeric',
};

const DAYS_IN_A_WEEK = 7;

function startOfDay(value: Date): number {
  return new Date(value.getFullYear(), value.getMonth(), value.getDate()).getTime();
}

export function formatTimelineMoment(
  value: string | null,
  t: (key: string, parameters?: TranslationParameters) => string,
  now: number = Date.now(),
): string {
  if (value === null) {
    return '—';
  }

  const parsed = new Date(value);

  if (Number.isNaN(parsed.getTime())) {
    return '—';
  }

  const reference = new Date(now);
  const dayDistance = Math.round((startOfDay(reference) - startOfDay(parsed)) / 86400000);
  const clock = formatLocalizedDate(parsed, CLOCK_FORMAT);

  if (dayDistance === 0) {
    return t('i18n.lib.format_date.today_at', { value1: clock });
  }

  if (dayDistance === 1) {
    return t('i18n.lib.format_date.yesterday_at', { value1: clock });
  }

  if (dayDistance > 1 && dayDistance < DAYS_IN_A_WEEK) {
    return t('i18n.lib.format_date.last_weekday_at', {
      value1: formatLocalizedDate(parsed, WEEKDAY_FORMAT),
      value2: clock,
    });
  }

  const sameYear = parsed.getFullYear() === reference.getFullYear();
  const day = sameYear ? formatLocalizedDate(parsed, DAY_FORMAT) : formatLocalizedDate(parsed, DAY_WITH_YEAR_FORMAT);

  return `${day} ${clock}`;
}
