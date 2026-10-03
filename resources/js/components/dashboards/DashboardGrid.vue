<script setup lang="ts">
import {
    draggable,
    dropTargetForElements,
    monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { LayoutDashboard } from '@lucide/vue';
import {
    computed,
    nextTick,
    onMounted,
    onScopeDispose,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import CreateButton from '@/components/CreateButton.vue';
import DashboardWidget from '@/components/dashboards/DashboardWidget.vue';
import WidgetEditorSheet from '@/components/dashboards/WidgetEditorSheet.vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import { useDashboardLayout } from '@/composables/useDashboardLayout';
import { useI18n } from '@/composables/useI18n';
import { useReportDrillDown } from '@/composables/useReportDrillDown';
import { useWidgetResults } from '@/composables/useWidgetResults';
import type {
    DashboardColumnSpan,
    DashboardWidgetMeta,
} from '@/types/dashboards';
import {
    clampColumnSpan,
    COLUMN_SPAN_CLASS,
    DASHBOARD_ADD_WIDGET_LABEL,
    DASHBOARD_EMPTY_DESCRIPTION,
    DASHBOARD_EMPTY_TITLE,
} from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type { ReportDrillDownSelection } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const DRAG_TYPE = 'dashboard-widget';

const ADD_TILE_HINT = t(
    'i18n.components.dashboards.dashboard_grid.add_another_tile_to_the_dashboard',
);

const GRID_COLUMNS = 3;

const TRAILING_ROW_KEY = 'dashboard-trailing-row';

interface DashboardGridRow {
    key: string;
    widgets: DashboardWidgetMeta[];
    usedColumns: number;
    insertIndex: number;
}

interface DashboardGridSlot extends DashboardGridRow {
    addSlotClass: string | null;
}

const props = defineProps<{
    dashboardId: string;
    widgets: DashboardWidgetMeta[];
    canUpdate: boolean;
    updateReason?: string;
    reportOptions: SelectOption[];
    goalOptions: SelectOption[];
    objectTypeOptions: SelectOption[];
    fieldsByType: Record<string, FieldDefinition[]>;
    linkedFieldsByType: Record<string, FieldDefinition[]>;
    segmentsByType: Record<string, SelectOption[]>;
}>();

const {
    widgets,
    announcement,
    error: layoutError,
    validationErrors,
    moveBefore,
    moveBy,
    setSpan,
    addWidget,
    updateWidget,
    removeWidget,
} = useDashboardLayout(props.dashboardId, () => props.widgets);

const {
    tiles,
    pending,
    isRefreshingAll,
    error: resultsError,
    refreshAll,
    refreshOne,
} = useWidgetResults(props.dashboardId);

const drillDown = useReportDrillDown();

const gridRoot = useTemplateRef<HTMLElement>('gridRoot');
const dropTargetId = ref<string | null>(null);
const editorOpen = ref<boolean>(false);
const editingWidgetId = ref<string | null>(null);
const editorProcessing = ref<boolean>(false);
const insertIndex = ref<number | null>(null);

const rows = computed<DashboardGridSlot[]>(() => {
    const built: DashboardGridRow[] = [];

    widgets.value.forEach((widget, index) => {
        const span = clampColumnSpan(widget.column_span);
        const current = built[built.length - 1];

        if (
            current === undefined ||
            current.usedColumns + span > GRID_COLUMNS
        ) {
            built.push({
                key: widget.id,
                widgets: [],
                usedColumns: 0,
                insertIndex: 0,
            });
        }

        const row = built[built.length - 1];

        row.widgets.push(widget);
        row.usedColumns += span;
        row.insertIndex = index + 1;
    });

    const last = built[built.length - 1];

    if (last === undefined || last.usedColumns === GRID_COLUMNS) {
        built.push({
            key: TRAILING_ROW_KEY,
            widgets: [],
            usedColumns: GRID_COLUMNS - 1,
            insertIndex: widgets.value.length,
        });
    }

    return built.map((row) => {
        const free = GRID_COLUMNS - row.usedColumns;

        return {
            ...row,
            addSlotClass:
                free > 0 ? COLUMN_SPAN_CLASS[clampColumnSpan(free)] : null,
        };
    });
});

const gridError = computed<string | null>(
    () => resultsError.value ?? layoutError.value,
);

const addWidgetProps = computed(() => ({
    label: DASHBOARD_ADD_WIDGET_LABEL,
    disabled: !props.canUpdate,
    title: props.updateReason,
}));

const dragCleanups: Array<() => void> = [];

let monitorCleanup: (() => void) | null = null;

function releaseTiles(): void {
    while (dragCleanups.length > 0) {
        dragCleanups.pop()?.();
    }
}

function widgetIdOf(data: Record<string | symbol, unknown>): string | null {
    const value = data.widgetId;

    return typeof value === 'string' ? value : null;
}

async function registerTiles(): Promise<void> {
    releaseTiles();

    if (!props.canUpdate) {
        return;
    }

    await nextTick();

    const root = gridRoot.value;

    if (root === null) {
        return;
    }

    root.querySelectorAll<HTMLElement>('[data-dashboard-widget]').forEach(
        (element) => {
            const widgetId = element.getAttribute('data-dashboard-widget');

            if (widgetId === null) {
                return;
            }

            dragCleanups.push(
                draggable({
                    element,
                    getInitialData: () => ({ type: DRAG_TYPE, widgetId }),
                }),
                dropTargetForElements({
                    element,
                    getData: () => ({ type: DRAG_TYPE, widgetId }),
                    onDragEnter: () => {
                        dropTargetId.value = widgetId;
                    },
                    onDragLeave: () => {
                        if (dropTargetId.value === widgetId) {
                            dropTargetId.value = null;
                        }
                    },
                }),
            );
        },
    );
}

onMounted(() => {
    monitorCleanup = monitorForElements({
        onDrop: ({ source, location }) => {
            dropTargetId.value = null;

            if (!props.canUpdate) {
                return;
            }

            const target = location.current.dropTargets[0];

            if (target === undefined) {
                return;
            }

            const sourceId = widgetIdOf(source.data);
            const targetId = widgetIdOf(target.data);

            if (sourceId === null || targetId === null) {
                return;
            }

            moveBefore(sourceId, targetId);
        },
    });

    void registerTiles();

    if (props.widgets.length > 0) {
        void refreshAll();
    }
});

onScopeDispose(() => {
    releaseTiles();
    monitorCleanup?.();
    monitorCleanup = null;
});

watch(
    widgets,
    () => {
        void registerTiles();
    },
    { flush: 'post' },
);

function openEditor(widgetId: string | null, position?: number): void {
    editingWidgetId.value = widgetId;
    insertIndex.value = position ?? null;
    editorOpen.value = true;
}

function closeEditor(): void {
    editorOpen.value = false;
    editingWidgetId.value = null;
    insertIndex.value = null;
}

function editedWidget(): DashboardWidgetMeta | null {
    return (
        widgets.value.find((entry) => entry.id === editingWidgetId.value) ??
        null
    );
}

async function submitEditor(payload: Record<string, unknown>): Promise<void> {
    editorProcessing.value = true;

    const widgetId = editingWidgetId.value;
    const saved =
        widgetId === null
            ? await addWidget(payload, insertIndex.value ?? undefined)
            : await updateWidget(widgetId, payload);

    editorProcessing.value = false;

    if (saved === null) {
        return;
    }

    closeEditor();
    await refreshOne(saved.id);
}

function onSpanChange(payload: {
    widgetId: string;
    span: DashboardColumnSpan;
}): void {
    setSpan(payload.widgetId, payload.span);
}

function onMoveBy(payload: { widgetId: string; offset: -1 | 1 }): void {
    moveBy(payload.widgetId, payload.offset);
}

function onDrillDown(payload: {
    widgetId: string;
    selection: ReportDrillDownSelection;
}): void {
    const slug = tiles.value[payload.widgetId]?.object_type?.slug;

    if (slug === undefined) {
        return;
    }

    drillDown.open({
        dashboardId: props.dashboardId,
        widgetId: payload.widgetId,
        objectTypeSlug: slug,
        selection: payload.selection,
    });
}

function onRemoveWidget(widgetId: string): void {
    void removeWidget(widgetId);
}

defineExpose({ refreshAll, openEditor, isRefreshingAll });
</script>

<template>
    <section class="flex flex-col gap-4">
        <div
            data-dashboard-announcer
            role="status"
            aria-live="polite"
            aria-atomic="true"
            class="sr-only"
        >
            {{ announcement }}
        </div>

        <p
            v-if="gridError !== null"
            data-dashboard-error
            class="text-sm text-destructive"
        >
            {{ gridError }}
        </p>

        <div
            v-if="widgets.length === 0"
            data-dashboard-empty
            class="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-10 text-center"
        >
            <EmptyState
                :icon="LayoutDashboard"
                :title="DASHBOARD_EMPTY_TITLE"
                :description="DASHBOARD_EMPTY_DESCRIPTION"
            />
            <CreateButton
                v-bind="addWidgetProps"
                data-dashboard-add-widget
                @click="openEditor(null)"
            />
        </div>

        <div
            v-else
            ref="gridRoot"
            data-dashboard-grid
            class="grid grid-cols-1 gap-4 md:grid-cols-3"
        >
            <template v-for="(row, rowIndex) in rows" :key="row.key">
                <DashboardWidget
                    v-for="widget in row.widgets"
                    :key="widget.id"
                    :class="COLUMN_SPAN_CLASS[widget.column_span]"
                    :widget="widget"
                    :tile="tiles[widget.id] ?? null"
                    :can-update="props.canUpdate"
                    :update-reason="props.updateReason"
                    :is-refreshing="pending.has(widget.id)"
                    :is-drop-target="dropTargetId === widget.id"
                    @refresh="refreshOne"
                    @edit-widget="openEditor"
                    @remove-widget="onRemoveWidget"
                    @span-change="onSpanChange"
                    @move-by="onMoveBy"
                    @drill-down="onDrillDown"
                />

                <div
                    v-if="row.addSlotClass !== null"
                    data-dashboard-add-slot
                    class="flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-4 py-8 text-center"
                    :class="[
                        row.addSlotClass,
                        rowIndex === rows.length - 1
                            ? 'flex'
                            : 'hidden md:flex',
                    ]"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ ADD_TILE_HINT }}
                    </p>
                    <CreateButton
                        v-bind="addWidgetProps"
                        data-dashboard-add-widget
                        @click="openEditor(null, row.insertIndex)"
                    />
                </div>
            </template>
        </div>

        <WidgetEditorSheet
            v-if="editorOpen"
            :dashboard-id="props.dashboardId"
            :widget="editedWidget()"
            :report-options="props.reportOptions"
            :goal-options="props.goalOptions"
            :object-type-options="props.objectTypeOptions"
            :fields-by-type="props.fieldsByType"
            :linked-fields-by-type="props.linkedFieldsByType"
            :segments-by-type="props.segmentsByType"
            :errors="validationErrors"
            :processing="editorProcessing"
            @submit="submitEditor"
            @close="closeEditor"
        />
    </section>
</template>
