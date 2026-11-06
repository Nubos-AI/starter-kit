<script setup lang="ts">
import { Download } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed } from 'vue';
import { errorReport } from '@/actions/App/Http/Controllers/Import/ImportExecutionsController';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ListPage from '@/components/data-grid/ListPage.vue';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import ViewStates from '@/components/engine/ViewStates.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { formatDateTime } from '@/lib/formatDate';
import { IMPORT_JOB_STATUS } from '@/lib/statusMaps';

const { t } = useI18n();

type ImportJobStatus = 'pending' | 'running' | 'completed' | 'failed';

interface ImportJobRow {
    id: string;
    status: ImportJobStatus;
    original_filename: string;
    total_rows: number;
    created_count: number;
    updated_count: number;
    error_count: number;
    error_report_path: string | null;
    created_at: string | null;
}

const props = withDefaults(
    defineProps<{
        objectType: string;
        jobs?: ImportJobRow[];
    }>(),
    { jobs: () => [] },
);

const PAGE_TITLE = t('i18n.pages.import.import_history.import_history_2');

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.import.import_history.import_history') },
]);

const listState = computed<'empty' | 'ready'>(() =>
    props.jobs.length === 0 ? 'empty' : 'ready',
);

const columnDefs = computed<ColDef<ImportJobRow>[]>(() => [
    {
        colId: 'created_at',
        field: 'created_at',
        headerName: t('i18n.pages.import.import_history.date'),
        flex: 1,
        valueFormatter: (params) => formatDateTime(params.value ?? null),
    },
    {
        colId: 'original_filename',
        field: 'original_filename',
        headerName: t('i18n.pages.import.import_history.file'),
        flex: 2,
        type: 'emphasis',
    },
    {
        colId: 'total_rows',
        field: 'total_rows',
        headerName: t('i18n.pages.import.import_history.rows'),
        width: 120,
    },
    statusBadgeColumn<ImportJobRow>('status', IMPORT_JOB_STATUS, {
        headerName: t('i18n.pages.import.import_history.status'),
        width: 160,
    }),
    actionsColumn<ImportJobRow>([
        {
            icon: Download,
            label: t('i18n.pages.import.import_history.download_error_report'),
            href: (row) =>
                errorReport.url({
                    objectType: props.objectType,
                    importJob: row.id,
                }),
            download: true,
            isVisible: (row) => row.error_report_path !== null,
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="PAGE_TITLE"
        :description="
            t(
                'i18n.pages.import.import_history.every_import_you_started_for_this_object_type',
            )
        "
    >
        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                :empty-title="
                    t('i18n.pages.import.import_history.no_imports_yet')
                "
                :empty-description="
                    t(
                        'i18n.pages.import.import_history.no_imports_have_been_run_yet',
                    )
                "
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    class="h-full"
                    :column-defs="columnDefs"
                    :row-data="props.jobs"
                    :aria-label="PAGE_TITLE"
                />
            </div>
        </div>
    </ListPage>
</template>
