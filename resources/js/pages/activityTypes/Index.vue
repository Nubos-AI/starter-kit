<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import ActivityTypesController from '@/actions/App/Http/Controllers/Engine/ActivityTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';

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

const activityTypes = computed<ActivityTypeRow[]>(
    () => props.activityTypes ?? [],
);

const listState = computed<'empty' | 'ready'>(() =>
    activityTypes.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(activityTypes, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<ActivityTypeRow | null>(null);
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
              'i18n.pages.activity_types.index.the_activity_type_will_be_deleted_and_can_no',
              { value1: pendingDelete.value.name },
          ),
);

function bulkDeleteActivityTypes(): void {
    router.post(
        ActivityTypesController.bulkDestroy.url(),
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

    router.delete(
        ActivityTypesController.destroy.url({ activityType: row.id }),
        {
            preserveScroll: true,
            onFinish: () => {
                deletePending.value = false;
                pendingDelete.value = null;
            },
        },
    );
}

function goToCreate(): void {
    router.visit(ActivityTypesController.create.url());
}

function goToEdit(row: ActivityTypeRow): void {
    router.visit(ActivityTypesController.edit.url({ activityType: row.id }));
}

const columnDefs = computed<ColDef<ActivityTypeRow>[]>(() => [
    selectionColumn<ActivityTypeRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.activity_types.index.name'),
        flex: 1,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: ActivityTypeRow): string | undefined =>
                row.can_update
                    ? ActivityTypesController.edit.url({ activityType: row.id })
                    : undefined,
        },
    },
    actionsColumn<ActivityTypeRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.activity_types.index.edit'),
            variant: 'edit',
            testId: 'activity-type-edit',
            href: (row) =>
                ActivityTypesController.edit.url({ activityType: row.id }),
            isDisabled: (row) => !row.can_update,
            disabledReason: () => deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.activity_types.index.delete'),
            variant: 'destructive',
            testId: 'activity-type-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: () => deniedReason,
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.activity_types.index.activity_types')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.activity_types.index.activity_types')"
                :description="
                    t(
                        'i18n.pages.activity_types.index.activity_types_are_the_categories_available_when_adding_an',
                    )
                "
            />
            <CreateButton
                v-if="can('activity-types.create')"
                :href="ActivityTypesController.create.url()"
                :label="
                    t('i18n.pages.activity_types.index.create_activity_type')
                "
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Aktivitätstypen löschen"
            @delete="bulkDeleteActivityTypes"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('activity-types.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                empty-title="Noch keine Aktivitätstypen"
                empty-description="Legen Sie den ersten Aktivitätstyp an, um Aktivitäten zu kategorisieren."
                create-label="Aktivitätstyp anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="activityTypes"
                    :is-row-activatable="
                        (row: ActivityTypeRow) => row.can_update
                    "
                    :aria-label="
                        t('i18n.pages.activity_types.index.activity_types')
                    "
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.activity_types.index.delete_activity_type')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.activity_types.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
