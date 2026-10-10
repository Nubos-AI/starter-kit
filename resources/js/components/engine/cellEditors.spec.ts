import { describe, expect, it } from 'vitest';
import {
    isComplexField,
    isInlineEditable,
    withCellEditing,
} from '@/components/engine/cellEditors';
import type { FieldDefinition, FieldType } from '@/types/fields';
import { buildColumnDefs } from '@/types/grid';
import type { GridColumn } from '@/types/grid';

function column(key: string, fieldType: FieldType): GridColumn {
    return {
        key,
        label: key,
        field_type: fieldType,
        is_sortable: true,
        is_filterable: true,
        is_default_column: true,
        list_position: 0,
    };
}

function field(key: string, fieldType: FieldType): FieldDefinition {
    return { key, field_type: fieldType, label: key, is_required: false };
}

const complexTypes: FieldType[] = [
    'relation_has_many',
    'relation_many_to_many',
    'file',
    'geo_address',
    'computed',
    'rollup',
];

const simpleTypes: FieldType[] = [
    'text_short',
    'text_long',
    'number',
    'decimal',
    'money',
    'date',
    'datetime',
    'boolean',
    'single_select',
    'email',
    'phone',
    'url',
];

describe('isComplexField (SC-8)', () => {
    it.each(complexTypes)(
        'treats %s as complex → drawer, never inline',
        (type) => {
            expect(isComplexField(type)).toBe(true);
            expect(isInlineEditable(type)).toBe(false);
        },
    );

    it.each(simpleTypes)('treats %s as a non-complex type', (type) => {
        expect(isComplexField(type)).toBe(false);
    });

    it('keeps multi_select out of inline editing without marking it complex', () => {
        expect(isComplexField('multi_select')).toBe(false);
        expect(isInlineEditable('multi_select')).toBe(false);
    });

    it('allows text and select fields to be inline editable', () => {
        expect(isInlineEditable('text_short')).toBe(true);
        expect(isInlineEditable('single_select')).toBe(true);
    });
});

describe('withCellEditing (SC-8)', () => {
    it('marks complex-field columns editable:false so clicks route to the drawer', () => {
        const columns = [column('owner', 'relation_has_many')];
        const defs = withCellEditing(
            buildColumnDefs(columns, [field('owner', 'relation_has_many')]),
            columns,
            [field('owner', 'relation_has_many')],
        );

        expect(defs).toHaveLength(1);
        expect(defs[0].editable).toBe(false);
    });

    it('locks every column when the viewer may not update records', () => {
        const columns = [column('title', 'text_short')];
        const defs = withCellEditing(
            buildColumnDefs(columns, [field('title', 'text_short')]),
            columns,
            [field('title', 'text_short')],
            false,
        );

        expect(defs[0].editable).toBe(false);
    });

    it('marks a text column editable and leaves no select editor on it', () => {
        const columns = [column('title', 'text_short')];
        const defs = withCellEditing(
            buildColumnDefs(columns, [field('title', 'text_short')]),
            columns,
            [field('title', 'text_short')],
        );

        expect(defs[0].editable).toBe(true);
        expect(defs[0].cellEditor).toBeUndefined();
    });

    it('wires the select cell editor for single_select columns', () => {
        const columns = [column('stage', 'single_select')];
        const fields: FieldDefinition[] = [
            {
                key: 'stage',
                field_type: 'single_select',
                label: 'Stage',
                is_required: false,
                config: {
                    options: [
                        { value: 'open', label: 'Offen' },
                        { value: 'won', label: 'Gewonnen' },
                    ],
                },
            },
        ];
        const defs = withCellEditing(
            buildColumnDefs(columns, fields),
            columns,
            fields,
        );

        expect(defs[0].editable).toBe(true);
        expect(defs[0].cellEditor).toBe('agSelectCellEditor');
        expect(defs[0].cellEditorParams?.values).toEqual(['open', 'won']);
    });

    it('uses the large-text editor for text_long columns', () => {
        const columns = [column('notes', 'text_long')];
        const defs = withCellEditing(
            buildColumnDefs(columns, [field('notes', 'text_long')]),
            columns,
            [field('notes', 'text_long')],
        );

        expect(defs[0].editable).toBe(true);
        expect(defs[0].cellEditor).toBe('agLargeTextCellEditor');
    });
});
