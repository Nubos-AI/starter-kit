<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef } from 'ag-grid-community';
import { computed } from 'vue';
import ActivityTypesController from '@/actions/App/Http/Controllers/Engine/ActivityTypesController';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';

const { t } = useI18n();

interface ActivityTypeRow {
  id: string;
  name: string;
  can_update: boolean;
  can_delete: boolean;
}

const props = withDefaults(
  defineProps<{
    activityTypes?: ActivityTypeRow[];
  }>(),
  { activityTypes: () => [] },
);

const { can } = usePermissions();

const deniedReason = t('i18n.pages.activity_types.index.no_permission');

const activityTypes = computed<ActivityTypeRow[]>(() => props.activityTypes ?? []);

function destroyUrl(row: ActivityTypeRow): string {
  return ActivityTypesController.destroy.url({ activityType: row.id });
}

function bulkDestroyUrl(): string {
  return ActivityTypesController.bulkDestroy.url();
}

function editUrl(row: ActivityTypeRow): string {
  return ActivityTypesController.edit.url({ activityType: row.id });
}

function goToCreate(): void {
  router.visit(ActivityTypesController.create.url());
}

function goToEdit(row: ActivityTypeRow): void {
  router.visit(ActivityTypesController.edit.url({ activityType: row.id }));
}

const columns = computed<ColDef<ActivityTypeRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.activity_types.index.name'),
    flex: 1,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: ActivityTypeRow): string | undefined =>
        row.can_update ? ActivityTypesController.edit.url({ activityType: row.id }) : undefined,
    },
  },
]);

const rowOpen = computed<ListGridRowOpen<ActivityTypeRow>>(() => ({
  label: t('i18n.pages.activity_types.index.edit'),
  testId: 'activity-type-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
}));

const rowDelete = computed<ListGridRowDelete<ActivityTypeRow>>(() => ({
  label: t('i18n.pages.activity_types.index.delete'),
  testId: 'activity-type-delete',
  reason: () => deniedReason,
}));

const copy = computed<ListGridCopy<ActivityTypeRow>>(() => ({
  deleteSelected: t('i18n.pages.activity_types.index.delete_activity_types'),
  emptyTitle: t('i18n.pages.activity_types.index.no_activity_types_yet'),
  emptyDescription: t('i18n.pages.activity_types.index.create_the_first_activity_type_to_categorise_activities'),
  create: t('i18n.pages.activity_types.index.create_activity_type'),
  deleteTitle: t('i18n.pages.activity_types.index.delete_activity_type'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.activity_types.index.the_activity_type_will_be_deleted_and_can_no', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.activity_types.index.activity_types')"
    :description="t('i18n.pages.activity_types.index.activity_types_are_the_categories_available_when_adding_an')"
  >
    <template #actions>
      <CreateButton
        v-if="can('activity-types.create')"
        :href="ActivityTypesController.create.url()"
        :label="t('i18n.pages.activity_types.index.create_activity_type')"
      />
    </template>

    <ListGrid
      :rows="activityTypes"
      :columns="columns"
      :grid-label="t('i18n.pages.activity_types.index.activity_types')"
      :can-create="can('activity-types.create')"
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
