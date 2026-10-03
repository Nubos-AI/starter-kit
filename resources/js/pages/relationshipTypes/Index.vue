<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueFormatterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import RelationshipTypesController from '@/actions/App/Http/Controllers/Engine/RelationshipTypesController';
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
import { relationCardinalityLabel } from '@/lib/systemValueLabels';
import type { RelationshipTypeRow } from '@/types/objectTypes';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        relationshipTypes?: RelationshipTypeRow[];
    }>(),
    { relationshipTypes: () => [] },
);

const { can } = usePermissions();

const deniedReason = t('i18n.pages.relationship_types.index.no_permission');

const relationshipTypes = computed<RelationshipTypeRow[]>(
    () => props.relationshipTypes ?? [],
);

const listState = computed<'empty' | 'ready'>(() =>
    relationshipTypes.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(relationshipTypes, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const pendingDelete = ref<RelationshipTypeRow | null>(null);
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
              'i18n.pages.relationship_types.index.the_relationship_type_will_be_permanently_deleted_existing_links',
              { value1: pendingDelete.value.name },
          ),
);

function bulkDeleteRelationshipTypes(): void {
    router.post(
        RelationshipTypesController.bulkDestroy.url(),
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
        RelationshipTypesController.destroy.url({ relationshipType: row.id }),
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
    router.visit(RelationshipTypesController.create.url());
}

function goToEdit(row: RelationshipTypeRow): void {
    router.visit(
        RelationshipTypesController.edit.url({ relationshipType: row.id }),
    );
}

const columnDefs = computed<ColDef<RelationshipTypeRow>[]>(() => [
    selectionColumn<RelationshipTypeRow>(selection, (row) => row.can_delete),
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
        valueFormatter: (
            params: ValueFormatterParams<RelationshipTypeRow>,
        ): string => relationCardinalityLabel(String(params.value ?? '')),
    },
    actionsColumn<RelationshipTypeRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.relationship_types.index.edit'),
            variant: 'edit',
            testId: 'relationship-type-edit',
            href: (row) =>
                RelationshipTypesController.edit.url({
                    relationshipType: row.id,
                }),
            isDisabled: (row) => !row.can_update,
            disabledReason: () => deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.relationship_types.index.delete'),
            variant: 'destructive',
            testId: 'relationship-type-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: () => deniedReason,
        },
    ]),
]);
</script>

<template>
    <ListPage>
        <Head
            :title="t('i18n.pages.relationship_types.index.relationship_types')"
        />

        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="
                    t('i18n.pages.relationship_types.index.relationship_types')
                "
                :description="
                    t(
                        'i18n.pages.relationship_types.index.relationship_types_define_how_records_of_one_object_type',
                    )
                "
            />
            <CreateButton
                v-if="can('object-types.create')"
                :href="RelationshipTypesController.create.url()"
                :label="
                    t(
                        'i18n.pages.relationship_types.index.create_relationship_type',
                    )
                "
            />
        </div>

        <SelectionBulkBar
            :count="selection.count.value"
            delete-label="Beziehungstypen löschen"
            @delete="bulkDeleteRelationshipTypes"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                :empty-kind="
                    can('object-types.create') ? 'no-records' : 'empty-column'
                "
                skeleton-variant="list"
                empty-title="Noch keine Beziehungstypen"
                empty-description="Legen Sie den ersten Beziehungstyp an, um Datensätze zu verknüpfen."
                create-label="Beziehungstyp anlegen"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="relationshipTypes"
                    :is-row-activatable="
                        (row: RelationshipTypeRow) => row.can_update
                    "
                    :aria-label="
                        t(
                            'i18n.pages.relationship_types.index.relationship_types',
                        )
                    "
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="
                t(
                    'i18n.pages.relationship_types.index.delete_relationship_type',
                )
            "
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.relationship_types.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
