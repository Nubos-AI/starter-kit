<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, watch } from 'vue';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
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
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useRowDeletion } from '@/composables/useRowDeletion';
import { SEGMENT_ORIGIN } from '@/lib/statusMaps';

const { t } = useI18n();

interface SegmentRow {
    id: string;
    name: string;
    object_type: string | null;
    is_owner: boolean;
    can_update: boolean;
    can_delete: boolean;
}

const props = withDefaults(
    defineProps<{
        segments?: SegmentRow[];
    }>(),
    { segments: () => [] },
);

const deniedReason = t('i18n.pages.segments.index.no_permission');

const segments = computed<SegmentRow[]>(() => props.segments ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    segments.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(segments, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } =
    useRowDeletion<SegmentRow>({
        url: (row) =>
            SegmentManagementController.destroy.url({ segment: row.id }),
    });

const deleteDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.segments.index.the_segment_will_be_deleted_and_will_no_longer',
              { value1: pendingDelete.value.name },
          ),
);

function bulkDeleteSegments(): void {
    router.post(
        SegmentManagementController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function goToCreate(): void {
    router.visit(SegmentManagementController.create.url());
}

function goToEdit(row: SegmentRow): void {
    router.visit(SegmentManagementController.edit.url({ segment: row.id }));
}

const columnDefs = computed<ColDef<SegmentRow>[]>(() => [
    selectionColumn<SegmentRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.segments.index.name'),
        flex: 2,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: SegmentRow): string | undefined =>
                row.can_update
                    ? SegmentManagementController.edit.url({ segment: row.id })
                    : undefined,
        },
    },
    {
        colId: 'object_type',
        field: 'object_type',
        headerName: t('i18n.pages.segments.index.object_type'),
        flex: 1,
        minWidth: 160,
    },
    statusBadgeColumn<SegmentRow>('origin', SEGMENT_ORIGIN, {
        headerName: t('i18n.pages.segments.index.origin'),
        flex: 1,
        minWidth: 140,
        valueGetter: (params: ValueGetterParams<SegmentRow>): string =>
            params.data?.is_owner === false ? 'shared' : 'owner',
    }),
    actionsColumn<SegmentRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.segments.index.edit'),
            variant: 'edit',
            testId: 'segment-edit',
            href: (row) =>
                SegmentManagementController.edit.url({ segment: row.id }),
            isDisabled: (row) => !row.can_update,
            disabledReason: () => deniedReason,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.segments.index.delete'),
            variant: 'destructive',
            testId: 'segment-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: () => deniedReason,
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.segments.index.segments')"
        :description="
            t(
                'i18n.pages.segments.index.create_segments_rename_them_or_edit_their_filters',
            )
        "
    >
        <template #actions>
            <CreateButton
                :href="SegmentManagementController.create.url()"
                :label="t('i18n.pages.segments.index.create_segment')"
            />
        </template>

        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.segments.index.delete_segments')"
            @delete="bulkDeleteSegments"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                :empty-title="t('i18n.pages.segments.index.no_segments_yet')"
                :empty-description="
                    t(
                        'i18n.pages.segments.index.create_your_first_segment_to_see_filtered_records',
                    )
                "
                :create-label="t('i18n.pages.segments.index.create_segment')"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="segments"
                    :is-row-activatable="(row: SegmentRow) => row.can_update"
                    :aria-label="t('i18n.pages.segments.index.segments')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.segments.index.delete_segment')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.segments.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
