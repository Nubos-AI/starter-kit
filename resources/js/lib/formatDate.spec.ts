import { describe, expect, it } from 'vitest';
import { formatIsoDate, formatTimelineMoment } from '@/lib/formatDate';

const now = new Date('2026-08-26T16:00:00Z').getTime();

describe('formatTimelineMoment', () => {
    it('says today for a moment from the same day', () => {
        expect(formatTimelineMoment('2026-08-26T12:41:00Z', now)).toBe(
            'heute um 12:41 Uhr',
        );
    });

    it('says yesterday for the day before', () => {
        expect(formatTimelineMoment('2026-08-25T07:05:00Z', now)).toBe(
            'gestern um 07:05 Uhr',
        );
    });

    it('names the weekday inside the last week', () => {
        expect(formatTimelineMoment('2026-08-24T12:41:00Z', now)).toBe(
            'letzten Montag um 12:41 Uhr',
        );
    });

    it('falls back to the day and month once the week is over', () => {
        expect(formatTimelineMoment('2026-08-17T11:37:00Z', now)).toBe(
            '17. August 11:37',
        );
    });

    it('adds the year for a moment from another year', () => {
        expect(formatTimelineMoment('2025-12-11T07:15:00Z', now)).toBe(
            '11. Dezember 2025 07:15',
        );
    });

    it('stays silent about an unreadable moment', () => {
        expect(formatTimelineMoment('not-a-date', now)).toBe('—');
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
