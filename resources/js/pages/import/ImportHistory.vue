<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import type { ColDef } from 'ag-grid-community';
import { computed } from 'vue';
import { errorReport } from '@/actions/App/Http/Controllers/Import/ImportExecutionsController';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { statusBadgeColumn } from '@/components/data-grid/StatusBadgeCellRenderer';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Skeleton } from '@/components/ui/skeleton';
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
        data?: ImportJobRow[];
        loading?: boolean;
        error?: string | null;
    }>(),
    { jobs: () => [], data: () => [], loading: false, error: null },
);

usePageBreadcrumbs(() => [
    { title: t('i18n.pages.import.import_history.import_history') },
]);

const rows = computed<ImportJobRow[]>(() =>
    props.data.length > 0 ? props.data : props.jobs,
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
    <Head :title="t('i18n.pages.import.import_history.import_history_2')" />

    <div class="flex h-full flex-1 flex-col gap-6 p-3">
        <h1 class="text-lg font-semibold">
            {{ t('i18n.pages.import.import_history.import_history_2') }}
        </h1>

        <div
            v-if="props.loading"
            class="flex flex-col gap-2"
            role="status"
            aria-live="polite"
            :aria-busy="true"
        >
            <span class="sr-only">{{
                t('i18n.pages.import.import_history.loading_history')
            }}</span>
            <Skeleton class="h-10 w-full" />
            <Skeleton class="h-10 w-full" />
            <Skeleton class="h-10 w-full" />
        </div>

        <Alert v-else-if="props.error" variant="destructive" role="alert">
            <AlertTitle>{{
                t('i18n.pages.import.import_history.history_unavailable')
            }}</AlertTitle>
            <AlertDescription>{{ props.error }}</AlertDescription>
        </Alert>

        <p
            v-else-if="rows.length === 0"
            class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            {{
                t(
                    'i18n.pages.import.import_history.no_imports_have_been_run_yet',
                )
            }}
        </p>

        <DataGrid
            v-else
            :column-defs="columnDefs"
            :row-data="rows"
            dom-layout="autoHeight"
            :aria-label="t('i18n.pages.import.import_history.import_history_2')"
        />
    </div>
</template>
