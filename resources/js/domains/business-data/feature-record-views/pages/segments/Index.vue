<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed } from 'vue';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
import { useI18n } from '@/shared/composables/useI18n';
import { SEGMENT_ORIGIN } from '@/shared/lib/statusMaps';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';

const { t } = useI18n();

interface SegmentRow {
  id: string;
  name: string;
  object_type: string | null;
  is_owner: boolean;
  can_update: boolean;
  can_delete: boolean;
}

const props = withDefaults(
  defineProps<{
    segments?: SegmentRow[];
  }>(),
  { segments: () => [] },
);

const deniedReason = t('i18n.pages.segments.index.no_permission');

const segments = computed<SegmentRow[]>(() => props.segments ?? []);

function destroyUrl(row: SegmentRow): string {
  return SegmentManagementController.destroy.url({ segment: row.id });
}

function bulkDestroyUrl(): string {
  return SegmentManagementController.bulkDestroy.url();
}

function editUrl(row: SegmentRow): string {
  return SegmentManagementController.edit.url({ segment: row.id });
}

function goToCreate(): void {
  router.visit(SegmentManagementController.create.url());
}

function goToEdit(row: SegmentRow): void {
  router.visit(SegmentManagementController.edit.url({ segment: row.id }));
}

const columns = computed<ColDef<SegmentRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.segments.index.name'),
    flex: 2,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: SegmentRow): string | undefined =>
        row.can_update ? SegmentManagementController.edit.url({ segment: row.id }) : undefined,
    },
  },
  {
    colId: 'object_type',
    field: 'object_type',
    headerName: t('i18n.pages.segments.index.object_type'),
    flex: 1,
    minWidth: 160,
  },
  statusBadgeColumn<SegmentRow>('origin', SEGMENT_ORIGIN, {
    headerName: t('i18n.pages.segments.index.origin'),
    flex: 1,
    minWidth: 140,
    valueGetter: (params: ValueGetterParams<SegmentRow>): string =>
      params.data?.is_owner === false ? 'shared' : 'owner',
  }),
]);

const rowOpen: ListGridRowOpen<SegmentRow> = {
  label: t('i18n.pages.segments.index.edit'),
  testId: 'segment-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
};

const rowDelete: ListGridRowDelete<SegmentRow> = {
  label: t('i18n.pages.segments.index.delete'),
  testId: 'segment-delete',
  reason: () => deniedReason,
};

const copy = computed<ListGridCopy<SegmentRow>>(() => ({
  deleteSelected: t('i18n.pages.segments.index.delete_segments'),
  emptyTitle: t('i18n.pages.segments.index.no_segments_yet'),
  emptyDescription: t('i18n.pages.segments.index.create_your_first_segment_to_see_filtered_records'),
  create: t('i18n.pages.segments.index.create_segment'),
  deleteTitle: t('i18n.pages.segments.index.delete_segment'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.segments.index.the_segment_will_be_deleted_and_will_no_longer', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.segments.index.segments')"
    :description="t('i18n.pages.segments.index.create_segments_rename_them_or_edit_their_filters')"
  >
    <template #actions>
      <CreateButton
        :href="SegmentManagementController.create.url()"
        :label="t('i18n.pages.segments.index.create_segment')"
      />
    </template>

    <ListGrid
      :rows="segments"
      :columns="columns"
      :grid-label="t('i18n.pages.segments.index.segments')"
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
