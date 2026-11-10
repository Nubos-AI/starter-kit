import type { ValueFormatterParams } from 'ag-grid-community';
import { afterEach, describe, expect, it } from 'vitest';
import { tableColumnTypes } from '@/lib/tableColumns';

function formatNumberCell(value: unknown): string {
    const formatter = tableColumnTypes.number.valueFormatter as (
        params: ValueFormatterParams,
    ) => string;

    return formatter({ value } as ValueFormatterParams);
}

afterEach(() => {
    document.documentElement.lang = '';
});

describe('tableColumnTypes — the number column', () => {
    it('groups a number in German notation', () => {
        expect(formatNumberCell(23123.45)).toBe('23.123,45');
    });

    it('groups a number in the notation of the active language', () => {
        document.documentElement.lang = 'en';

        expect(formatNumberCell(23123.45)).toBe('23,123.45');
    });

    it('leaves a value that is no number as it is', () => {
        expect(formatNumberCell('—')).toBe('—');
        expect(formatNumberCell(null)).toBe('');
    });
});
