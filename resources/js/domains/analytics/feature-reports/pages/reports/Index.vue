<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueFormatterParams, ValueGetterParams } from 'ag-grid-community';
import { computed } from 'vue';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import type { ReportListRow } from '@/domains/analytics/data';
import { REPORT_AGGREGATION_LABELS, REPORT_PRESENTATION_LABELS, reportActionRefusals } from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { resolveActionRefusal } from '@/shared/lib/actionRefusal';
import { formatDateTime } from '@/shared/lib/formatDate';
import { REPORT_EXECUTION_MODE } from '@/shared/lib/statusMaps';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    reports?: ReportListRow[];
  }>(),
  { reports: () => [] },
);

const reports = computed<ReportListRow[]>(() => props.reports ?? []);

function destroyUrl(row: ReportListRow): string {
  return ReportsController.destroy.url({ report: row.id });
}

function bulkDestroyUrl(): string {
  return ReportsController.bulkDestroy.url();
}

function editUrl(row: ReportListRow): string {
  return ReportsController.edit.url({ report: row.id });
}

function goToCreate(): void {
  router.visit(ReportsController.create.url());
}

function goToEdit(row: ReportListRow): void {
  router.visit(editUrl(row));
}

const columns = computed<ColDef<ReportListRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.reports.index.name'),
    flex: 2,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: ReportListRow): string | undefined => (row.can_update ? editUrl(row) : undefined),
    },
  },
  {
    colId: 'object_type',
    headerName: t('i18n.pages.reports.index.object_type'),
    flex: 1,
    minWidth: 160,
    valueGetter: (params: ValueGetterParams<ReportListRow>): string | null => params.data?.object_type?.label ?? null,
  },
  {
    colId: 'aggregation_type',
    field: 'aggregation_type',
    headerName: t('i18n.pages.reports.index.calculation'),
    flex: 1,
    minWidth: 140,
    valueFormatter: (params: ValueFormatterParams<ReportListRow>): string =>
      params.data === undefined ? '' : t(REPORT_AGGREGATION_LABELS[params.data.aggregation_type]),
  },
  {
    colId: 'chart_type',
    field: 'chart_type',
    headerName: t('i18n.pages.reports.index.display'),
    flex: 1,
    minWidth: 140,
    valueFormatter: (params: ValueFormatterParams<ReportListRow>): string =>
      params.data === undefined ? '' : t(REPORT_PRESENTATION_LABELS[params.data.chart_type]),
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
      formatDateTime(typeof params.value === 'string' ? params.value : null),
  },
]);

const rowOpen = computed<ListGridRowOpen<ReportListRow>>(() => ({
  label: t('i18n.pages.reports.index.edit'),
  testId: 'report-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: (row) => resolveActionRefusal(row.update_reason, reportActionRefusals, t),
}));

const rowDelete = computed<ListGridRowDelete<ReportListRow>>(() => ({
  label: t('i18n.pages.reports.index.delete'),
  testId: 'report-delete',
  reason: (row) => resolveActionRefusal(row.delete_reason, reportActionRefusals, t),
}));

const copy = computed<ListGridCopy<ReportListRow>>(() => ({
  deleteSelected: t('i18n.pages.reports.index.delete_reports'),
  emptyTitle: t('i18n.pages.reports.index.no_reports_yet'),
  emptyDescription: t('i18n.pages.reports.index.create_your_first_report_to_see_metrics_and_charts'),
  create: t('i18n.pages.reports.index.create_report'),
  deleteTitle: t('i18n.pages.reports.index.delete_report'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.reports.index.the_report_will_be_deleted_and_will_no_longer', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.reports.index.reports')"
    :description="t('i18n.pages.reports.index.create_reports_edit_their_definitions_or_remove_them')"
  >
    <template #actions>
      <CreateButton :href="ReportsController.create.url()" :label="t('i18n.pages.reports.index.create_report')" />
    </template>

    <ListGrid
      :rows="reports"
      :columns="columns"
      :grid-label="t('i18n.pages.reports.index.reports')"
      :copy="copy"
      :destroy-url="destroyUrl"
      :bulk-destroy-url="bulkDestroyUrl"
      :row-open="rowOpen"
      :row-delete="rowDelete"
      @create="goToCreate"
      @open="goToEdit"
    />
  </ListPage>
</template>
