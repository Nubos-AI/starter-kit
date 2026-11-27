import type { ComputedRef, Ref } from 'vue';
import { computed, onScopeDispose, ref } from 'vue';
import { toast } from 'vue-sonner';
import { show } from '@/actions/App/Http/Controllers/Engine/BulkActionsController';
import { recordToast } from '@/domains/business-data/data';
import { useI18n } from '@/shared/composables/useI18n';
import { usePollingLoop } from '@/shared/composables/usePollingLoop';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import type { BulkAction } from '@/shared/records/composables/useRecordSelection';

const PROGRESS_ERROR_MESSAGE = 'i18n.composables.use_batch_progress.progress_error';

export type BatchStatus = 'idle' | 'polling' | 'finished' | 'failed' | 'cancelled';

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

export function useBatchProgress(options: UseBatchProgressOptions = {}): UseBatchProgressReturn {
  const { t } = useI18n();
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
    partialErrors.value = Array.isArray(data.partialErrors) ? data.partialErrors : [];
    downloadUrl.value = data.downloadUrl ?? null;
  };

  const finish = (data: BatchStatusResponse): void => {
    loop.clear();

    if (data.cancelled === true) {
      status.value = 'cancelled';
      toast.info(t('i18n.composables.use_batch_progress.cancelled'));
      options.onSettled?.();

      return;
    }

    status.value = 'finished';

    if (failedJobs.value > 0) {
      toast.warning(
        t('i18n.composables.use_batch_progress.finished_with_failures', {
          count: failedJobs.value,
        }),
      );
    } else if (currentAction === 'soft-delete') {
      recordToast('delete', t, {
        count: Math.max(processedJobs.value - failedJobs.value, 1),
      });
    } else {
      toast.success(t('i18n.composables.use_batch_progress.finished'));
    }

    const url = downloadUrl.value;

    if (url !== null) {
      toast.success(t('i18n.composables.use_batch_progress.export_ready'), {
        action: {
          label: t('i18n.composables.use_batch_progress.download_csv'),
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
        throw new Error(`Batch endpoint responded with status ${response.status}`);
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
        toast.error(t(PROGRESS_ERROR_MESSAGE));

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
