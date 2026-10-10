<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
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
import Heading from '@/components/Heading.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { usePermissions } from '@/composables/usePermissions';
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

const objectTypes = computed<ObjectTypeRow[]>(() => props.objectTypes ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    objectTypes.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(objectTypes, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<ObjectTypeRow | null>(null);
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

function confirmDelete(): void {
    const row = pendingDelete.value;

    if (row === null) {
        return;
    }

    deletePending.value = true;

    router.delete(ObjectTypesController.destroy.url({ objectType: row.slug }), {
        preserveScroll: true,
        onFinish: () => {
            deletePending.value = false;
            pendingDelete.value = null;
        },
    });
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
            disabledReason: (row) => reasonFor(row),
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head :title="t('i18n.pages.object_types.index.object_types')" />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="t('i18n.pages.object_types.index.object_types')"
                :description="
                    t(
                        'i18n.pages.object_types.index.object_types_define_which_records_exist_and_which_fields',
                    )
                "
            />
            <CreateButton
                v-if="can('object-types.create')"
                :href="ObjectTypesController.create.url()"
                :label="t('i18n.pages.object_types.index.create_object_type')"
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Objekttypen löschen"
            @delete="bulkDeleteObjectTypes"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('object-types.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                empty-title="Noch keine Objekttypen"
                empty-description="Legen Sie den ersten Objekttyp an, um Datensätze zu erfassen."
                create-label="Objekttyp anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    preference-key="object-types"
                    class="h-full w-full"
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
