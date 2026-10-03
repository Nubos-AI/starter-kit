import type { ColDef } from 'ag-grid-community';
import { normalizeFieldOptions } from '@/composables/useFieldTypeRegistry';
import type { FieldDefinition, FieldType } from '@/types/fields';
import type { GridColumn } from '@/types/grid';

const complexFieldTypes: ReadonlySet<FieldType> = new Set<FieldType>([
    'relation_has_many',
    'relation_many_to_many',
    'file',
    'geo_address',
    'computed',
    'rollup',
]);

export function isComplexField(fieldType: FieldType): boolean {
    return complexFieldTypes.has(fieldType);
}

export function isInlineEditable(fieldType: FieldType): boolean {
    return !isComplexField(fieldType) && fieldType !== 'multi_select';
}

function editorParams(
    fieldType: FieldType,
    field: FieldDefinition | undefined,
): Partial<ColDef> {
    if (fieldType === 'text_long') {
        return { cellEditor: 'agLargeTextCellEditor' };
    }

    if (fieldType === 'single_select') {
        const options = normalizeFieldOptions(field?.config?.options);
        const labels = new Map(
            options.map((option) => [option.value, option.label]),
        );

        return {
            cellEditor: 'agSelectCellEditor',
            cellEditorParams: {
                values: options.map((option) => option.value),
                formatValue: (value: unknown): string => {
                    const key = String(value);

                    return labels.get(key) ?? key;
                },
            },
        };
    }

    return {};
}

export function withCellEditing(
    defs: ColDef[],
    columns: GridColumn[],
    fields: FieldDefinition[],
    canEdit = true,
): ColDef[] {
    const columnsByKey = new Map(columns.map((column) => [column.key, column]));
    const fieldsByKey = new Map(fields.map((field) => [field.key, field]));

    if (!canEdit) {
        return defs.map((def) => ({ ...def, editable: false }));
    }

    return defs.map((def) => {
        const colId = def.colId;

        if (colId === undefined) {
            return def;
        }

        const column = columnsByKey.get(colId);

        if (column === undefined) {
            return def;
        }

        if (!isInlineEditable(column.field_type)) {
            return { ...def, editable: false };
        }

        return {
            ...def,
            editable: true,
            ...editorParams(column.field_type, fieldsByKey.get(colId)),
        };
    });
}
