import type {
    ColDef,
    SortModelItem,
    ValueFormatterParams,
} from 'ag-grid-community';
import { formatFieldValue } from '@/lib/formatFieldValue';
import type { FieldDefinition, FieldType } from '@/types/fields';
import type { RecordPayload } from '@/types/records';

export interface GridColumn {
    key: string;
    label: string;
    field_type: FieldType;
    is_sortable: boolean;
    is_filterable: boolean;
    is_default_column: boolean;
    list_position: number;
}

export interface GridGetRowsRequest {
    startRow: number;
    endRow: number;
    sortModel: SortModelItem[];
    filterModel: Record<string, unknown>;
    search?: string | null;
    segment?: string | null;
    report?: string | null;
    dashboard?: string | null;
    widget?: string | null;
    group?: string | null;
    series?: string | null;
    hierarchy?: boolean;
}

export interface GridHierarchyState {
    applied: boolean;
    reason: string | null;
}

export interface GridGetRowsResponse {
    rows: RecordPayload[];
    lastRow: number | null;
    hierarchy?: GridHierarchyState;
    searchApplied?: boolean;
}

type ColumnDefFactory = (field: FieldDefinition | undefined) => Partial<ColDef>;

function stringFallback(params: ValueFormatterParams): string {
    return params.value === null || params.value === undefined
        ? ''
        : String(params.value);
}

function fieldValueFormatter(
    field: FieldDefinition | undefined,
): (params: ValueFormatterParams) => string {
    return (params: ValueFormatterParams): string =>
        formatFieldValue(params.value, field);
}

const columnDefRegistry: Partial<Record<FieldType, ColumnDefFactory>> = {
    text_short: () => ({ cellDataType: 'text' }),
    text_long: () => ({ cellDataType: 'text' }),
    email: () => ({ cellDataType: 'text' }),
    phone: () => ({ cellDataType: 'text' }),
    url: () => ({ cellDataType: 'text' }),
    number: () => ({ cellDataType: 'number' }),
    decimal: (field) => ({
        cellDataType: 'number',
        valueFormatter: fieldValueFormatter(field),
    }),
    money: (field) => ({
        cellDataType: 'number',
        valueFormatter: fieldValueFormatter(field),
    }),
    date: () => ({ cellDataType: 'dateString' }),
    datetime: (field) => ({
        cellDataType: 'text',
        valueFormatter: fieldValueFormatter(field),
    }),
    boolean: () => ({ cellDataType: 'boolean' }),
    single_select: (field) => ({
        cellDataType: 'text',
        valueFormatter: fieldValueFormatter(field),
    }),
    multi_select: (field) => ({
        cellDataType: 'text',
        valueFormatter: fieldValueFormatter(field),
    }),
};

const defaultColumnDef: ColumnDefFactory = () => ({
    cellDataType: 'text',
    valueFormatter: stringFallback,
});

export function toGridColumns(
    fieldDefinitions: FieldDefinition[],
): GridColumn[] {
    return fieldDefinitions.map((field) => ({
        key: field.key,
        label: field.label,
        field_type: field.field_type,
        is_sortable: field.is_sortable ?? false,
        is_filterable: field.is_filterable ?? false,
        is_default_column: field.is_default_column ?? false,
        list_position: field.list_position ?? 0,
    }));
}

export const BUSINESS_KEY_COL_ID = 'record_number';

export function businessKeyColumnDef(): ColDef {
    return {
        colId: BUSINESS_KEY_COL_ID,
        field: 'recordNumber',
        headerName: 'Datensatznummer',
        width: 170,
        minWidth: 130,
        pinned: 'left',
        editable: false,
        filter: false,
        sortable: true,
        valueFormatter: (params) => (params.value as string | null) ?? '—',
    };
}

export function buildColumnDefs(
    columns: GridColumn[],
    fieldDefinitions: FieldDefinition[],
    visibleKeys?: Set<string>,
): ColDef[] {
    const definitionsByKey = new Map(
        fieldDefinitions.map((definition) => [definition.key, definition]),
    );

    return [...columns]
        .sort((first, second) => first.list_position - second.list_position)
        .map((column) => {
            const factory =
                columnDefRegistry[column.field_type] ?? defaultColumnDef;

            const def: ColDef = {
                colId: column.key,
                field: `data.${column.key}`,
                headerName: column.label,
                sortable: column.is_sortable,
                filter: column.is_filterable,
                flex: 1,
                minWidth: 130,
                ...factory(definitionsByKey.get(column.key)),
            };

            if (visibleKeys !== undefined) {
                def.hide = !visibleKeys.has(column.key);
            }

            return def;
        });
}
