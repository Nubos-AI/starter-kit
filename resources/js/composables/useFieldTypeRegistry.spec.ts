import { describe, expect, it } from 'vitest';
import {
    deriveValidationHints,
    fieldInputType,
    filterOperatorsFor,
    numericInputStep,
    useFieldTypeRegistry,
} from '@/composables/useFieldTypeRegistry';
import type { FieldValidationRules } from '@/types/fieldEditor';
import type { FieldDefinition, FieldType } from '@/types/fields';

type OperatorArity = 'none' | 'single' | 'range';

interface ExpectedOperator {
    value: string;
    arity: OperatorArity;
}

const BACKEND_TAXONOMY: readonly string[] = [
    'equals',
    'notEqual',
    'contains',
    'notContains',
    'startsWith',
    'endsWith',
    'greaterThan',
    'greaterThanOrEqual',
    'lessThan',
    'lessThanOrEqual',
    'inRange',
    'blank',
    'notBlank',
    'in',
    'notIn',
    'has',
    'hasNot',
];

const ALL_FIELD_TYPES: readonly FieldType[] = [
    'text_short',
    'text_long',
    'number',
    'decimal',
    'money',
    'date',
    'datetime',
    'boolean',
    'single_select',
    'multi_select',
    'relation_has_many',
    'relation_many_to_many',
    'email',
    'phone',
    'url',
    'file',
    'geo_address',
    'computed',
    'rollup',
];

const TEXT_OPERATORS: ExpectedOperator[] = [
    { value: 'equals', arity: 'single' },
    { value: 'notEqual', arity: 'single' },
    { value: 'contains', arity: 'single' },
    { value: 'notContains', arity: 'single' },
    { value: 'startsWith', arity: 'single' },
    { value: 'endsWith', arity: 'single' },
    { value: 'in', arity: 'single' },
    { value: 'notIn', arity: 'single' },
    { value: 'blank', arity: 'none' },
    { value: 'notBlank', arity: 'none' },
];

const NUMERIC_OPERATORS: ExpectedOperator[] = [
    { value: 'equals', arity: 'single' },
    { value: 'notEqual', arity: 'single' },
    { value: 'greaterThan', arity: 'single' },
    { value: 'greaterThanOrEqual', arity: 'single' },
    { value: 'lessThan', arity: 'single' },
    { value: 'lessThanOrEqual', arity: 'single' },
    { value: 'inRange', arity: 'range' },
    { value: 'blank', arity: 'none' },
    { value: 'notBlank', arity: 'none' },
];

const DATE_OPERATORS: ExpectedOperator[] = [
    { value: 'equals', arity: 'single' },
    { value: 'notEqual', arity: 'single' },
    { value: 'greaterThan', arity: 'single' },
    { value: 'lessThan', arity: 'single' },
    { value: 'inRange', arity: 'range' },
    { value: 'blank', arity: 'none' },
    { value: 'notBlank', arity: 'none' },
];

const BOOLEAN_OPERATORS: ExpectedOperator[] = [
    { value: 'equals', arity: 'single' },
    { value: 'blank', arity: 'none' },
];

const SELECT_OPERATORS: ExpectedOperator[] = [
    { value: 'in', arity: 'single' },
    { value: 'notIn', arity: 'single' },
    { value: 'blank', arity: 'none' },
    { value: 'notBlank', arity: 'none' },
];

const RELATION_OPERATORS: ExpectedOperator[] = [
    { value: 'has', arity: 'single' },
    { value: 'hasNot', arity: 'single' },
];

const PRESENCE_OPERATORS: ExpectedOperator[] = [
    { value: 'blank', arity: 'none' },
    { value: 'notBlank', arity: 'none' },
];

function byValue(a: ExpectedOperator, b: ExpectedOperator): number {
    return a.value.localeCompare(b.value);
}

function assertOperators(type: FieldType, expected: ExpectedOperator[]): void {
    const operators = filterOperatorsFor(type);

    for (const operator of operators) {
        expect(typeof operator.label).toBe('string');
        expect(operator.label.length).toBeGreaterThan(0);
    }

    const actual = operators.map((operator) => ({
        value: operator.value,
        arity: operator.arity,
    }));

    expect([...actual].sort(byValue)).toEqual([...expected].sort(byValue));
}

describe('filterOperatorsFor — text field types (SC-1)', () => {
    for (const type of [
        'text_short',
        'text_long',
        'url',
        'email',
        'phone',
    ] as const) {
        it(`mirrors comparison + membership + presence operators for ${type}`, () => {
            assertOperators(type, TEXT_OPERATORS);
        });
    }
});

describe('filterOperatorsFor — numeric field types (SC-1)', () => {
    for (const type of ['number', 'decimal', 'money'] as const) {
        it(`mirrors ordered comparison + inRange + presence operators for ${type}`, () => {
            assertOperators(type, NUMERIC_OPERATORS);
        });
    }
});

describe('filterOperatorsFor — rollup field type (SC-1)', () => {
    it('mirrors the full numeric operator set including inRange (not merely presence)', () => {
        assertOperators('rollup', NUMERIC_OPERATORS);

        const values = filterOperatorsFor('rollup').map(
            (operator) => operator.value,
        );

        expect(values).toContain('inRange');
        expect(values).toContain('greaterThan');
        expect(values.length).toBeGreaterThan(PRESENCE_OPERATORS.length);
    });
});

describe('filterOperatorsFor — date field types (SC-1)', () => {
    for (const type of ['date', 'datetime'] as const) {
        it(`mirrors comparison + inRange + presence operators for ${type}`, () => {
            assertOperators(type, DATE_OPERATORS);
        });
    }
});

describe('filterOperatorsFor — boolean field type (SC-1)', () => {
    it('mirrors equals + blank only', () => {
        assertOperators('boolean', BOOLEAN_OPERATORS);
    });
});

describe('filterOperatorsFor — select field types (SC-1)', () => {
    for (const type of ['single_select', 'multi_select'] as const) {
        it(`mirrors membership + presence operators for ${type}`, () => {
            assertOperators(type, SELECT_OPERATORS);
        });
    }
});

describe('filterOperatorsFor — relation field types (SC-1)', () => {
    for (const type of [
        'relation_has_many',
        'relation_many_to_many',
    ] as const) {
        it(`mirrors has + hasNot operators for ${type}`, () => {
            assertOperators(type, RELATION_OPERATORS);
        });
    }
});

describe('filterOperatorsFor — presence-only field types (SC-1)', () => {
    it('returns exactly [blank, notBlank] with arity none for file', () => {
        assertOperators('file', PRESENCE_OPERATORS);

        expect(
            filterOperatorsFor('file').every(
                (operator) => operator.arity === 'none',
            ),
        ).toBe(true);
    });

    it('returns exactly [blank, notBlank] with arity none for geo_address', () => {
        assertOperators('geo_address', PRESENCE_OPERATORS);

        expect(
            filterOperatorsFor('geo_address').every(
                (operator) => operator.arity === 'none',
            ),
        ).toBe(true);
    });
});

describe('filterOperatorsFor — arity steers the value widget (SC-1)', () => {
    it('maps inRange to range arity (two-value widget)', () => {
        const inRange = filterOperatorsFor('number').find(
            (operator) => operator.value === 'inRange',
        );

        expect(inRange?.arity).toBe('range');
    });

    it('maps blank to none arity (no-value widget)', () => {
        const blank = filterOperatorsFor('number').find(
            (operator) => operator.value === 'blank',
        );

        expect(blank?.arity).toBe('none');
    });

    it('maps notBlank to none arity (no-value widget)', () => {
        const notBlank = filterOperatorsFor('number').find(
            (operator) => operator.value === 'notBlank',
        );

        expect(notBlank?.arity).toBe('none');
    });

    it('maps equals to single arity (one-value widget)', () => {
        const equals = filterOperatorsFor('number').find(
            (operator) => operator.value === 'equals',
        );

        expect(equals?.arity).toBe('single');
    });
});

describe('filterOperatorsFor — multi-value intent uses single arity (SC-1)', () => {
    it('gives in and notIn single arity on select types', () => {
        const operators = filterOperatorsFor('multi_select');
        const membership = operators.filter((operator) =>
            ['in', 'notIn'].includes(operator.value),
        );

        expect(membership).toHaveLength(2);
        expect(
            membership.every((operator) => operator.arity === 'single'),
        ).toBe(true);
    });

    it('gives has and hasNot single arity on relation types', () => {
        const operators = filterOperatorsFor('relation_has_many');

        expect(operators.every((operator) => operator.arity === 'single')).toBe(
            true,
        );
    });
});

describe('filterOperatorsFor — handlerless and unknown types (SC-1)', () => {
    it('returns an empty list for computed', () => {
        expect(filterOperatorsFor('computed')).toEqual([]);
    });

    it('returns an empty list for an unknown field type', () => {
        expect(filterOperatorsFor('not_a_real_type' as FieldType)).toEqual([]);
    });
});

describe('filterOperatorsFor — backend taxonomy parity guard (SC-1, D-3)', () => {
    it('only ever emits operator values that exist in the backend taxonomy', () => {
        for (const type of ALL_FIELD_TYPES) {
            for (const operator of filterOperatorsFor(type)) {
                expect(BACKEND_TAXONOMY).toContain(operator.value);
            }
        }
    });

    it('only ever emits the three defined arities', () => {
        const validArities: OperatorArity[] = ['none', 'single', 'range'];

        for (const type of ALL_FIELD_TYPES) {
            for (const operator of filterOperatorsFor(type)) {
                expect(validArities).toContain(operator.arity);
            }
        }
    });
});

describe('filterOperatorsFor — completeness across every FieldType member (SC-1)', () => {
    it('returns an array for each of the 19 declared field types', () => {
        expect(ALL_FIELD_TYPES).toHaveLength(19);

        for (const type of ALL_FIELD_TYPES) {
            expect(Array.isArray(filterOperatorsFor(type))).toBe(true);
        }
    });
});

function textField(
    rules: FieldValidationRules | null,
    isRequired = false,
): FieldDefinition {
    return {
        key: 'note',
        field_type: 'text_short',
        label: 'Notiz',
        is_required: isRequired,
        validation_rules: rules,
    };
}

describe('deriveValidationHints — the stored rule map', () => {
    it('reports the required flag and nothing else without rules', () => {
        expect(deriveValidationHints(textField(null, true))).toEqual({
            required: true,
        });
    });

    it('reads min and max out of the map the editor writes', () => {
        expect(deriveValidationHints(textField({ min: 2, max: 120 }))).toEqual({
            required: false,
            min: 2,
            max: 120,
            maxLength: 120,
        });
    });

    it('accepts the numeric strings a form submits', () => {
        expect(
            deriveValidationHints(textField({ min: '2', max: '120' })),
        ).toEqual({ required: false, min: 2, max: 120, maxLength: 120 });
    });

    it('survives an all-empty map, which is what an untouched editor stores', () => {
        expect(
            deriveValidationHints(
                textField({ regex: null, min: null, max: null }),
            ),
        ).toEqual({ required: false });
    });

    it('ignores bounds that are no numbers, such as a date bound', () => {
        expect(
            deriveValidationHints(textField({ min: '2026-01-01', max: '' })),
        ).toEqual({ required: false });
    });

    it('leaves the other rule kinds to the server', () => {
        expect(
            deriveValidationHints(
                textField({ regex: '/^A/', in: ['a', 'b'], cross: null }),
            ),
        ).toEqual({ required: false });
    });
});

describe('numericInputStep — a fractional field must not read as invalid', () => {
    it('lets a decimal field carry any number of fraction digits', () => {
        expect(numericInputStep('decimal')).toBe('any');
    });

    it('steps a money field in cents so the arrow keys keep the fraction', () => {
        expect(numericInputStep('money')).toBe('0.01');
    });

    it('leaves a whole number stepping by one', () => {
        expect(numericInputStep('number')).toBe('1');
    });

    it('offers no step for a field that is not numeric', () => {
        expect(numericInputStep('text_short')).toBeUndefined();
        expect(numericInputStep('date')).toBeUndefined();
    });
});

describe('fieldInputType — one place decides the html input type', () => {
    it('maps every numeric field type onto a number input', () => {
        expect(fieldInputType('number')).toBe('number');
        expect(fieldInputType('decimal')).toBe('number');
        expect(fieldInputType('money')).toBe('number');
    });

    it('maps the temporal and contact types onto their native inputs', () => {
        expect(fieldInputType('date')).toBe('date');
        expect(fieldInputType('datetime')).toBe('datetime-local');
        expect(fieldInputType('email')).toBe('email');
        expect(fieldInputType('phone')).toBe('tel');
        expect(fieldInputType('url')).toBe('url');
    });

    it('falls back to text for anything else', () => {
        expect(fieldInputType('text_long')).toBe('text');
        expect(fieldInputType('single_select')).toBe('text');
    });
});

describe('the widget registry carries the step alongside the input type', () => {
    it('hands the decimal widget a step', () => {
        const { resolveWidget } = useFieldTypeRegistry();

        expect(resolveWidget('decimal')?.step).toBe('any');
        expect(resolveWidget('money')?.step).toBe('0.01');
        expect(resolveWidget('text_short')?.step).toBeUndefined();
    });
});
