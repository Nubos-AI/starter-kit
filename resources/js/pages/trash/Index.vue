<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Eye, RotateCcw, Trash2 } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed, watch } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import TrashController from '@/actions/App/Http/Controllers/Engine/TrashController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import ListPager from '@/components/data-grid/ListPager.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useRowDeletion } from '@/composables/useRowDeletion';
import type { TrashPagination, TrashRow } from '@/types/trash';

const { t } = useI18n();

const DENIED_REASON = t(
    'i18n.pages.trash.index.you_do_not_have_permission_to_access_this_record',
);

const props = withDefaults(
    defineProps<{
        records?: TrashRow[];
        pagination?: TrashPagination;
    }>(),
    {
        records: () => [],
        pagination: () => ({
            currentPage: 1,
            lastPage: 1,
            perPage: 25,
            total: 0,
        }),
    },
);

const records = computed<TrashRow[]>(() => props.records ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    records.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(records, (rows) =>
    selection.prune(rows.filter((row) => row.canDelete).map((row) => row.id)),
);

const {
    pendingDelete: pendingPurge,
    deletePending: purgePending,
    deleteDialogOpen: purgeDialogOpen,
    confirmDelete: confirmPurge,
} = useRowDeletion<TrashRow>({
    url: (row) => TrashController.destroy.url({ id: row.id }),
});

const purgeDescription = computed<string>(() =>
    pendingPurge.value === null
        ? ''
        : t(
              'i18n.pages.trash.index.will_be_permanently_deleted_and_cannot_be_restored_afterwards',
              { value1: pendingPurge.value.title },
          ),
);

function showHref(row: TrashRow): string {
    return RecordsController.show.url({ record: row.recordNumber ?? row.id });
}

function openRecord(row: TrashRow): void {
    router.visit(showHref(row));
}

function restore(row: TrashRow): void {
    router.put(
        TrashController.restore.url({ id: row.id }),
        {},
        { preserveScroll: true },
    );
}

function visitPage(page: number): void {
    router.get(
        TrashController.index.url(undefined, { query: { page } }),
        {},
        { preserveScroll: true },
    );
}

function bulkPurge(): void {
    router.post(
        TrashController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

const columnDefs = computed<ColDef<TrashRow>[]>(() => [
    selectionColumn<TrashRow>(selection, (row) => row.canDelete),
    {
        colId: 'objectTypeName',
        field: 'objectTypeName',
        headerName: t('i18n.pages.trash.index.object_type'),
        flex: 1,
        minWidth: 140,
    },
    {
        colId: 'recordNumber',
        field: 'recordNumber',
        headerName: t('i18n.pages.trash.index.business_key'),
        flex: 1,
        minWidth: 140,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: TrashRow): string => showHref(row),
        },
    },
    {
        colId: 'title',
        field: 'title',
        headerName: t('i18n.pages.trash.index.name'),
        flex: 2,
        minWidth: 200,
    },
    {
        colId: 'deletionReason',
        field: 'deletionReason',
        headerName: t('i18n.pages.trash.index.reason_for_deletion'),
        flex: 2,
        minWidth: 200,
    },
    actionsColumn<TrashRow>([
        {
            icon: Eye,
            label: t('i18n.pages.trash.index.view'),
            variant: 'info',
            testId: 'trash-view',
            href: (row) => showHref(row),
        },
        {
            icon: RotateCcw,
            label: t('i18n.pages.trash.index.restore'),
            variant: 'success',
            testId: 'trash-restore',
            onClick: (row) => restore(row),
            isDisabled: (row) => !row.canRestore,
            disabledReason: () => DENIED_REASON,
        },
        {
            icon: Trash2,
            label: t('i18n.pages.trash.index.delete_permanently'),
            variant: 'destructive',
            testId: 'trash-purge',
            onClick: (row) => (pendingPurge.value = row),
            isDisabled: (row) => !row.canDelete,
            disabledReason: () => DENIED_REASON,
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.trash.index.trash')"
        :description="
            t(
                'i18n.pages.trash.index.restore_deleted_records_or_remove_them_permanently',
            )
        "
    >
        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.trash.index.delete_permanently')"
            @delete="bulkPurge"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                empty-kind="empty-column"
                skeleton-variant="list"
                :empty-title="t('i18n.pages.trash.index.the_trash_is_empty')"
                :empty-description="
                    t(
                        'i18n.pages.trash.index.deleted_records_appear_here_until_they_are_removed',
                    )
                "
            />

            <div v-if="listState === 'ready'" class="min-h-0 w-full flex-1">
                <DataGrid
                    preference-key="trash"
                    class="h-full w-full"
                    :column-defs="columnDefs"
                    :row-data="records"
                    :is-row-activatable="() => true"
                    :aria-label="t('i18n.pages.trash.index.trash')"
                    @row-activate="openRecord"
                />
            </div>

            <ListPager
                v-if="listState === 'ready'"
                class="pt-3"
                :current-page="props.pagination.currentPage"
                :last-page="props.pagination.lastPage"
                @change-page="visitPage"
            />
        </div>

        <ConfirmDialog
            v-model:open="purgeDialogOpen"
            :title="t('i18n.pages.trash.index.permanently_delete_record')"
            :description="purgeDescription"
            :confirm-label="t('i18n.pages.trash.index.delete_permanently')"
            variant="destructive"
            :pending="purgePending"
            @confirm="confirmPurge"
        />
    </ListPage>
</template>
