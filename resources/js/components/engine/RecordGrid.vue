<script setup lang="ts">
import { ListTree } from '@lucide/vue';
import type {
    CellClickedEvent,
    ColDef,
    ColumnState,
    GetRowIdParams,
    GridApi,
    GridReadyEvent,
    ICellRendererParams,
} from 'ag-grid-community';
import type { PropType } from 'vue';
import {
    computed,
    defineComponent,
    getCurrentInstance,
    h,
    onMounted,
    ref,
    watch,
} from 'vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import AgingCellRenderer from '@/components/engine/AgingCellRenderer.vue';
import BulkActionBar from '@/components/engine/BulkActionBar.vue';
import BusinessKeyCell from '@/components/engine/BusinessKeyCell.vue';
import {
    isComplexField,
    withCellEditing,
} from '@/components/engine/cellEditors';
import ColumnMenu from '@/components/engine/ColumnMenu.vue';
import ConflictDialog from '@/components/engine/ConflictDialog.vue';
import ExportButton from '@/components/engine/export/ExportButton.vue';
import FormulaBackfillBanner from '@/components/engine/FormulaBackfillBanner.vue';
import FormulaErrorCell from '@/components/engine/FormulaErrorCell.vue';
import { recordActionsColumn } from '@/components/engine/recordActions';
import RecordDeleteDialog from '@/components/engine/records/RecordDeleteDialog.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { useColumnState } from '@/composables/useColumnState';
import { useI18n } from '@/composables/useI18n';
import { useInlineSave } from '@/composables/useInlineSave';
import { usePermissions } from '@/composables/usePermissions';
import { useRecordDatasource } from '@/composables/useRecordDatasource';
import { useRecordDelete } from '@/composables/useRecordDelete';
import { useRecordSelection } from '@/composables/useRecordSelection';
import type { SelectionSnapshot } from '@/composables/useRecordSelection';
import { useUserPreferences } from '@/composables/useUserPreferences';
import { AGING_COLUMN_IDS } from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';
import {
    buildColumnDefs,
    businessKeyColumnDef,
    toGridColumns,
} from '@/types/grid';
import type { GridColumn } from '@/types/grid';
import type { RecordObjectType, RecordPayload } from '@/types/records';
import type { ReportDrillDownParams } from '@/types/reports';

const { t } = useI18n();

const props = defineProps<{
    objectType: RecordObjectType;
    columns: GridColumn[];
    fieldDefinitions: FieldDefinition[];
    segmentId?: string | null;
    search?: string | null;
    drillDown?: ReportDrillDownParams | null;
    columnState?: ColumnState[] | null;
    pageSize?: number;
    hierarchyOrder?: boolean | null;
}>();

const { canForObjectType } = usePermissions();
const preferences = useUserPreferences();

const emit = defineEmits<{
    openRecord: [record: RecordPayload];
    state: [value: 'loading' | 'error' | 'empty' | 'ready'];
}>();

const instance = getCurrentInstance();
const managesState = computed<boolean>(
    () => instance?.vnode.props?.onState != null,
);

const BusinessKeyCellRenderer = defineComponent({
    name: 'BusinessKeyCellRenderer',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<RecordPayload>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () =>
            h(BusinessKeyCell, { record: cellProps.params.data ?? null });
    },
});

const FormulaErrorCellRenderer = defineComponent({
    name: 'FormulaErrorCellRenderer',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<RecordPayload>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => h(FormulaErrorCell, { value: cellProps.params.value });
    },
});

function withFormulaErrorRenderer(
    defs: ColDef[],
    columns: GridColumn[],
): ColDef[] {
    const computedKeys = new Set(
        columns
            .filter((column) => column.field_type === 'computed')
            .map((column) => column.key),
    );

    return defs.map((def) =>
        def.colId !== undefined && computedKeys.has(def.colId)
            ? {
                  ...def,
                  cellDataType: false,
                  cellRenderer: FormulaErrorCellRenderer,
              }
            : def,
    );
}

function withAgingColumns(defs: ColDef[]): ColDef[] {
    return defs.map((def) => {
        if (def.colId === AGING_COLUMN_IDS.age) {
            return {
                ...def,
                field: 'aging.age',
                hide: false,
                editable: false,
                cellDataType: false,
                cellRenderer: AgingCellRenderer,
            };
        }

        if (def.colId === AGING_COLUMN_IDS.stage) {
            return {
                ...def,
                field: 'aging.stage',
                hide: true,
                editable: false,
            };
        }

        return def;
    });
}

const allColumns = toGridColumns(props.fieldDefinitions);
const visibleKeys = new Set(props.columns.map((column) => column.key));
const columnDefs = withAgingColumns(
    withFormulaErrorRenderer(
        withCellEditing(
            buildColumnDefs(allColumns, props.fieldDefinitions, visibleKeys),
            allColumns,
            props.fieldDefinitions,
            canForObjectType(props.objectType.slug, 'update'),
        ),
        allColumns,
    ),
);
const columnsByKey = new Map(allColumns.map((column) => [column.key, column]));
const hierarchyOrder = ref<boolean>(
    props.objectType.hasHierarchy && props.hierarchyOrder !== false,
);
const { datasource, loading, error, hierarchyNotice } = useRecordDatasource(
    props.objectType.slug,
    () => props.segmentId ?? null,
    () => props.drillDown ?? null,
    () => hierarchyOrder.value,
    () => props.search ?? null,
);

const toggleHierarchyOrder = (): void => {
    hierarchyOrder.value = !hierarchyOrder.value;

    preferences.patch({
        objectTypes: {
            [props.objectType.id]: { hierarchyOrder: hierarchyOrder.value },
        },
    });

    clearSelection();
    gridApi?.refreshInfiniteCache();
};
const { onCellValueChanged, conflictOpen, closeConflict, announcement } =
    useInlineSave();
const {
    onGridReady,
    onColumnEvent,
    columnSnapshot,
    setColumnVisible,
    cycleSort,
    loaded,
} = useColumnState({
    initialState: props.columnState ?? null,
    onPersist: (state) => {
        preferences.patch({
            objectTypes: { [props.objectType.id]: { columnState: state } },
        });
    },
});

const rowModelType = 'infinite' as const;
const cacheBlockSize = computed<number>(() => props.pageSize ?? 100);
const maxConcurrentDatasourceRequests = 1;

const isMounted = ref<boolean>(false);

let gridApi: GridApi | null = null;

const SELECTION_COL_ID = '__bulk_select__';

const snapshotProvider = (): SelectionSnapshot => {
    if (gridApi === null) {
        return { filterModel: {}, sortModel: [], search: props.search ?? null };
    }

    const sortModel = gridApi
        .getColumnState()
        .filter((entry) => entry.sort !== null && entry.sort !== undefined)
        .sort(
            (first, second) => (first.sortIndex ?? 0) - (second.sortIndex ?? 0),
        )
        .map((entry) => ({
            colId: entry.colId,
            sort: entry.sort as 'asc' | 'desc',
        }));

    return {
        filterModel: gridApi.getFilterModel() as Record<string, unknown>,
        sortModel,
        search: props.search ?? null,
    };
};

const {
    isSelected,
    toggle: toggleSelection,
    hasSelection: hasBulkSelection,
    summary: bulkSummary,
    buildSelectionPayload,
    selectAllMatching,
    clear: clearSelection,
} = useRecordSelection(snapshotProvider);

const SelectionCellRenderer = defineComponent({
    name: 'BulkSelectionCell',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<RecordPayload>>,
            required: true,
        },
    },
    setup(cellProps) {
        const onToggle = (): void => {
            const id = cellProps.params.data?.id;

            if (id !== undefined && id !== null) {
                toggleSelection(id);
            }
        };

        return () => {
            const id = cellProps.params.data?.id ?? null;

            return h(Checkbox, {
                modelValue: id !== null && isSelected(id),
                'onUpdate:modelValue': onToggle,
                'aria-label': t(
                    'i18n.components.engine.record_grid.select_record',
                ),
            });
        };
    },
});

const SelectionHeader = defineComponent({
    name: 'BulkSelectionHeader',
    setup() {
        const loadedIds = (): string[] => {
            const ids: string[] = [];

            gridApi?.forEachNode((node) => {
                const id = node.data?.id;

                if (id !== undefined && id !== null) {
                    ids.push(id);
                }
            });

            return ids;
        };

        const headerState = (): boolean | 'indeterminate' => {
            const ids = loadedIds();

            if (ids.length === 0) {
                return false;
            }

            const selectedCount = ids.filter((id) => isSelected(id)).length;

            if (selectedCount === 0) {
                return false;
            }

            return selectedCount === ids.length ? true : 'indeterminate';
        };

        const onToggle = (): void => {
            const ids = loadedIds();
            const allSelected =
                ids.length > 0 && ids.every((id) => isSelected(id));

            for (const id of ids) {
                if (allSelected === isSelected(id)) {
                    toggleSelection(id);
                }
            }
        };

        return () =>
            h(Checkbox, {
                modelValue: headerState(),
                'onUpdate:modelValue': onToggle,
                'aria-label': t(
                    'i18n.components.engine.record_grid.select_all_visible_records',
                ),
            });
    },
});

const selectionColumnDef: ColDef<RecordPayload> = {
    colId: SELECTION_COL_ID,
    headerComponent: SelectionHeader,
    cellRenderer: SelectionCellRenderer,
    width: 52,
    minWidth: 52,
    maxWidth: 52,
    pinned: 'left',
    resizable: false,
    sortable: false,
    filter: false,
    editable: false,
    suppressMovable: true,
    type: 'selection',
};

const deletion = useRecordDelete(() => {
    gridApi?.refreshInfiniteCache();
    clearSelection();
});

const deleteDescription = computed<string>(() => {
    const number = deletion.pending.value?.recordNumber ?? null;
    const subject =
        number === null
            ? t('i18n.components.engine.record_grid.the_record')
            : `„${number}“`;

    return t(
        'i18n.components.engine.record_grid.will_be_deleted_and_can_be_restored_from_the',
        { value1: subject },
    );
});

const gridColumnDefs: ColDef[] = [
    selectionColumnDef,
    { ...businessKeyColumnDef(), cellRenderer: BusinessKeyCellRenderer },
    ...columnDefs,
    recordActionsColumn({
        canUpdate: canForObjectType(props.objectType.slug, 'update'),
        canDelete: canForObjectType(props.objectType.slug, 'delete'),
        onDelete: (record) => deletion.request(record),
    }) as ColDef,
];

const onBulkFinished = (): void => {
    gridApi?.refreshInfiniteCache();
    clearSelection();
};

const getRowId = (params: GetRowIdParams<RecordPayload>): string =>
    params.data.id;

const handleGridReady = async (event: GridReadyEvent): Promise<void> => {
    gridApi = event.api;
    await onGridReady(event);
    event.api.setGridOption('datasource', datasource);
};

const onCellClicked = (event: CellClickedEvent<RecordPayload>): void => {
    const column = columnsByKey.get(event.column.getColId());

    if (column === undefined || !isComplexField(column.field_type)) {
        return;
    }

    const record = event.data;

    if (record !== undefined && record !== null) {
        emit('openRecord', record);
    }
};

const reloadAfterConflict = (): void => {
    gridApi?.refreshInfiniteCache();
    closeConflict();
};

const onModelUpdated = (): void => {
    if (loading.value || error.value !== null) {
        return;
    }

    const count = gridApi?.getDisplayedRowCount() ?? 0;

    emit('state', count === 0 ? 'empty' : 'ready');
};

watch(
    [loading, error],
    () => {
        if (error.value !== null) {
            emit('state', 'error');

            return;
        }

        if (loading.value) {
            emit('state', 'loading');
        }
    },
    { immediate: true },
);

const refresh = (): void => {
    gridApi?.refreshInfiniteCache();
};

watch(
    [() => props.segmentId, () => props.drillDown, () => props.search],
    () => {
        clearSelection();
        gridApi?.refreshInfiniteCache();
    },
);

defineExpose({ refresh });

onMounted(() => {
    isMounted.value = true;
});
</script>

<template>
    <div class="relative flex h-full flex-col">
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
        >
            {{ announcement }}
        </div>

        <p
            v-if="error && !managesState"
            role="alert"
            class="shrink-0 px-4 py-2 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <p
            v-if="hierarchyNotice"
            data-hierarchy-notice
            class="shrink-0 px-4 pt-2 text-xs text-muted-foreground"
        >
            {{ hierarchyNotice }}
        </p>

        <div class="flex shrink-0 items-center justify-end gap-2 px-4 py-2">
            <ExportButton
                :object-type="objectType"
                :field-definitions="fieldDefinitions"
                :segment-id="props.segmentId ?? null"
                :snapshot-provider="snapshotProvider"
            />
            <Button
                v-if="objectType.hasHierarchy"
                variant="outline"
                size="sm"
                :aria-pressed="hierarchyOrder"
                data-hierarchy-toggle
                @click="toggleHierarchyOrder"
            >
                <ListTree class="size-4" />
                {{ t('i18n.components.engine.record_grid.hierarchy') }}
            </Button>
            <ColumnMenu
                :columns="allColumns"
                :snapshot="columnSnapshot"
                @toggle-visible="setColumnVisible"
                @cycle-sort="cycleSort"
            />
        </div>

        <FormulaBackfillBanner
            :object-type-slug="objectType.slug"
            @completed="refresh"
        />

        <BulkActionBar
            v-if="hasBulkSelection"
            :object-type="objectType"
            :summary="bulkSummary"
            :field-definitions="fieldDefinitions"
            :build-selection-payload="buildSelectionPayload"
            @select-all-matching="selectAllMatching"
            @clear="clearSelection"
            @finished="onBulkFinished"
        />

        <div
            v-if="isMounted"
            role="region"
            :aria-label="
                t('i18n.components.engine.record_grid.table', {
                    value1: objectType.name,
                })
            "
            class="min-h-0 w-full flex-1 px-4 pb-4"
        >
            <DataGrid
                class="h-full w-full"
                :column-defs="gridColumnDefs"
                :row-model-type="rowModelType"
                :cache-block-size="cacheBlockSize"
                :max-concurrent-datasource-requests="
                    maxConcurrentDatasourceRequests
                "
                :stop-editing-when-cells-lose-focus="true"
                :ensure-dom-order="true"
                :get-row-id="getRowId"
                @grid-ready="handleGridReady"
                @cell-value-changed="onCellValueChanged"
                @cell-clicked="onCellClicked"
                @model-updated="onModelUpdated"
                @column-moved="onColumnEvent"
                @column-resized="onColumnEvent"
                @column-visible="onColumnEvent"
                @sort-changed="onColumnEvent"
            />
        </div>

        <div
            v-if="!managesState && (!isMounted || !loaded || loading)"
            class="absolute inset-0 flex flex-1 flex-col gap-2 bg-background p-4"
        >
            <Skeleton class="h-8 w-full" />
            <Skeleton class="h-6 w-full" />
            <Skeleton class="h-6 w-full" />
            <Skeleton class="h-6 w-3/4" />
        </div>

        <ConflictDialog
            :open="conflictOpen"
            @close="closeConflict"
            @reload="reloadAfterConflict"
        />

        <RecordDeleteDialog
            v-model:reason="deletion.reason.value"
            :open="deletion.isOpen.value"
            :description="deleteDescription"
            :pending="deletion.deleting.value"
            :requires-reason="props.objectType.requiresDeletionReason"
            :error="deletion.reasonError.value"
            @confirm="deletion.confirm"
            @cancel="deletion.cancel"
        />
    </div>
</template>
