<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ValueFormatterParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, watch } from 'vue';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import { StatusBadgeCellRenderer } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';
import { useRowDeletion } from '@/composables/useRowDeletion';
import type { StatusMap } from '@/lib/statusMaps';
import { systemRoleLabel } from '@/lib/systemValueLabels';
import type { RoleOption, RoleRow } from '@/types/roles';

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
        label: t('i18n.pages.roles.index.system'),
        variant: 'secondary',
    },
    custom: {
        label: t('i18n.pages.roles.index.custom'),
        variant: 'outline',
    },
};

const scopeLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.scopes.map((option) => [option.value, option.label]),
    ),
);

const authorityLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.authorities.map((option) => [option.value, option.label]),
    ),
);

const listState = computed<'empty' | 'ready'>(() =>
    props.roles.length === 0 ? 'empty' : 'ready',
);

const emptyDescription = computed<string>(() =>
    can('roles.create')
        ? t(
              'i18n.pages.roles.index.create_the_first_role_to_assign_permissions',
          )
        : t('i18n.pages.roles.index.no_roles_available'),
);

const {
    pendingDelete,
    deletePending,
    deleteDialogOpen: confirmOpen,
    requestDelete,
    confirmDelete,
} = useRowDeletion<RoleRow>({
    url: (row) => RolesController.destroy.url({ role: row.id }),
});

const confirmDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.roles.index.the_role_will_be_permanently_deleted_this_cannot_be',
              { value1: pendingDelete.value.name },
          ),
);

const selection = useListSelection();

function isDeletable(row: RoleRow): boolean {
    return row.can_delete;
}

watch(
    () => props.roles,
    (rows) => selection.prune(rows.filter(isDeletable).map((row) => row.id)),
);

function bulkDeleteRoles(): void {
    router.post(
        RolesController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
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

const columnDefs = computed<ColDef<RoleRow>[]>(() => [
    selectionColumn<RoleRow>(selection, isDeletable),
    {
        colId: 'name',
        headerName: t('i18n.pages.roles.index.name'),
        flex: 2,
        valueGetter: (params: ValueGetterParams<RoleRow>): string =>
            params.data === undefined
                ? ''
                : systemRoleLabel(params.data.name, params.data.is_system),
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: RoleRow): string | undefined =>
                row.can_update
                    ? RolesController.edit.url({ role: row.id })
                    : undefined,
        },
    },
    {
        colId: 'scope',
        field: 'scope',
        headerName: t('i18n.pages.roles.index.scope'),
        flex: 1,
        valueFormatter: (params: ValueFormatterParams<RoleRow>): string =>
            scopeLabels.value[params.value] ?? params.value,
    },
    {
        colId: 'authority',
        field: 'authority',
        headerName: t('i18n.pages.roles.index.authority'),
        flex: 1,
        valueFormatter: (params: ValueFormatterParams<RoleRow>): string =>
            params.value === null || params.value === undefined
                ? '—'
                : (authorityLabels.value[params.value] ?? params.value),
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
    actionsColumn<RoleRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.roles.index.edit'),
            variant: 'edit',
            onClick: (row) => goToEdit(row),
            isDisabled: (row) => !row.can_update,
            disabledReason: () => deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.roles.index.delete'),
            variant: 'destructive',
            onClick: (row) => requestDelete(row),
            isDisabled: (row) => !isDeletable(row),
            disabledReason: (row) => deleteReason(row),
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.roles.index.roles')"
        :description="
            t(
                'i18n.pages.roles.index.roles_determine_who_may_do_what_in_the_system',
            )
        "
    >
        <template #actions>
            <CreateButton
                v-if="can('roles.create')"
                :href="RolesController.create.url()"
                :label="t('i18n.pages.roles.index.create_role')"
            />
        </template>

        <SelectionBulkBar
            :count="selection.count.value"
            @delete="bulkDeleteRoles"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('roles.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                :empty-title="t('i18n.pages.roles.index.no_roles_yet')"
                :empty-description="emptyDescription"
                :create-label="t('i18n.pages.roles.index.create_role')"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    preference-key="roles"
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="roles"
                    :is-row-activatable="(row: RoleRow) => row.can_update"
                    :aria-label="t('i18n.pages.roles.index.roles')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="t('i18n.pages.roles.index.delete_role')"
            :description="confirmDescription"
            :confirm-label="t('i18n.pages.roles.index.delete')"
            :cancel-label="t('i18n.pages.roles.index.cancel')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
