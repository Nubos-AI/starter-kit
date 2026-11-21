<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueFormatterParams, ValueGetterParams } from 'ag-grid-community';
import { computed } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import type { DashboardRow } from '@/domains/analytics/data';
import { dashboardActionRefusals } from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { resolveActionRefusal } from '@/shared/lib/actionRefusal';
import { formatDateTime } from '@/shared/lib/formatDate';
import { DASHBOARD_START_PAGE, DASHBOARD_VISIBILITY } from '@/shared/lib/statusMaps';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    dashboards?: DashboardRow[];
  }>(),
  { dashboards: () => [] },
);

const dashboards = computed<DashboardRow[]>(() => props.dashboards ?? []);

function destroyUrl(row: DashboardRow): string {
  return DashboardsController.destroy.url({ dashboard: row.id });
}

function bulkDestroyUrl(): string {
  return DashboardsController.bulkDestroy.url();
}

function showUrl(row: DashboardRow): string {
  return DashboardsController.show.url({ dashboard: row.id });
}

function goToCreate(): void {
  router.visit(DashboardsController.create.url());
}

function goToShow(row: DashboardRow): void {
  router.visit(showUrl(row));
}

const columns = computed<ColDef<DashboardRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.dashboards.index.name'),
    flex: 2,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: DashboardRow): string => showUrl(row),
    },
  },
  {
    colId: 'description',
    field: 'description',
    headerName: t('i18n.pages.dashboards.index.description'),
    flex: 2,
    minWidth: 200,
  },
  statusBadgeColumn<DashboardRow>('visibility', DASHBOARD_VISIBILITY, {
    headerName: t('i18n.pages.dashboards.index.visibility'),
    flex: 1,
    minWidth: 160,
    valueGetter: (params: ValueGetterParams<DashboardRow>): string | null =>
      params.data === undefined ? null : params.data.is_tenant_wide ? 'tenant_wide' : 'private',
  }),
  statusBadgeColumn<DashboardRow>('start_page', DASHBOARD_START_PAGE, {
    headerName: t('i18n.pages.dashboards.index.home'),
    flex: 1,
    minWidth: 130,
    valueGetter: (params: ValueGetterParams<DashboardRow>): string | null =>
      params.data?.is_default === true ? 'start_page' : null,
  }),
  {
    colId: 'updated_at',
    field: 'updated_at',
    headerName: t('i18n.pages.dashboards.index.last_change'),
    flex: 1,
    minWidth: 170,
    valueFormatter: (params: ValueFormatterParams<DashboardRow>): string =>
      formatDateTime(typeof params.value === 'string' ? params.value : null),
  },
]);

const rowOpen = computed<ListGridRowOpen<DashboardRow>>(() => ({
  label: t('i18n.pages.dashboards.index.open'),
  testId: 'dashboard-open',
  href: showUrl,
  isAllowed: () => true,
  reason: () => undefined,
}));

const rowDelete = computed<ListGridRowDelete<DashboardRow>>(() => ({
  label: t('i18n.pages.dashboards.index.delete'),
  testId: 'dashboard-delete',
  reason: (row) => resolveActionRefusal(row.delete_reason, dashboardActionRefusals, t),
}));

const copy = computed<ListGridCopy<DashboardRow>>(() => ({
  deleteSelected: t('i18n.pages.dashboards.index.delete_dashboards'),
  emptyTitle: t('i18n.pages.dashboards.index.no_dashboards_yet'),
  emptyDescription: t('i18n.pages.dashboards.index.create_your_first_dashboard_to_see_metrics_and_charts'),
  create: t('i18n.pages.dashboards.index.create_dashboard'),
  deleteTitle: t('i18n.pages.dashboards.index.delete_dashboard'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.dashboards.index.the_dashboard_will_be_deleted_and_will_no_longer', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.dashboards.index.dashboards')"
    :description="t('i18n.pages.dashboards.index.create_open_or_remove_dashboards')"
  >
    <template #actions>
      <CreateButton
        :href="DashboardsController.create.url()"
        :label="t('i18n.pages.dashboards.index.create_dashboard')"
      />
    </template>

    <ListGrid
      :rows="dashboards"
      :columns="columns"
      :grid-label="t('i18n.pages.dashboards.index.dashboards')"
      :copy="copy"
      :destroy-url="destroyUrl"
      :bulk-destroy-url="bulkDestroyUrl"
      :row-open="rowOpen"
      :row-delete="rowDelete"
      @create="goToCreate"
      @open="goToShow"
    />
  </ListPage>
</template>
