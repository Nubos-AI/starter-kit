import { describe, expect, it } from 'vitest';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { isRollupScope, toRollupFieldConfig } from '@/types/rollups';

describe('isRollupScope', () => {
    it.each<[unknown]>([['direct_children'], ['subtree']])(
        'accepts the known scope %s',
        (value) => {
            expect(isRollupScope(value)).toBe(true);
        },
    );

    it.each<[string, unknown]>([
        ['null', null],
        ['undefined', undefined],
        ['an empty string', ''],
        ['a number', 19],
        ['an object', { scope: 'subtree' }],
        ['an array', ['subtree']],
        ['an inherited toString key', 'toString'],
        ['an inherited constructor key', 'constructor'],
    ])('rejects %s as a scope', (_case, value) => {
        expect(isRollupScope(value)).toBe(false);
    });
});

describe('toRollupFieldConfig', () => {
    const storedFilter: FilterGroupNode = {
        combinator: 'and',
        conditions: [
            { field: 'amount', operator: 'greaterThan', value: 5 },
            {
                combinator: 'or',
                conditions: [{ field: 'name', operator: 'equals', value: 'x' }],
            },
        ],
    };

    it('maps a missing configuration to null', () => {
        expect(toRollupFieldConfig(null)).toBeNull();
        expect(toRollupFieldConfig(undefined)).toBeNull();
    });

    it('maps an empty configuration to an empty roll-up configuration', () => {
        expect(toRollupFieldConfig({})).toEqual({});
    });

    it('takes over every key of a stored configuration unchanged', () => {
        expect(
            toRollupFieldConfig({
                aggregate: 'avg',
                relationship_type_id: 'rel-hierarchy',
                source_field_key: 'amount',
                scope: 'subtree',
                filter: storedFilter,
            }),
        ).toEqual({
            aggregate: 'avg',
            relationship_type_id: 'rel-hierarchy',
            source_field_key: 'amount',
            scope: 'subtree',
            filter: storedFilter,
        });
    });

    it('keeps the stored filter tree identical instead of rebuilding it', () => {
        expect(toRollupFieldConfig({ filter: storedFilter })?.filter).toBe(
            storedFilter,
        );
    });

    it.each<[string, Record<string, unknown>]>([
        ['an unknown scope', { scope: 'entire_universe' }],
        ['a null scope', { scope: null }],
        ['a numeric aggregate', { aggregate: 19 }],
        ['a numeric relationship type', { relationship_type_id: 19 }],
        ['a null source field key', { source_field_key: null }],
        [
            'a filter with an unknown combinator',
            { filter: { combinator: 'xor', conditions: [] } },
        ],
        ['a filter without a combinator', { filter: { conditions: [] } }],
        ['a filter without conditions', { filter: { combinator: 'and' } }],
        [
            'a filter whose conditions are no list',
            { filter: { combinator: 'and', conditions: 'all' } },
        ],
        ['a null filter', { filter: null }],
        ['a filter that is a plain string', { filter: 'and' }],
    ])('drops %s', (_case, config) => {
        expect(toRollupFieldConfig(config)).toEqual({});
    });

    it('keeps the valid keys while dropping the invalid ones', () => {
        expect(
            toRollupFieldConfig({
                aggregate: 'sum',
                scope: 'entire_universe',
                filter: { combinator: 'xor', conditions: [] },
            }),
        ).toEqual({ aggregate: 'sum' });
    });
});
