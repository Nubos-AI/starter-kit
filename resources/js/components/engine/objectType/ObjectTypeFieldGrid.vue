<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, Pencil, Trash2 } from '@lucide/vue';
import type {
    CellValueChangedEvent,
    ColDef,
    GetRowIdParams,
    ICellRendererParams,
    IsFullWidthRowParams,
    ValueFormatterParams,
} from 'ag-grid-community';
import type { PropType } from 'vue';
import { computed, defineComponent, h, ref } from 'vue';
import FieldDefinitionsController from '@/actions/App/Http/Controllers/Engine/FieldDefinitionsController';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { useI18n } from '@/composables/useI18n';
import { buildFieldGridRows } from '@/lib/fieldGrouping';
import type { FieldGridRow, FieldGroupRow } from '@/types/fieldGroups';
import { isFieldGroupHeaderRow } from '@/types/fieldGroups';
import type { ObjectTypeFieldRow } from '@/types/formulas';

const { t } = useI18n();

const props = defineProps<{
    objectTypeSlug: string;
    fields: ObjectTypeFieldRow[];
    fieldTypes: Array<{ value: string; label: string }>;
    groups: FieldGroupRow[];
    editable: boolean;
}>();

const collapsed = ref<Set<string>>(new Set());

const rows = computed<FieldGridRow[]>(() =>
    buildFieldGridRows(props.fields, props.groups, collapsed.value),
);

function toggleGroup(headerId: string): void {
    const next = new Set(collapsed.value);

    if (!next.delete(headerId)) {
        next.add(headerId);
    }

    collapsed.value = next;
}

function fieldOf(row: FieldGridRow | undefined): ObjectTypeFieldRow | null {
    return row === undefined || isFieldGroupHeaderRow(row) ? null : row;
}

const emit = defineEmits<{
    'edit-field': [field: ObjectTypeFieldRow];
}>();

const typeLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.fieldTypes.map((type) => [type.value, type.label]),
    ),
);

function saveField(
    id: string,
    payload: Record<string, string | boolean>,
): void {
    router.put(
        FieldDefinitionsController.update.url({
            objectType: props.objectTypeSlug,
            field: id,
        }),
        payload,
        { preserveScroll: true, preserveState: true },
    );
}

function removeField(id: string): void {
    router.delete(
        FieldDefinitionsController.destroy.url({
            objectType: props.objectTypeSlug,
            field: id,
        }),
        { preserveScroll: true, preserveState: true },
    );
}

type BooleanFieldKey = 'is_required' | 'is_unique' | 'is_default_column';

function flagCell(
    name: string,
    fieldKey: BooleanFieldKey,
    ariaLabel: (key: string) => string,
) {
    return defineComponent({
        name,
        props: {
            params: {
                type: Object as PropType<ICellRendererParams<FieldGridRow>>,
                required: true,
            },
        },
        setup(cellProps) {
            return () => {
                const row = fieldOf(cellProps.params.data);

                if (!row) {
                    return null;
                }

                return h(Checkbox, {
                    modelValue: row[fieldKey],
                    disabled: !props.editable,
                    'aria-label': ariaLabel(row.key),
                    'onUpdate:modelValue': (
                        value: boolean | 'indeterminate',
                    ) => {
                        saveField(row.id, { [fieldKey]: value === true });
                    },
                });
            };
        },
    });
}

const RequiredCell = flagCell('FieldRequiredCell', 'is_required', (key) =>
    t(
        'i18n.components.engine.object_type.object_type_field_grid.mark_as_required',
        { value1: key },
    ),
);

const UniqueCell = flagCell('FieldUniqueCell', 'is_unique', (key) =>
    t(
        'i18n.components.engine.object_type.object_type_field_grid.mark_as_unique',
        { value1: key },
    ),
);

const DefaultColumnCell = flagCell(
    'FieldDefaultColumnCell',
    'is_default_column',
    (key) =>
        t(
            'i18n.components.engine.object_type.object_type_field_grid.mark_as_a_default_column',
            { value1: key },
        ),
);

const columnDefs = computed<ColDef<FieldGridRow>[]>(() => [
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.key',
        ),
        field: 'key',
        editable: false,
        flex: 1,
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.type',
        ),
        field: 'field_type',
        editable: false,
        flex: 1,
        valueFormatter: (params: ValueFormatterParams<FieldGridRow>): string =>
            typeLabels.value[params.value] ?? params.value,
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.title',
        ),
        field: 'label',
        editable: props.editable,
        flex: 2,
        valueFormatter: (params: ValueFormatterParams<FieldGridRow>): string =>
            params.value || fieldOf(params.data)?.key || '',
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.description',
        ),
        field: 'description',
        editable: props.editable,
        flex: 2,
        valueFormatter: (params: ValueFormatterParams<FieldGridRow>): string =>
            params.value ?? '',
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.required',
        ),
        colId: 'required',
        width: 110,
        editable: false,
        sortable: false,
        cellRenderer: RequiredCell,
        type: 'control',
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.unique',
        ),
        colId: 'unique',
        width: 120,
        editable: false,
        sortable: false,
        cellRenderer: UniqueCell,
        type: 'control',
    },
    {
        headerName: t(
            'i18n.components.engine.object_type.object_type_field_grid.list_column',
        ),
        colId: 'default_column',
        width: 140,
        editable: false,
        sortable: false,
        cellRenderer: DefaultColumnCell,
        type: 'control',
    },
    {
        ...actionsColumn<FieldGridRow>([
            {
                icon: Pencil,
                label: t(
                    'i18n.components.engine.object_type.object_type_field_grid.edit_field',
                ),
                variant: 'edit',
                testId: 'field-edit',
                onClick: (row) => {
                    const field = fieldOf(row);

                    if (field !== null) {
                        emit('edit-field', field);
                    }
                },
            },
            {
                icon: Trash2,
                label: t(
                    'i18n.components.engine.object_type.object_type_field_grid.remove_field',
                ),
                variant: 'destructive',
                testId: 'field-remove',
                onClick: (row) => {
                    const field = fieldOf(row);

                    if (field !== null) {
                        removeField(field.id);
                    }
                },
            },
        ]),
        hide: !props.editable,
    },
]);

const getRowId = (params: GetRowIdParams<FieldGridRow>): string =>
    params.data.id;

const isFullWidthRow = (params: IsFullWidthRowParams<FieldGridRow>): boolean =>
    params.rowNode.data !== undefined &&
    isFieldGroupHeaderRow(params.rowNode.data);

const GroupHeaderCell = defineComponent({
    name: 'FieldGroupHeaderCell',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<FieldGridRow>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data;

            if (row === undefined || !isFieldGroupHeaderRow(row)) {
                return null;
            }

            return h(
                'button',
                {
                    type: 'button',
                    class: 'flex h-full w-full items-center gap-2 bg-muted/60 px-3 text-left text-sm font-medium',
                    'data-field-group-header': row.id,
                    'aria-expanded': !row.collapsed,
                    onClick: () => toggleGroup(row.id),
                },
                [
                    h(row.collapsed ? ChevronRight : ChevronDown, {
                        class: 'size-4 text-muted-foreground',
                    }),
                    h('span', row.label),
                    h(
                        'span',
                        { class: 'text-xs font-normal text-muted-foreground' },
                        String(row.count),
                    ),
                ],
            );
        };
    },
});

const EDITABLE_TEXT_COLUMNS = ['label', 'description'];

function onCellValueChanged(event: CellValueChangedEvent<FieldGridRow>): void {
    const column = event.colDef.field;
    const field = fieldOf(event.data);

    if (column === undefined || field === null) {
        return;
    }

    if (!EDITABLE_TEXT_COLUMNS.includes(column)) {
        return;
    }

    saveField(field.id, { [column]: (event.newValue ?? '') as string });
}
</script>

<template>
    <DataGrid
        class="w-full"
        dom-layout="autoHeight"
        :row-data="rows"
        :column-defs="columnDefs"
        :get-row-id="getRowId"
        :is-full-width-row="isFullWidthRow"
        :full-width-cell-renderer="GroupHeaderCell"
        :stop-editing-when-cells-lose-focus="true"
        @cell-value-changed="onCellValueChanged"
    />
</template>
