<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ValueFormatterParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { formatDateTime } from '@/lib/formatDate';
import { REPORT_EXECUTION_MODE } from '@/lib/statusMaps';
import type { ReportListRow } from '@/types/reports';
import {
    REPORT_AGGREGATION_LABELS,
    REPORT_PRESENTATION_LABELS,
    resolveReportActionRefusal,
} from '@/types/reports';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        reports?: ReportListRow[];
    }>(),
    { reports: () => [] },
);

const reports = computed<ReportListRow[]>(() => props.reports ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    reports.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(reports, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<ReportListRow | null>(null);
const deletePending = ref<boolean>(false);

const deleteDialogOpen = computed<boolean>({
    get: () => pendingDelete.value !== null,
    set: (value) => {
        if (!value) {
            pendingDelete.value = null;
        }
    },
});

const deleteDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.reports.index.the_report_will_be_deleted_and_will_no_longer',
              { value1: pendingDelete.value.name },
          ),
);

function editUrl(row: ReportListRow): string {
    return ReportsController.edit.url({ report: row.id });
}

function bulkDeleteReports(): void {
    router.post(
        ReportsController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(ReportsController.destroy.url({ report: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
}

function goToCreate(): void {
    router.visit(ReportsController.create.url());
}

function goToEdit(row: ReportListRow): void {
    router.visit(editUrl(row));
}

const columnDefs = computed<ColDef<ReportListRow>[]>(() => [
    selectionColumn<ReportListRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.reports.index.name'),
        flex: 2,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: ReportListRow): string | undefined =>
                row.can_update ? editUrl(row) : undefined,
        },
    },
    {
        colId: 'object_type',
        headerName: t('i18n.pages.reports.index.object_type'),
        flex: 1,
        minWidth: 160,
        valueGetter: (
            params: ValueGetterParams<ReportListRow>,
        ): string | null => params.data?.object_type?.name ?? null,
    },
    {
        colId: 'aggregation_type',
        field: 'aggregation_type',
        headerName: t('i18n.pages.reports.index.calculation'),
        flex: 1,
        minWidth: 140,
        valueFormatter: (
            params: ValueFormatterParams<ReportListRow>,
        ): string =>
            params.data === undefined
                ? ''
                : REPORT_AGGREGATION_LABELS[params.data.aggregation_type],
    },
    {
        colId: 'chart_type',
        field: 'chart_type',
        headerName: t('i18n.pages.reports.index.display'),
        flex: 1,
        minWidth: 140,
        valueFormatter: (
            params: ValueFormatterParams<ReportListRow>,
        ): string =>
            params.data === undefined
                ? ''
                : REPORT_PRESENTATION_LABELS[params.data.chart_type],
    },
    statusBadgeColumn<ReportListRow>('execution_mode', REPORT_EXECUTION_MODE, {
        headerName: t('i18n.pages.reports.index.execution'),
        flex: 1,
        minWidth: 160,
    }),
    {
        colId: 'updated_at',
        field: 'updated_at',
        headerName: t('i18n.pages.reports.index.last_change'),
        flex: 1,
        minWidth: 170,
        valueFormatter: (params: ValueFormatterParams<ReportListRow>): string =>
            formatDateTime(
                typeof params.value === 'string' ? params.value : null,
            ),
    },
    actionsColumn<ReportListRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.reports.index.edit'),
            variant: 'edit',
            testId: 'report-edit',
            href: (row) => editUrl(row),
            isDisabled: (row) => !row.can_update,
            disabledReason: (row) =>
                resolveReportActionRefusal(row.update_reason),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.reports.index.delete'),
            variant: 'destructive',
            testId: 'report-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) =>
                resolveReportActionRefusal(row.delete_reason),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.reports.index.reports')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.reports.index.reports')"
                :description="
                    t(
                        'i18n.pages.reports.index.create_reports_edit_their_definitions_or_remove_them',
                    )
                "
            />
            <CreateButton
                :href="ReportsController.create.url()"
                :label="t('i18n.pages.reports.index.create_report')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Auswertungen löschen"
            @delete="bulkDeleteReports"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                empty-title="Noch keine Auswertungen"
                empty-description="Legen Sie Ihre erste Auswertung an, um Kennzahlen und Diagramme zu sehen."
                create-label="Auswertung anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="reports"
                    :is-row-activatable="(row: ReportListRow) => row.can_update"
                    :aria-label="t('i18n.pages.reports.index.reports')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.reports.index.delete_report')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.reports.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
