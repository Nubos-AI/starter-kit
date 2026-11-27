import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { duplicate as duplicateAction } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { recordToast } from '@/domains/business-data/data';
import type { RecordPayload } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { readErrorReason } from '@/shared/lib/errorResponse';

const DUPLICATE_ERROR_MESSAGE = 'i18n.composables.use_record_duplicate.duplicate_error';

export interface UseRecordDuplicateReturn {
  duplicating: Ref<boolean>;
  duplicate: (record: RecordPayload) => Promise<void>;
}

export function useRecordDuplicate(onDuplicated: (record: RecordPayload) => void): UseRecordDuplicateReturn {
  const { t } = useI18n();
  const duplicating = ref<boolean>(false);

  const duplicate = async (record: RecordPayload): Promise<void> => {
    if (duplicating.value) {
      return;
    }

    duplicating.value = true;

    try {
      const response = await fetch(duplicateAction.url({ record: record.id }), {
        method: 'POST',
        credentials: 'same-origin',
        headers: buildHeaders({ hasBody: true }),
        body: '{}',
      });

      if (response.status !== 201) {
        toast.error(await readErrorReason(response, t(DUPLICATE_ERROR_MESSAGE)));

        return;
      }

      const body = (await response.json()) as { data: RecordPayload };

      recordToast('create', t);
      onDuplicated(body.data);
    } catch {
      toast.error(t(DUPLICATE_ERROR_MESSAGE));
    } finally {
      duplicating.value = false;
    }
  };

  return { duplicating, duplicate };
}
