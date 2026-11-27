<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef } from 'ag-grid-community';
import { computed } from 'vue';
import ReminderTypesController from '@/actions/App/Http/Controllers/Engine/ReminderTypesController';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';

const { t } = useI18n();

interface ReminderTypeRow {
  id: string;
  name: string;
  can_update: boolean;
  can_delete: boolean;
}

const props = withDefaults(
  defineProps<{
    reminderTypes?: ReminderTypeRow[];
  }>(),
  { reminderTypes: () => [] },
);

const { can } = usePermissions();

const deniedReason = t('i18n.pages.reminder_types.index.no_permission');

const reminderTypes = computed<ReminderTypeRow[]>(() => props.reminderTypes ?? []);

function destroyUrl(row: ReminderTypeRow): string {
  return ReminderTypesController.destroy.url({ reminderType: row.id });
}

function bulkDestroyUrl(): string {
  return ReminderTypesController.bulkDestroy.url();
}

function editUrl(row: ReminderTypeRow): string {
  return ReminderTypesController.edit.url({ reminderType: row.id });
}

function goToCreate(): void {
  router.visit(ReminderTypesController.create.url());
}

function goToEdit(row: ReminderTypeRow): void {
  router.visit(ReminderTypesController.edit.url({ reminderType: row.id }));
}

const columns = computed<ColDef<ReminderTypeRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.reminder_types.index.name'),
    flex: 1,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: ReminderTypeRow): string | undefined =>
        row.can_update ? ReminderTypesController.edit.url({ reminderType: row.id }) : undefined,
    },
  },
]);

const rowOpen = computed<ListGridRowOpen<ReminderTypeRow>>(() => ({
  label: t('i18n.pages.reminder_types.index.edit'),
  testId: 'reminder-type-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
}));

const rowDelete = computed<ListGridRowDelete<ReminderTypeRow>>(() => ({
  label: t('i18n.pages.reminder_types.index.delete'),
  testId: 'reminder-type-delete',
  reason: () => deniedReason,
}));

const copy = computed<ListGridCopy<ReminderTypeRow>>(() => ({
  deleteSelected: t('i18n.pages.reminder_types.index.delete_reminder_types'),
  emptyTitle: t('i18n.pages.reminder_types.index.no_reminder_types_yet'),
  emptyDescription: t('i18n.pages.reminder_types.index.create_the_first_reminder_type_to_categorise_reminders'),
  create: t('i18n.pages.reminder_types.index.create_reminder_type'),
  deleteTitle: t('i18n.pages.reminder_types.index.delete_reminder_type'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.reminder_types.index.the_reminder_type_will_be_deleted_and_can_no', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.reminder_types.index.reminder_types')"
    :description="t('i18n.pages.reminder_types.index.reminder_types_are_the_categories_available_when_adding_a')"
  >
    <template #actions>
      <CreateButton
        v-if="can('reminder-types.create')"
        :href="ReminderTypesController.create.url()"
        :label="t('i18n.pages.reminder_types.index.create_reminder_type')"
      />
    </template>

    <ListGrid
      :rows="reminderTypes"
      :columns="columns"
      :grid-label="t('i18n.pages.reminder_types.index.reminder_types')"
      :can-create="can('reminder-types.create')"
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
