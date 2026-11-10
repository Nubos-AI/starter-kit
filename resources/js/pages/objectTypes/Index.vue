<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, watch } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import InputError from '@/components/InputError.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';
import { useRowDeletion } from '@/composables/useRowDeletion';
import { storageStrategyLabel } from '@/lib/systemValueLabels';
import type { ObjectTypeRow } from '@/types/objectTypes';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        objectTypes?: ObjectTypeRow[];
    }>(),
    { objectTypes: () => [] },
);

const { can } = usePermissions();

const systemReason = t(
    'i18n.pages.object_types.index.system_types_cannot_be_changed',
);

const deniedReason = t('i18n.pages.object_types.index.no_permission');

function reasonFor(row: ObjectTypeRow): string {
    return row.is_system ? systemReason : deniedReason;
}

function deleteReasonFor(row: ObjectTypeRow): string {
    return row.delete_reason ?? reasonFor(row);
}

const page = usePage();

function isErrorBag(value: unknown): value is Record<string, string> {
    return typeof value === 'object' && value !== null;
}

const deleteError = computed<string | undefined>(() =>
    isErrorBag(page.props.errors) ? page.props.errors.objectType : undefined,
);

const objectTypes = computed<ObjectTypeRow[]>(() => props.objectTypes ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    objectTypes.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(objectTypes, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } =
    useRowDeletion<ObjectTypeRow>({
        url: (row) =>
            ObjectTypesController.destroy.url({ objectType: row.slug }),
    });

const deleteDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.object_types.index.the_object_type_will_be_permanently_deleted_with_all',
              { value1: pendingDelete.value.name },
          ),
);

function bulkDeleteObjectTypes(): void {
    router.post(
        ObjectTypesController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function goToCreate(): void {
    router.visit(ObjectTypesController.create.url());
}

function goToEdit(row: ObjectTypeRow): void {
    router.visit(ObjectTypesController.edit.url({ objectType: row.slug }));
}

const columnDefs = computed<ColDef<ObjectTypeRow>[]>(() => [
    selectionColumn<ObjectTypeRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.object_types.index.name'),
        flex: 2,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: ObjectTypeRow): string | undefined =>
                row.can_update
                    ? ObjectTypesController.edit.url({ objectType: row.slug })
                    : undefined,
        },
    },
    {
        colId: 'slug',
        field: 'slug',
        headerName: t('i18n.pages.object_types.index.code'),
        flex: 1,
        minWidth: 140,
    },
    {
        colId: 'field_count',
        field: 'field_count',
        headerName: t('i18n.pages.object_types.index.fields'),
        width: 110,
    },
    {
        colId: 'storage_strategy',
        field: 'storage_strategy',
        headerName: t('i18n.pages.object_types.index.storage'),
        flex: 1,
        minWidth: 140,
        valueFormatter: (params: ValueFormatterParams<ObjectTypeRow>): string =>
            storageStrategyLabel(String(params.value ?? '')),
    },
    actionsColumn<ObjectTypeRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.object_types.index.edit'),
            variant: 'edit',
            testId: 'object-type-edit',
            href: (row) =>
                ObjectTypesController.edit.url({ objectType: row.slug }),
            isDisabled: (row) => !row.can_update,
            disabledReason: (row) => reasonFor(row),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.object_types.index.delete'),
            variant: 'destructive',
            testId: 'object-type-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) => deleteReasonFor(row),
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.object_types.index.object_types')"
        :description="
            t(
                'i18n.pages.object_types.index.object_types_define_which_records_exist_and_which_fields',
            )
        "
    >
        <template #actions>
            <CreateButton
                v-if="can('object-types.create')"
                :href="ObjectTypesController.create.url()"
                :label="t('i18n.pages.object_types.index.create_object_type')"
            />
        </template>

        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="
                t('i18n.pages.object_types.index.delete_object_types')
            "
            @delete="bulkDeleteObjectTypes"
            @clear="selection.clear"
        />

        <InputError data-object-type-error :message="deleteError" />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('object-types.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                :empty-title="
                    t('i18n.pages.object_types.index.no_object_types_yet')
                "
                :empty-description="
                    t(
                        'i18n.pages.object_types.index.create_the_first_object_type_to_capture_records',
                    )
                "
                :create-label="
                    t('i18n.pages.object_types.index.create_object_type')
                "
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    preference-key="object-types"
                    class="h-full"
                    :column-defs="columnDefs"
                    :row-data="objectTypes"
                    :is-row-activatable="(row: ObjectTypeRow) => row.can_update"
                    :aria-label="
                        t('i18n.pages.object_types.index.object_types')
                    "
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.object_types.index.delete_object_type')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.object_types.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
