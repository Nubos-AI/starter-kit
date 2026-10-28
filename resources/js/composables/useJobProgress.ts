import type { ComputedRef, Ref } from 'vue';
import { computed, onScopeDispose, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    errorReport as errorReportAction,
    execute as executeAction,
    history as historyAction,
    status as statusAction,
} from '@/actions/App/Http/Controllers/Import/ImportExecutionsController';
import { usePollingLoop } from '@/composables/usePollingLoop';
import { buildHeaders } from '@/composables/useRequestHeaders';

const START_ERROR_MESSAGE =
    'Der Import konnte nicht gestartet werden. Bitte versuchen Sie es erneut.';

const PROGRESS_ERROR_MESSAGE =
    'Der Fortschritt des Imports konnte nicht abgerufen werden.';

export type JobStatus =
    | 'idle'
    | 'polling'
    | 'finished'
    | 'failed'
    | 'cancelled';

export interface JobExecutePayload {
    path: string;
    format: string;
    sheet: string | null;
    mapping: unknown;
    duplicate_mode: string;
    missing_option_mode?: string;
}

export interface JobProgressContext {
    objectType: string;
}

interface ExecuteResponse {
    batchId: string;
    importJobId: string;
}

interface JobStatusResponse {
    batchId: string;
    progress?: number;
    processedJobs?: number;
    totalJobs?: number;
    failedJobs?: number;
    finished?: boolean;
    cancelled?: boolean;
}

interface ImportHistoryRow {
    id: string;
    status?: string;
    created_count?: number;
    updated_count?: number;
    error_count?: number;
    error_report_path?: string | null;
}

export interface UseJobProgressReturn {
    start: (payload: JobExecutePayload, context: JobProgressContext) => void;
    stop: () => void;
    status: Ref<JobStatus>;
    progress: Ref<number>;
    processedJobs: Ref<number>;
    totalJobs: Ref<number>;
    failedJobs: Ref<number>;
    createdCount: Ref<number>;
    updatedCount: Ref<number>;
    errorCount: Ref<number>;
    errorReportUrl: Ref<string | null>;
    active: ComputedRef<boolean>;
}

export function useJobProgress(): UseJobProgressReturn {
    const status = ref<JobStatus>('idle');
    const progress = ref<number>(0);
    const processedJobs = ref<number>(0);
    const totalJobs = ref<number>(0);
    const failedJobs = ref<number>(0);
    const createdCount = ref<number>(0);
    const updatedCount = ref<number>(0);
    const errorCount = ref<number>(0);
    const errorReportUrl = ref<string | null>(null);
    const active = computed<boolean>(() => status.value === 'polling');

    const loop = usePollingLoop();

    let currentBatchId: string | null = null;
    let currentImportJobId: string | null = null;
    let currentObjectType: string | null = null;

    const hydrateFromHistory = async (
        objectType: string,
        importJobId: string,
    ): Promise<void> => {
        const response = await loop.request(historyAction.url({ objectType }), {
            method: 'GET',
            headers: buildHeaders(),
        });

        if (!response.ok) {
            throw new Error(
                `Import history endpoint responded with status ${response.status}`,
            );
        }

        const payload = (await response.json()) as {
            data?: ImportHistoryRow[];
        };
        const row = (payload.data ?? []).find(
            (entry) => entry.id === importJobId,
        );

        if (row === undefined) {
            return;
        }

        createdCount.value = row.created_count ?? 0;
        updatedCount.value = row.updated_count ?? 0;
        errorCount.value = row.error_count ?? 0;

        const hasReport =
            (row.error_report_path !== null &&
                row.error_report_path !== undefined) ||
            errorCount.value > 0;

        errorReportUrl.value = hasReport
            ? errorReportAction.url({ objectType, importJob: importJobId })
            : null;
    };

    const finish = async (data: JobStatusResponse): Promise<void> => {
        loop.clear();

        if (data.cancelled === true) {
            status.value = 'cancelled';
            toast.info('Import abgebrochen.');

            return;
        }

        status.value = 'finished';

        if (currentObjectType !== null && currentImportJobId !== null) {
            try {
                await hydrateFromHistory(currentObjectType, currentImportJobId);
            } catch {
                toast.warning(
                    'Die Fehlerliste des Imports konnte nicht geladen werden.',
                );
            }
        }

        if (errorCount.value > 0 || failedJobs.value > 0) {
            toast.warning(
                `Import abgeschlossen — ${errorCount.value} Zeile(n) fehlerhaft.`,
            );
        } else {
            toast.success('Import erfolgreich abgeschlossen.');
        }
    };

    const poll = async (batchId: string): Promise<void> => {
        if (batchId !== currentBatchId || currentObjectType === null) {
            return;
        }

        try {
            const response = await loop.request(
                statusAction.url({
                    objectType: currentObjectType,
                    batch: batchId,
                }),
                { method: 'GET', headers: buildHeaders() },
            );

            if (!response.ok) {
                throw new Error(
                    `Import status endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as JobStatusResponse;

            if (batchId !== currentBatchId) {
                return;
            }

            loop.resetFailures();
            progress.value = data.progress ?? 0;
            processedJobs.value = data.processedJobs ?? 0;
            totalJobs.value = data.totalJobs ?? 0;
            failedJobs.value = data.failedJobs ?? 0;

            if (data.finished === true || data.cancelled === true) {
                await finish(data);

                return;
            }

            loop.schedule(() => void poll(batchId));
        } catch {
            if (batchId !== currentBatchId) {
                return;
            }

            if (loop.registerFailure()) {
                status.value = 'failed';
                toast.error(PROGRESS_ERROR_MESSAGE);

                return;
            }

            loop.schedule(() => void poll(batchId));
        }
    };

    const run = async (
        payload: JobExecutePayload,
        objectType: string,
    ): Promise<void> => {
        try {
            const response = await loop.request(
                executeAction.url({ objectType }),
                {
                    method: 'POST',
                    headers: buildHeaders({ hasBody: true }),
                    body: JSON.stringify(payload),
                },
            );

            if (!response.ok) {
                throw new Error(
                    `Import execute endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as ExecuteResponse;

            currentBatchId = data.batchId;
            currentImportJobId = data.importJobId;

            void poll(data.batchId);
        } catch {
            loop.clear();
            currentBatchId = null;
            currentImportJobId = null;
            status.value = 'failed';
            toast.error(START_ERROR_MESSAGE);
        }
    };

    const start = (
        payload: JobExecutePayload,
        context: JobProgressContext,
    ): void => {
        loop.clear();
        currentBatchId = null;
        currentImportJobId = null;
        currentObjectType = context.objectType;
        loop.resetFailures();
        status.value = 'polling';
        progress.value = 0;
        processedJobs.value = 0;
        totalJobs.value = 0;
        failedJobs.value = 0;
        createdCount.value = 0;
        updatedCount.value = 0;
        errorCount.value = 0;
        errorReportUrl.value = null;

        void run(payload, context.objectType);
    };

    const stop = (): void => {
        loop.clear();
        currentBatchId = null;
        currentImportJobId = null;
    };

    onScopeDispose(() => {
        currentBatchId = null;
        currentImportJobId = null;
        currentObjectType = null;
        loop.clear();
    });

    return {
        start,
        stop,
        status,
        progress,
        processedJobs,
        totalJobs,
        failedJobs,
        createdCount,
        updatedCount,
        errorCount,
        errorReportUrl,
        active,
    };
}
