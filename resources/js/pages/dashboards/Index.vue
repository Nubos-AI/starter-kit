<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ValueFormatterParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
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
import { DASHBOARD_START_PAGE, DASHBOARD_VISIBILITY } from '@/lib/statusMaps';
import type { DashboardRow } from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        dashboards?: DashboardRow[];
    }>(),
    { dashboards: () => [] },
);

const dashboards = computed<DashboardRow[]>(() => props.dashboards ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    dashboards.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(dashboards, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<DashboardRow | null>(null);
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
              'i18n.pages.dashboards.index.the_dashboard_will_be_deleted_and_will_no_longer',
              { value1: pendingDelete.value.name },
          ),
);

function showUrl(row: DashboardRow): string {
    return DashboardsController.show.url({ dashboard: row.id });
}

function bulkDeleteDashboards(): void {
    router.post(
        DashboardsController.bulkDestroy.url(),
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

    router.delete(DashboardsController.destroy.url({ dashboard: row.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
}

function goToCreate(): void {
    router.visit(DashboardsController.create.url());
}

function goToShow(row: DashboardRow): void {
    router.visit(showUrl(row));
}

const columnDefs = computed<ColDef<DashboardRow>[]>(() => [
    selectionColumn<DashboardRow>(selection, (row) => row.can_delete),
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
        valueGetter: (
            params: ValueGetterParams<DashboardRow>,
        ): string | null =>
            params.data === undefined
                ? null
                : params.data.is_tenant_wide
                  ? 'tenant_wide'
                  : 'private',
    }),
    statusBadgeColumn<DashboardRow>('start_page', DASHBOARD_START_PAGE, {
        headerName: t('i18n.pages.dashboards.index.home'),
        flex: 1,
        minWidth: 130,
        valueGetter: (
            params: ValueGetterParams<DashboardRow>,
        ): string | null =>
            params.data?.is_default === true ? 'start_page' : null,
    }),
    {
        colId: 'updated_at',
        field: 'updated_at',
        headerName: t('i18n.pages.dashboards.index.last_change'),
        flex: 1,
        minWidth: 170,
        valueFormatter: (params: ValueFormatterParams<DashboardRow>): string =>
            formatDateTime(
                typeof params.value === 'string' ? params.value : null,
            ),
    },
    actionsColumn<DashboardRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.dashboards.index.open'),
            variant: 'edit',
            testId: 'dashboard-open',
            href: (row) => showUrl(row),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.dashboards.index.delete'),
            variant: 'destructive',
            testId: 'dashboard-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) =>
                resolveDashboardActionRefusal(row.delete_reason),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.dashboards.index.dashboards')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.dashboards.index.dashboards')"
                :description="
                    t(
                        'i18n.pages.dashboards.index.create_open_or_remove_dashboards',
                    )
                "
            />
            <CreateButton
                :href="DashboardsController.create.url()"
                :label="t('i18n.pages.dashboards.index.create_dashboard')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Dashboards löschen"
            @delete="bulkDeleteDashboards"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                empty-title="Noch keine Dashboards"
                empty-description="Legen Sie Ihr erstes Dashboard an, um Kennzahlen und Diagramme an einer Stelle zu sehen."
                create-label="Dashboard anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="dashboards"
                    :is-row-activatable="() => true"
                    :aria-label="t('i18n.pages.dashboards.index.dashboards')"
                    @row-activate="goToShow"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.dashboards.index.delete_dashboard')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.dashboards.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
