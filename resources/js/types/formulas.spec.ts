import { describe, expect, it } from 'vitest';
import { formulaErrorLabels, isFormulaErrorValue } from '@/types/formulas';

describe('isFormulaErrorValue', () => {
    it.each<[string, unknown]>([
        ['a plain string', 'division_by_zero'],
        ['a number', 19],
        ['a boolean', true],
        ['undefined', undefined],
        ['a function', () => 'division_by_zero'],
        ['null, which typeof reports as an object', null],
        ['an empty object', {}],
        ['an object carrying only the code', { code: 'division_by_zero' }],
        ['an object carrying only the field key', { field_key: 'netto' }],
        ['a numeric code', { code: 19, field_key: null }],
        ['a null code', { code: null, field_key: null }],
        ['an unknown code', { code: 'meltdown', field_key: null }],
        ['an inherited toString code', { code: 'toString', field_key: null }],
        [
            'an inherited constructor code',
            { code: 'constructor', field_key: null },
        ],
        ['a numeric field key', { code: 'division_by_zero', field_key: 19 }],
        [
            'an undefined field key',
            { code: 'division_by_zero', field_key: undefined },
        ],
        ['an object as field key', { code: 'division_by_zero', field_key: {} }],
    ])('rejects %s', (_case, value) => {
        expect(isFormulaErrorValue(value)).toBe(false);
    });

    it('accepts every known error code with and without a field key', () => {
        for (const code of Object.keys(formulaErrorLabels)) {
            expect(isFormulaErrorValue({ code, field_key: 'netto' })).toBe(
                true,
            );
            expect(isFormulaErrorValue({ code, field_key: null })).toBe(true);
        }
    });
});
