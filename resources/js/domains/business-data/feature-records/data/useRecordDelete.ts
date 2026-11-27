import type { ComputedRef, Ref } from 'vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { destroy } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { recordToast } from '@/domains/business-data/data';
import type { RecordPayload } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { readErrorReason, readErrorResponse, toFieldErrors } from '@/shared/lib/errorResponse';

const DELETE_ERROR_MESSAGE = 'i18n.composables.use_record_delete.delete_error';

const REASON_REQUIRED_MESSAGE = 'i18n.composables.use_record_delete.reason_required';

export interface UseRecordDeleteReturn {
  pending: Ref<RecordPayload | null>;
  isOpen: ComputedRef<boolean>;
  deleting: Ref<boolean>;
  reason: Ref<string>;
  reasonError: Ref<string | null>;
  request: (record: RecordPayload) => void;
  cancel: () => void;
  confirm: () => Promise<void>;
}

export function useRecordDelete(onDeleted: (record: RecordPayload) => void): UseRecordDeleteReturn {
  const { t } = useI18n();
  const pending = ref<RecordPayload | null>(null);
  const deleting = ref<boolean>(false);
  const reason = ref<string>('');
  const reasonError = ref<string | null>(null);

  const isOpen = computed<boolean>(() => pending.value !== null);

  const request = (record: RecordPayload): void => {
    reason.value = '';
    reasonError.value = null;
    pending.value = record;
  };

  const cancel = (): void => {
    pending.value = null;
    reason.value = '';
    reasonError.value = null;
  };

  const confirm = async (): Promise<void> => {
    const record = pending.value;

    if (record === null || deleting.value) {
      return;
    }

    deleting.value = true;
    reasonError.value = null;

    try {
      const trimmed = reason.value.trim();

      const response = await fetch(destroy.url({ record: record.id }), {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: buildHeaders({ hasBody: true }),
        body: JSON.stringify({
          deletion_reason: trimmed === '' ? null : trimmed,
        }),
      });

      if (response.status === 422) {
        const failure = await readErrorResponse(response);

        reasonError.value =
          toFieldErrors(failure.errors).deletion_reason ?? failure.message ?? t(REASON_REQUIRED_MESSAGE);

        return;
      }

      if (!response.ok) {
        toast.error(await readErrorReason(response, t(DELETE_ERROR_MESSAGE)));

        return;
      }

      recordToast('delete', t);
      cancel();
      onDeleted(record);
    } catch {
      toast.error(t(DELETE_ERROR_MESSAGE));
    } finally {
      deleting.value = false;
    }
  };

  return {
    pending,
    isOpen,
    deleting,
    reason,
    reasonError,
    request,
    cancel,
    confirm,
  };
}
