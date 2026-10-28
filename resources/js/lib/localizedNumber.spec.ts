import { afterEach, describe, expect, it } from 'vitest';
import {
    formatLocalizedNumber,
    numberInputFormat,
    parseLocalizedNumber,
} from '@/lib/localizedNumber';

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
        expect(
            formatLocalizedNumber('23123.45', numberInputFormat('money')),
        ).toBe('23.123,45');

        document.documentElement.lang = 'en';

        expect(
            formatLocalizedNumber(23123.45, numberInputFormat('money')),
        ).toBe('23,123.45');
    });

    it('leaves a blank or unreadable value as it is', () => {
        expect(formatLocalizedNumber(null)).toBe('');
        expect(formatLocalizedNumber('abc')).toBe('abc');
    });
});

describe('numberInputFormat', () => {
    it('keeps whole numbers whole, amounts at two digits and decimals as entered', () => {
        expect(formatLocalizedNumber(1234.6, numberInputFormat('number'))).toBe(
            '1.235',
        );
        expect(formatLocalizedNumber(12, numberInputFormat('money'))).toBe(
            '12,00',
        );
        expect(
            formatLocalizedNumber('0.123456', numberInputFormat('decimal')),
        ).toBe('0,123456');
    });
});
