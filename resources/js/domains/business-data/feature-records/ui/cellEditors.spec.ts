import { describe, expect, it } from 'vitest';
import { numberInputFormat } from '@/shared/lib/localizedNumber';
import { t } from '@/shared/testing/i18n';
import type { FieldDefinition, FieldType } from '@/shared/types/fields';
import { buildColumnDefs } from '../../data/types/grid';
import type { GridColumn } from '../../data/types/grid';
import { isComplexField, isInlineEditable, isSuppliedField, withCellEditing } from './cellEditors';
import NumberCellEditor from './NumberCellEditor.vue';

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
  it.each(complexTypes)('treats %s as complex → drawer, never inline', (type) => {
    expect(isComplexField(type)).toBe(true);
    expect(isInlineEditable(type)).toBe(false);
  });

  it.each(simpleTypes)('treats %s as a non-complex, inline editable type', (type) => {
    expect(isComplexField(type)).toBe(false);
    expect(isInlineEditable(type)).toBe(true);
  });

  it('keeps multi_select out of inline editing without marking it complex', () => {
    expect(isComplexField('multi_select')).toBe(false);
    expect(isInlineEditable('multi_select')).toBe(false);
  });
});

describe('withCellEditing (SC-8)', () => {
  it('marks complex-field columns editable:false so clicks route to the drawer', () => {
    const columns = [column('owner', 'relation_has_many')];
    const defs = withCellEditing(buildColumnDefs(columns, [field('owner', 'relation_has_many')], t), columns, [
      field('owner', 'relation_has_many'),
    ]);

    expect(defs).toHaveLength(1);
    expect(defs[0].editable).toBe(false);
  });

  it('locks every column when the viewer may not update records', () => {
    const columns = [column('title', 'text_short')];
    const defs = withCellEditing(
      buildColumnDefs(columns, [field('title', 'text_short')], t),
      columns,
      [field('title', 'text_short')],
      false,
    );

    expect(defs[0].editable).toBe(false);
  });

  it('marks a text column editable and leaves no select editor on it', () => {
    const columns = [column('title', 'text_short')];
    const defs = withCellEditing(buildColumnDefs(columns, [field('title', 'text_short')], t), columns, [
      field('title', 'text_short'),
    ]);

    expect(typeof defs[0].editable).toBe('function');
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
    const defs = withCellEditing(buildColumnDefs(columns, fields, t), columns, fields);

    expect(typeof defs[0].editable).toBe('function');
    expect(defs[0].cellEditor).toBe('agSelectCellEditor');
    expect(defs[0].cellEditorParams?.values).toEqual(['open', 'won']);
  });

  it('uses the large-text editor for text_long columns', () => {
    const columns = [column('notes', 'text_long')];
    const defs = withCellEditing(buildColumnDefs(columns, [field('notes', 'text_long')], t), columns, [
      field('notes', 'text_long'),
    ]);

    expect(typeof defs[0].editable).toBe('function');
    expect(defs[0].cellEditor).toBe('agLargeTextCellEditor');
  });
});

describe('withCellEditing — numbers are edited in the local notation', () => {
  it.each(['number', 'decimal', 'money'] as const)('hands a %s column the localised number editor', (type) => {
    const defs = withCellEditing(
      buildColumnDefs([column('amount', type)], [field('amount', type)], t),
      [column('amount', type)],
      [field('amount', type)],
    );

    expect(defs[0].cellEditor).toBe(NumberCellEditor);
    expect(defs[0].cellEditorParams).toEqual({
      format: numberInputFormat(type),
    });
  });
});

describe('withCellEditing — fields another app supplies', () => {
  it('keeps the cell read-only only in the rows whose field is supplied', () => {
    const columns = [column('price', 'money')];
    const defs = withCellEditing(buildColumnDefs(columns, [field('price', 'money')], t), columns, [
      field('price', 'money'),
    ]);
    const editable = defs[0].editable;

    expect(typeof editable).toBe('function');

    if (typeof editable !== 'function') {
      return;
    }

    const isEditableFor = (data: unknown): boolean => editable({ data } as Parameters<typeof editable>[0]);

    expect(isEditableFor({ suppliedKeys: ['price'] })).toBe(false);
    expect(isEditableFor({ suppliedKeys: ['tax_rate'] })).toBe(true);
    expect(isEditableFor({})).toBe(true);
  });

  it('recognises a supplied field only from the row payload', () => {
    expect(isSuppliedField({ suppliedKeys: ['price'] }, 'price')).toBe(true);
    expect(isSuppliedField({ suppliedKeys: ['price'] }, 'cost')).toBe(false);
    expect(isSuppliedField(undefined, 'price')).toBe(false);
  });
});
