<script setup lang="ts" generic="TRow">
import type {
    CellClickedEvent,
    ColDef,
    GetRowIdParams,
    GridReadyEvent,
    IsFullWidthRowParams,
    RowClassParams,
    RowClassRules,
} from 'ag-grid-community';
import { AgGridVue } from 'ag-grid-vue3';
import type { Component } from 'vue';
import { computed, useAttrs } from 'vue';
import { ACTIONS_COL_ID } from '@/components/data-grid/actions';
import { agGridLocaleDe } from '@/components/data-grid/localeText';
import { SELECTION_COL_ID } from '@/components/data-grid/selectionColumn';
import { useColumnState } from '@/composables/useColumnState';
import type { ColumnGridEvent } from '@/composables/useColumnState';
import { useUserPreferences } from '@/composables/useUserPreferences';
import { registerAgGridModules } from '@/lib/agGrid';
import { buildAgGridTheme } from '@/lib/agGridTheme';
import { defaultGridColDef, resolveRowId } from '@/lib/dataGridDefaults';
import { tableDensityParams } from '@/lib/tableAppearance';
import { isTableOwnedOption, tableColumnTypes } from '@/lib/tableColumns';
import type { ConfigurationGrid } from '@/types/preferences';

defineOptions({
    inheritAttrs: false,
});

registerAgGridModules();

const props = withDefaults(
    defineProps<{
        columnDefs: ColDef<TRow>[];
        rowData?: TRow[];
        rowModelType?: 'clientSide' | 'infinite';
        getRowId?: (params: GetRowIdParams<TRow>) => string;
        defaultColDef?: ColDef<TRow>;
        domLayout?: 'normal' | 'autoHeight';
        suppressNoRowsOverlay?: boolean;
        ariaLabel?: string;
        rowClassRules?: RowClassRules<TRow>;
        isRowActivatable?: (row: TRow) => boolean;
        isFullWidthRow?: (params: IsFullWidthRowParams<TRow>) => boolean;
        fullWidthCellRenderer?: Component;
        preferenceKey?: ConfigurationGrid;
    }>(),
    {
        rowModelType: 'clientSide',
        domLayout: 'normal',
        suppressNoRowsOverlay: true,
    },
);

const emit = defineEmits<{
    'row-activate': [row: TRow];
}>();

const { settings, grid, patch } = useUserPreferences();

const gridTheme = computed(() => buildAgGridTheme(settings.value.density));
const density = computed(() => tableDensityParams[settings.value.density]);

const { onGridReady: restoreColumns, onColumnEvent: rememberColumns } =
    useColumnState({
        initialState:
            props.preferenceKey === undefined
                ? null
                : grid(props.preferenceKey).columnState,
        onPersist: (state) => {
            if (props.preferenceKey === undefined) {
                return;
            }

            patch({ grids: { [props.preferenceKey]: { columnState: state } } });
        },
    });

const COLUMN_EVENTS = [
    'onColumnMoved',
    'onColumnResized',
    'onColumnVisible',
    'onColumnPinned',
    'onSortChanged',
] as const;

type ColumnEventName = (typeof COLUMN_EVENTS)[number];

const attrs = useAttrs();

const forwardedAttrs = computed<Record<string, unknown>>(() =>
    Object.fromEntries(
        Object.entries(attrs).filter(
            ([key]) =>
                key !== 'onGridReady' &&
                !isTableOwnedOption(key) &&
                !COLUMN_EVENTS.includes(key as ColumnEventName),
        ),
    ),
);

function callParent(name: string, event: unknown): void {
    const handler = attrs[name];

    if (typeof handler === 'function') {
        handler(event);
    }
}

async function onGridReady(event: GridReadyEvent): Promise<void> {
    if (props.preferenceKey !== undefined) {
        await restoreColumns(event);
    }

    callParent('onGridReady', event);
}

function columnEventHandler(name: ColumnEventName) {
    return (event: ColumnGridEvent): void => {
        if (props.preferenceKey !== undefined) {
            rememberColumns(event);
        }

        callParent(name, event);
    };
}

const onColumnMoved = columnEventHandler('onColumnMoved');
const onColumnResized = columnEventHandler('onColumnResized');
const onColumnVisible = columnEventHandler('onColumnVisible');
const onColumnPinned = columnEventHandler('onColumnPinned');
const onSortChanged = columnEventHandler('onSortChanged');

const mergedDefaultColDef = computed<ColDef<TRow>>(() => ({
    ...defaultGridColDef,
    ...props.defaultColDef,
}));

const resolvedGetRowId = computed(() => props.getRowId ?? resolveRowId);

const mergedRowClassRules = computed<RowClassRules<TRow>>(() => ({
    ...props.rowClassRules,
    'cursor-pointer': (params: RowClassParams<TRow>) =>
        isActivatable(params.data),
}));

function isActivatable(row: TRow | undefined): boolean {
    return (
        props.isRowActivatable !== undefined &&
        row !== undefined &&
        row !== null &&
        props.isRowActivatable(row)
    );
}

function handlesItsOwnClick(target: unknown): boolean {
    return (
        target instanceof HTMLElement &&
        target.closest(
            'a, button, input, textarea, select, [role="checkbox"]',
        ) !== null
    );
}

function onCellClicked(event: CellClickedEvent<TRow>): void {
    const colId = event.column.getColId();

    if (colId === SELECTION_COL_ID || colId === ACTIONS_COL_ID) {
        return;
    }

    if (handlesItsOwnClick(event.event?.target) || !isActivatable(event.data)) {
        return;
    }

    emit('row-activate', event.data as TRow);
}
</script>

<template>
    <AgGridVue
        data-slot="data-grid"
        :theme="gridTheme"
        :locale-text="agGridLocaleDe"
        :column-defs="columnDefs"
        :row-data="rowData"
        :row-model-type="rowModelType"
        :default-col-def="mergedDefaultColDef"
        :dom-layout="domLayout"
        :get-row-id="resolvedGetRowId"
        :row-class-rules="mergedRowClassRules"
        :is-full-width-row="isFullWidthRow"
        :full-width-cell-renderer="fullWidthCellRenderer"
        :suppress-no-rows-overlay="suppressNoRowsOverlay"
        :aria-label="ariaLabel"
        v-bind="forwardedAttrs"
        :column-types="tableColumnTypes"
        :row-height="density.rowHeight"
        :header-height="density.headerHeight"
        @cell-clicked="onCellClicked"
        @grid-ready="onGridReady"
        @column-moved="onColumnMoved"
        @column-resized="onColumnResized"
        @column-visible="onColumnVisible"
        @column-pinned="onColumnPinned"
        @sort-changed="onSortChanged"
    />
</template>
