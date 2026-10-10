<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ValueFormatterParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, ref } from 'vue';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import { StatusBadgeCellRenderer } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
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

const pendingDelete = ref<RoleRow | null>(null);
const deletePending = ref<boolean>(false);

const confirmOpen = computed<boolean>({
    get: () => pendingDelete.value !== null,
    set: (value) => {
        if (!value) {
            pendingDelete.value = null;
        }
    },
});

const confirmDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.roles.index.the_role_will_be_permanently_deleted_this_cannot_be',
              { value1: pendingDelete.value.name },
          ),
);

function goToCreate(): void {
    router.visit(RolesController.create.url());
}

function goToEdit(row: RoleRow): void {
    router.visit(RolesController.edit.url({ role: row.id }));
}

function requestDelete(row: RoleRow): void {
    pendingDelete.value = row;
}

function deleteReason(row: RoleRow): string {
    return row.user_count === 1
        ? t('i18n.pages.roles.index.a_user_is_assigned_to_this_role')
        : t('i18n.pages.roles.index.users_are_assigned_to_this_role', {
              value1: row.user_count,
          });
}

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(RolesController.destroy.url({ role: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
}

const columnDefs = computed<ColDef<RoleRow>[]>(() => [
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
            isVisible: (row) => row.can_update,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.roles.index.delete'),
            variant: 'destructive',
            onClick: (row) => requestDelete(row),
            isVisible: (row) => row.can_delete,
            isDisabled: (row) => row.user_count > 0,
            disabledReason: (row) => deleteReason(row),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.roles.index.roles')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.roles.index.roles')"
                :description="
                    t(
                        'i18n.pages.roles.index.roles_determine_who_may_do_what_in_the_system',
                    )
                "
            />
            <CreateButton
                v-if="can('roles.create')"
                :href="RolesController.create.url()"
                :label="t('i18n.pages.roles.index.create_role')"
            />
        </div>

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('roles.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                empty-title="Noch keine Rollen"
                :empty-description="emptyDescription"
                create-label="Rolle anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
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
