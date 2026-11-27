const numberFormatters = new Map<string, Intl.NumberFormat>();

const dateTimeFormatters = new Map<string, Intl.DateTimeFormat>();

export function activeLocale(): string {
  if (typeof document === 'undefined') {
    return 'de';
  }

  return document.documentElement.lang || 'de';
}

export function numberFormatter(
  options: Intl.NumberFormatOptions = {},
  locale: string = activeLocale(),
): Intl.NumberFormat {
  const key = `${locale}|${JSON.stringify(options)}`;
  const cached = numberFormatters.get(key);

  if (cached !== undefined) {
    return cached;
  }

  const formatter = new Intl.NumberFormat(locale, options);

  numberFormatters.set(key, formatter);

  return formatter;
}

export function dateTimeFormatter(
  options: Intl.DateTimeFormatOptions = {},
  locale: string = activeLocale(),
): Intl.DateTimeFormat {
  const key = `${locale}|${JSON.stringify(options)}`;
  const cached = dateTimeFormatters.get(key);

  if (cached !== undefined) {
    return cached;
  }

  const formatter = new Intl.DateTimeFormat(locale, options);

  dateTimeFormatters.set(key, formatter);

  return formatter;
}
