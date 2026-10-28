import type { ComputedRef, Ref } from 'vue';
import { computed, onScopeDispose, ref } from 'vue';
import { toast } from 'vue-sonner';
import { show } from '@/actions/App/Http/Controllers/Engine/BulkActionsController';
import { usePollingLoop } from '@/composables/usePollingLoop';
import type { BulkAction } from '@/composables/useRecordSelection';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { recordToast } from '@/lib/recordToast';

const PROGRESS_ERROR_MESSAGE =
    'Der Fortschritt der Massenaktion konnte nicht abgerufen werden.';

export type BatchStatus =
    | 'idle'
    | 'polling'
    | 'finished'
    | 'failed'
    | 'cancelled';

export interface BatchPartialError {
    recordId: string;
    message: string;
}

interface BatchStatusResponse {
    batchId: string;
    progress?: number;
    processedJobs?: number;
    totalJobs?: number;
    failedJobs?: number;
    finished?: boolean;
    cancelled?: boolean;
    partialErrors?: BatchPartialError[];
    downloadUrl?: string | null;
}

export interface UseBatchProgressOptions {
    onSettled?: () => void;
}

export interface UseBatchProgressReturn {
    start: (batchId: string, action?: BulkAction) => void;
    status: Ref<BatchStatus>;
    progress: Ref<number>;
    processedJobs: Ref<number>;
    totalJobs: Ref<number>;
    failedJobs: Ref<number>;
    partialErrors: Ref<BatchPartialError[]>;
    downloadUrl: Ref<string | null>;
    active: ComputedRef<boolean>;
}

export function useBatchProgress(
    options: UseBatchProgressOptions = {},
): UseBatchProgressReturn {
    const status = ref<BatchStatus>('idle');
    const progress = ref<number>(0);
    const processedJobs = ref<number>(0);
    const totalJobs = ref<number>(0);
    const failedJobs = ref<number>(0);
    const partialErrors = ref<BatchPartialError[]>([]);
    const downloadUrl = ref<string | null>(null);
    const active = computed<boolean>(() => status.value === 'polling');

    const loop = usePollingLoop();

    let currentBatchId: string | null = null;
    let currentAction: BulkAction | null = null;

    const applyState = (data: BatchStatusResponse): void => {
        progress.value = data.progress ?? 0;
        processedJobs.value = data.processedJobs ?? 0;
        totalJobs.value = data.totalJobs ?? 0;
        failedJobs.value = data.failedJobs ?? 0;
        partialErrors.value = Array.isArray(data.partialErrors)
            ? data.partialErrors
            : [];
        downloadUrl.value = data.downloadUrl ?? null;
    };

    const finish = (data: BatchStatusResponse): void => {
        loop.clear();

        if (data.cancelled === true) {
            status.value = 'cancelled';
            toast.info('Massenaktion abgebrochen.');
            options.onSettled?.();

            return;
        }

        status.value = 'finished';

        if (failedJobs.value > 0) {
            toast.warning(
                `Massenaktion abgeschlossen — ${failedJobs.value} Aufgabe(n) fehlgeschlagen.`,
            );
        } else if (currentAction === 'soft-delete') {
            recordToast('delete', {
                count: Math.max(processedJobs.value - failedJobs.value, 1),
            });
        } else {
            toast.success('Massenaktion abgeschlossen.');
        }

        const url = downloadUrl.value;

        if (url !== null) {
            toast.success('Export bereit.', {
                action: {
                    label: 'CSV herunterladen',
                    onClick: (): void => {
                        window.open(url, '_blank', 'noopener');
                    },
                },
            });
        }

        options.onSettled?.();
    };

    const poll = async (batchId: string): Promise<void> => {
        if (batchId !== currentBatchId) {
            return;
        }

        try {
            const response = await loop.request(show.url({ batchId }), {
                method: 'GET',
                headers: buildHeaders(),
            });

            if (!response.ok) {
                throw new Error(
                    `Batch endpoint responded with status ${response.status}`,
                );
            }

            const data = (await response.json()) as BatchStatusResponse;

            if (batchId !== currentBatchId) {
                return;
            }

            loop.resetFailures();
            applyState(data);

            if (data.finished === true || data.cancelled === true) {
                finish(data);

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

    const start = (batchId: string, action?: BulkAction): void => {
        loop.clear();
        currentBatchId = batchId;
        currentAction = action ?? null;
        loop.resetFailures();
        status.value = 'polling';
        progress.value = 0;
        processedJobs.value = 0;
        totalJobs.value = 0;
        failedJobs.value = 0;
        partialErrors.value = [];
        downloadUrl.value = null;
        void poll(batchId);
    };

    onScopeDispose(() => {
        currentBatchId = null;
        loop.clear();
    });

    return {
        start,
        status,
        progress,
        processedJobs,
        totalJobs,
        failedJobs,
        partialErrors,
        downloadUrl,
        active,
    };
}
