import type { ValueFormatterParams } from 'ag-grid-community';
import { describe, expect, it } from 'vitest';
import type { FieldDefinition } from '@/types/fields';
import {
    buildColumnDefs,
    businessKeyColumnDef,
    toGridColumns,
} from '@/types/grid';

const fields: FieldDefinition[] = [
    {
        key: 'name',
        field_type: 'text_short',
        label: 'Name',
        is_required: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: true,
        list_position: 1,
    },
    {
        key: 'city',
        field_type: 'text_short',
        label: 'City',
        is_required: false,
        is_sortable: false,
        is_filterable: false,
        is_default_column: false,
        list_position: 2,
    },
];

describe('toGridColumns', () => {
    it('maps field definitions to grid columns including grid flags', () => {
        const columns = toGridColumns(fields);

        expect(columns.map((column) => column.key)).toEqual(['name', 'city']);
        expect(columns[0]).toMatchObject({
            key: 'name',
            label: 'Name',
            is_sortable: true,
            is_default_column: true,
        });
    });
});

describe('buildColumnDefs visibility', () => {
    it('hides every column that is not in the visible set', () => {
        const defs = buildColumnDefs(
            toGridColumns(fields),
            fields,
            new Set(['name']),
        );
        const byId = new Map(defs.map((def) => [def.colId, def]));

        expect(byId.get('name')?.hide).toBe(false);
        expect(byId.get('city')?.hide).toBe(true);
    });

    it('leaves visibility untouched when no visible set is given', () => {
        const defs = buildColumnDefs(toGridColumns(fields), fields);

        expect(defs.every((def) => def.hide === undefined)).toBe(true);
    });
});

describe('buildColumnDefs sizing', () => {
    it('makes every column flex so the grid always fills its width', () => {
        const defs = buildColumnDefs(toGridColumns(fields), fields);

        expect(defs.every((def) => def.flex === 1)).toBe(true);
        expect(defs.every((def) => (def.minWidth ?? 0) > 0)).toBe(true);
    });
});

describe('businessKeyColumnDef', () => {
    it('reads the business key from the record itself', () => {
        const def = businessKeyColumnDef();

        expect(def.colId).toBe('record_number');
        expect(def.field).toBe('recordNumber');
        expect(def.headerName).toBe('Datensatz');
    });

    it('stays pinned, sortable and read-only', () => {
        const def = businessKeyColumnDef();

        expect(def.pinned).toBe('left');
        expect(def.sortable).toBe(true);
        expect(def.editable).toBe(false);
        expect(def.filter).toBe(false);
    });

    it('shows a dash while a record carries no business key', () => {
        const format = businessKeyColumnDef().valueFormatter as (
            params: unknown,
        ) => string;

        expect(format({ value: null })).toBe('—');
        expect(format({ value: 'CO-0000000001' })).toBe('CO-0000000001');
    });
});

describe('buildColumnDefs formula columns', () => {
    it('formats a numeric formula like the record card does', () => {
        const formula: FieldDefinition[] = [
            {
                key: 'value',
                field_type: 'computed',
                label: 'Wert',
                is_required: false,
                config: { result_type: 'number', scale: 2 },
                list_position: 1,
            },
        ];
        const [definition] = buildColumnDefs(toGridColumns(formula), formula);
        const formatter = definition.valueFormatter as (
            params: ValueFormatterParams,
        ) => string;

        expect(
            formatter({
                value: '26638.85',
                data: { data: {} },
            } as ValueFormatterParams),
        ).toBe('26.638,85');
    });
});

describe('buildColumnDefs number columns', () => {
    it('groups an integer in the notation of the language', () => {
        const numbers: FieldDefinition[] = [
            {
                key: 'quantity',
                field_type: 'number',
                label: 'Menge',
                is_required: false,
                list_position: 1,
            },
        ];
        const [definition] = buildColumnDefs(toGridColumns(numbers), numbers);
        const formatter = definition.valueFormatter as (
            params: ValueFormatterParams,
        ) => string;

        expect(
            formatter({
                value: 23123,
                data: { data: {} },
            } as ValueFormatterParams),
        ).toBe('23.123');
    });
});
