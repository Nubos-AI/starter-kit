const RELATIVE_TIME_UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['week', 7 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

const relativeTimeFormatter = new Intl.RelativeTimeFormat('de-DE', {
    numeric: 'auto',
});

const ISO_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

export function formatIsoDate(value: string | null): string {
    const match = value === null ? null : ISO_DATE_PATTERN.exec(value);

    return match === null ? '—' : `${match[3]}.${match[2]}.${match[1]}`;
}

export function formatDateTime(value: string | null): string {
    if (value === null) {
        return '—';
    }

    const parsed = new Date(value);

    return Number.isNaN(parsed.getTime())
        ? '—'
        : parsed.toLocaleString('de-DE');
}

export function formatRelativeTime(
    value: string,
    now: number = Date.now(),
): string {
    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return '—';
    }

    const seconds = Math.round((parsed.getTime() - now) / 1000);
    const distance = Math.abs(seconds);

    for (const [unit, size] of RELATIVE_TIME_UNITS) {
        if (distance >= size) {
            return relativeTimeFormatter.format(
                Math.round(seconds / size),
                unit,
            );
        }
    }

    return relativeTimeFormatter.format(seconds, 'second');
}

const WEEKDAY_FORMATTER = new Intl.DateTimeFormat('de-DE', {
    weekday: 'long',
});

const CLOCK_FORMATTER = new Intl.DateTimeFormat('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
});

const DAY_FORMATTER = new Intl.DateTimeFormat('de-DE', {
    day: 'numeric',
    month: 'long',
});

const DAY_WITH_YEAR_FORMATTER = new Intl.DateTimeFormat('de-DE', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

const DAYS_IN_A_WEEK = 7;

function startOfDay(value: Date): number {
    return new Date(
        value.getFullYear(),
        value.getMonth(),
        value.getDate(),
    ).getTime();
}

export function formatTimelineMoment(
    value: string | null,
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
    const dayDistance = Math.round(
        (startOfDay(reference) - startOfDay(parsed)) / 86400000,
    );
    const clock = CLOCK_FORMATTER.format(parsed);

    if (dayDistance === 0) {
        return `heute um ${clock} Uhr`;
    }

    if (dayDistance === 1) {
        return `gestern um ${clock} Uhr`;
    }

    if (dayDistance > 1 && dayDistance < DAYS_IN_A_WEEK) {
        return `letzten ${WEEKDAY_FORMATTER.format(parsed)} um ${clock} Uhr`;
    }

    const sameYear = parsed.getFullYear() === reference.getFullYear();
    const day = sameYear
        ? DAY_FORMATTER.format(parsed)
        : DAY_WITH_YEAR_FORMATTER.format(parsed);

    return `${day} ${clock}`;
}
