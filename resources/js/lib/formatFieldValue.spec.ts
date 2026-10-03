import { describe, expect, it } from 'vitest';
import { formatFieldValue } from '@/lib/formatFieldValue';
import type { FieldDefinition } from '@/types/fields';

function field(overrides: Partial<FieldDefinition> = {}): FieldDefinition {
    return {
        key: 'wert',
        field_type: 'text_short',
        label: 'Wert',
        is_required: false,
        ...overrides,
    };
}

describe('formatFieldValue — the same reading everywhere', () => {
    it('reads a decimal in German with two fraction digits', () => {
        expect(
            formatFieldValue('12.75', field({ field_type: 'decimal' })),
        ).toBe('12,75');
    });

    it('reads an amount as German currency', () => {
        expect(formatFieldValue(1999.99, field({ field_type: 'money' }))).toBe(
            '1.999,99\u00a0€',
        );
    });

    it('reads a whole number with German thousand separators', () => {
        expect(formatFieldValue(12000, field({ field_type: 'number' }))).toBe(
            '12.000',
        );
    });

    it('reads a date as a German day', () => {
        expect(
            formatFieldValue('2026-08-28', field({ field_type: 'date' })),
        ).toBe('28.08.2026');
    });

    it('reads a timestamp as a German day and time', () => {
        expect(
            formatFieldValue(
                '2026-08-28T09:05:00+00:00',
                field({ field_type: 'datetime' }),
            ),
        ).toContain('28.08.2026');
    });

    it('reads a boolean as Ja or Nein', () => {
        expect(formatFieldValue(true, field({ field_type: 'boolean' }))).toBe(
            'Ja',
        );
        expect(formatFieldValue(false, field({ field_type: 'boolean' }))).toBe(
            'Nein',
        );
    });

    it('reads a single select through its option label', () => {
        const definition = field({
            field_type: 'single_select',
            config: { options: [{ value: 'open', label: 'Offen' }] },
        });

        expect(formatFieldValue('open', definition)).toBe('Offen');
    });

    it('reads a multi select as a joined list of labels', () => {
        const definition = field({
            field_type: 'multi_select',
            config: {
                options: [
                    { value: 'a', label: 'Alpha' },
                    { value: 'b', label: 'Beta' },
                ],
            },
        });

        expect(formatFieldValue(['a', 'b'], definition)).toBe('Alpha, Beta');
    });

    it('falls back to the raw key when the option is gone', () => {
        const definition = field({
            field_type: 'single_select',
            config: { options: [] },
        });

        expect(formatFieldValue('open', definition)).toBe('open');
    });

    it('yields an empty string for an absent value', () => {
        expect(formatFieldValue(null, field({ field_type: 'money' }))).toBe('');
        expect(formatFieldValue(undefined, field())).toBe('');
        expect(formatFieldValue('', field())).toBe('');
    });

    it('leaves a value alone when the field is unknown', () => {
        expect(formatFieldValue('roh', undefined)).toBe('roh');
    });

    it('keeps an unparsable number readable instead of swallowing it', () => {
        expect(formatFieldValue('n/a', field({ field_type: 'decimal' }))).toBe(
            'n/a',
        );
    });
});

describe('formatFieldValue — an unknown field still reads as German', () => {
    it('reads a raw ISO timestamp as a German day and time', () => {
        expect(formatFieldValue('2026-09-01T15:53:54+00:00', undefined)).toBe(
            formatFieldValue(
                '2026-09-01T15:53:54+00:00',
                field({ field_type: 'datetime' }),
            ),
        );
        expect(
            formatFieldValue('2026-09-01T15:53:54+00:00', undefined),
        ).not.toBe('2026-09-01T15:53:54+00:00');
    });

    it('reads a raw ISO day as a German day', () => {
        expect(formatFieldValue('2026-09-01', undefined)).toBe('01.09.2026');
    });

    it('leaves plain text alone', () => {
        expect(formatFieldValue('Angebot A', undefined)).toBe('Angebot A');
        expect(formatFieldValue('01m1c498qzsh4e8z036sy3jy9b', undefined)).toBe(
            '01m1c498qzsh4e8z036sy3jy9b',
        );
    });
});
