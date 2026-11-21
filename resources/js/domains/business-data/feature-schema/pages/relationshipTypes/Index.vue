<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed } from 'vue';
import RelationshipTypesController from '@/actions/App/Http/Controllers/Engine/RelationshipTypesController';
import type { RelationshipTypeRow } from '@/domains/business-data/data';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import { relationCardinalityLabel } from '@/shared/lib/systemValueLabels';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    relationshipTypes?: RelationshipTypeRow[];
  }>(),
  { relationshipTypes: () => [] },
);

const { can } = usePermissions();

const deniedReason = t('i18n.pages.relationship_types.index.no_permission');

const relationshipTypes = computed<RelationshipTypeRow[]>(() => props.relationshipTypes ?? []);

function destroyUrl(row: RelationshipTypeRow): string {
  return RelationshipTypesController.destroy.url({ relationshipType: row.id });
}

function bulkDestroyUrl(): string {
  return RelationshipTypesController.bulkDestroy.url();
}

function editUrl(row: RelationshipTypeRow): string {
  return RelationshipTypesController.edit.url({ relationshipType: row.id });
}

function goToCreate(): void {
  router.visit(RelationshipTypesController.create.url());
}

function goToEdit(row: RelationshipTypeRow): void {
  router.visit(RelationshipTypesController.edit.url({ relationshipType: row.id }));
}

const columns = computed<ColDef<RelationshipTypeRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.relationship_types.index.name'),
    flex: 2,
    minWidth: 180,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: RelationshipTypeRow): string | undefined =>
        row.can_update
          ? RelationshipTypesController.edit.url({
              relationshipType: row.id,
            })
          : undefined,
    },
  },
  {
    colId: 'inverse_name',
    field: 'inverse_name',
    headerName: t('i18n.pages.relationship_types.index.reverse_name'),
    flex: 1,
    minWidth: 160,
  },
  {
    colId: 'from_object_type',
    field: 'from_object_type',
    headerName: t('i18n.pages.relationship_types.index.from'),
    flex: 1,
    minWidth: 140,
  },
  {
    colId: 'to_object_type',
    field: 'to_object_type',
    headerName: t('i18n.pages.relationship_types.index.to'),
    flex: 1,
    minWidth: 140,
  },
  {
    colId: 'cardinality',
    field: 'cardinality',
    headerName: t('i18n.pages.relationship_types.index.cardinality'),
    flex: 1,
    minWidth: 160,
    valueFormatter: (params: ValueFormatterParams<RelationshipTypeRow>): string =>
      t(relationCardinalityLabel(String(params.value ?? ''))),
  },
]);

const rowOpen = computed<ListGridRowOpen<RelationshipTypeRow>>(() => ({
  label: t('i18n.pages.relationship_types.index.edit'),
  testId: 'relationship-type-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
}));

const rowDelete = computed<ListGridRowDelete<RelationshipTypeRow>>(() => ({
  label: t('i18n.pages.relationship_types.index.delete'),
  testId: 'relationship-type-delete',
  reason: () => deniedReason,
}));

const copy = computed<ListGridCopy<RelationshipTypeRow>>(() => ({
  deleteSelected: t('i18n.pages.relationship_types.index.delete_relationship_types'),
  emptyTitle: t('i18n.pages.relationship_types.index.no_relationship_types_yet'),
  emptyDescription: t('i18n.pages.relationship_types.index.create_the_first_relationship_type_to_link_records'),
  create: t('i18n.pages.relationship_types.index.create_relationship_type'),
  deleteTitle: t('i18n.pages.relationship_types.index.delete_relationship_type'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.relationship_types.index.the_relationship_type_will_be_permanently_deleted_existing_links', {
          value1: row.name,
        }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.relationship_types.index.relationship_types')"
    :description="t('i18n.pages.relationship_types.index.relationship_types_define_how_records_of_one_object_type')"
  >
    <template #actions>
      <CreateButton
        v-if="can('object-types.create')"
        :href="RelationshipTypesController.create.url()"
        :label="t('i18n.pages.relationship_types.index.create_relationship_type')"
      />
    </template>

    <ListGrid
      :rows="relationshipTypes"
      :columns="columns"
      :grid-label="t('i18n.pages.relationship_types.index.relationship_types')"
      :can-create="can('object-types.create')"
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
