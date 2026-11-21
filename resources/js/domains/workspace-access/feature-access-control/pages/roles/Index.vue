<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueFormatterParams, ValueGetterParams } from 'ag-grid-community';
import { computed } from 'vue';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import type { StatusMap } from '@/shared/lib/statusMaps';
import { systemRoleLabel } from '@/shared/lib/systemValueLabels';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import type { RoleOption, RoleRow } from '@/shared/types/roles';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import { StatusBadgeCellRenderer } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';

const { t } = useI18n();

const props = defineProps<{
  roles: RoleRow[];
  scopes: RoleOption[];
  authorities: RoleOption[];
}>();

const { can } = usePermissions();

const deniedReason = t('i18n.pages.roles.index.no_permission');

const ROLE_ORIGIN: StatusMap = {
  system: {
    label: 'i18n.pages.roles.index.system',
    variant: 'secondary',
  },
  custom: {
    label: 'i18n.pages.roles.index.custom',
    variant: 'outline',
  },
};

const scopeLabels = computed<Record<string, string>>(() =>
  Object.fromEntries(props.scopes.map((option) => [option.value, option.label])),
);

const authorityLabels = computed<Record<string, string>>(() =>
  Object.fromEntries(props.authorities.map((option) => [option.value, option.label])),
);

const emptyDescription = computed<string>(() =>
  can('roles.create')
    ? t('i18n.pages.roles.index.create_the_first_role_to_assign_permissions')
    : t('i18n.pages.roles.index.no_roles_available'),
);

function destroyUrl(row: RoleRow): string {
  return RolesController.destroy.url({ role: row.id });
}

function bulkDestroyUrl(): string {
  return RolesController.bulkDestroy.url();
}

function goToCreate(): void {
  router.visit(RolesController.create.url());
}

function goToEdit(row: RoleRow): void {
  router.visit(RolesController.edit.url({ role: row.id }));
}

function deleteReason(row: RoleRow): string {
  return row.delete_reason ?? deniedReason;
}

const columns = computed<ColDef<RoleRow>[]>(() => [
  {
    colId: 'name',
    headerName: t('i18n.pages.roles.index.name'),
    flex: 2,
    valueGetter: (params: ValueGetterParams<RoleRow>): string =>
      params.data === undefined ? '' : t(systemRoleLabel(params.data.name, params.data.is_system)),
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: RoleRow): string | undefined =>
        row.can_update ? RolesController.edit.url({ role: row.id }) : undefined,
    },
  },
  {
    colId: 'scope',
    field: 'scope',
    headerName: t('i18n.pages.roles.index.scope'),
    flex: 1,
    valueFormatter: (params: ValueFormatterParams<RoleRow>): string => scopeLabels.value[params.value] ?? params.value,
  },
  {
    colId: 'authority',
    field: 'authority',
    headerName: t('i18n.pages.roles.index.authority'),
    flex: 1,
    valueFormatter: (params: ValueFormatterParams<RoleRow>): string =>
      params.value === null || params.value === undefined ? '—' : (authorityLabels.value[params.value] ?? params.value),
  },
  {
    colId: 'origin',
    headerName: t('i18n.pages.roles.index.kind'),
    width: 180,
    valueGetter: (params: ValueGetterParams<RoleRow>): string =>
      params.data?.is_system === true ? 'system' : 'custom',
    cellRenderer: StatusBadgeCellRenderer,
    cellRendererParams: { statusMap: ROLE_ORIGIN },
  },
  {
    colId: 'user_count',
    field: 'user_count',
    headerName: t('i18n.pages.roles.index.user'),
    width: 120,
    type: 'number',
  },
]);

const rowOpen = computed<ListGridRowOpen<RoleRow>>(() => ({
  label: t('i18n.pages.roles.index.edit'),
  isAllowed: (row) => row.can_update,
  reason: () => deniedReason,
}));

const rowDelete = computed<ListGridRowDelete<RoleRow>>(() => ({
  label: t('i18n.pages.roles.index.delete'),
  reason: deleteReason,
}));

const copy = computed<ListGridCopy<RoleRow>>(() => ({
  emptyTitle: t('i18n.pages.roles.index.no_roles_yet'),
  emptyDescription: emptyDescription.value,
  create: t('i18n.pages.roles.index.create_role'),
  deleteTitle: t('i18n.pages.roles.index.delete_role'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.roles.index.the_role_will_be_permanently_deleted_this_cannot_be', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.roles.index.roles')"
    :description="t('i18n.pages.roles.index.roles_determine_who_may_do_what_in_the_system')"
  >
    <template #actions>
      <CreateButton
        v-if="can('roles.create')"
        :href="RolesController.create.url()"
        :label="t('i18n.pages.roles.index.create_role')"
      />
    </template>

    <ListGrid
      :rows="roles"
      :columns="columns"
      :grid-label="t('i18n.pages.roles.index.roles')"
      :can-create="can('roles.create')"
      :copy="copy"
      :destroy-url="destroyUrl"
      :bulk-destroy-url="bulkDestroyUrl"
      :row-open="rowOpen"
      :row-delete="rowDelete"
      preference-key="roles"
      @create="goToCreate"
      @open="goToEdit"
    />
  </ListPage>
</template>
