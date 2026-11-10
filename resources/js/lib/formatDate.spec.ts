import { afterEach, describe, expect, it } from 'vitest';
import {
    formatDateTime,
    formatIsoDate,
    formatRelativeTime,
    formatTimelineMoment,
} from '@/lib/formatDate';

const now = new Date('2026-08-26T16:00:00Z').getTime();

describe('formatTimelineMoment', () => {
    it.each([
        [
            'says today for a moment from the same day',
            '2026-08-26T12:41:00Z',
            'heute um 12:41 Uhr',
        ],
        [
            'says yesterday for the day before',
            '2026-08-25T07:05:00Z',
            'gestern um 07:05 Uhr',
        ],
        [
            'names the weekday inside the last week',
            '2026-08-24T12:41:00Z',
            'letzten Montag um 12:41 Uhr',
        ],
        [
            'falls back to the day and month once the week is over',
            '2026-08-17T11:37:00Z',
            '17. August 11:37',
        ],
        [
            'adds the year for a moment from another year',
            '2025-12-11T07:15:00Z',
            '11. Dezember 2025 07:15',
        ],
        ['stays silent about an unreadable moment', 'not-a-date', '—'],
        ['stays silent about a missing moment', null, '—'],
    ])('%s', (_case, value, expected) => {
        expect(formatTimelineMoment(value, now)).toBe(expected);
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

        expect(formatDateTime('2026-10-06T14:05:00Z')).toBe(
            '10/6/2026, 2:05:00 PM',
        );
    });

    it('speaks a relative moment in the active language', () => {
        document.documentElement.lang = 'en';

        expect(formatRelativeTime('2026-08-25T16:00:00Z', now)).toBe(
            'yesterday',
        );
    });
});
