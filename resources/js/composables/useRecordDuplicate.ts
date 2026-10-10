import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { duplicate as duplicateAction } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import { recordToast } from '@/lib/recordToast';
import type { RecordPayload } from '@/types/records';

const DUPLICATE_ERROR_MESSAGE = 'Der Datensatz konnte nicht dupliziert werden.';

export interface UseRecordDuplicateReturn {
    duplicating: Ref<boolean>;
    duplicate: (record: RecordPayload) => Promise<void>;
}

export function useRecordDuplicate(
    onDuplicated: (record: RecordPayload) => void,
): UseRecordDuplicateReturn {
    const duplicating = ref<boolean>(false);

    const duplicate = async (record: RecordPayload): Promise<void> => {
        if (duplicating.value) {
            return;
        }

        duplicating.value = true;

        try {
            const response = await fetch(
                duplicateAction.url({ record: record.id }),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: true }),
                    body: '{}',
                },
            );

            if (response.status !== 201) {
                toast.error(
                    await readErrorReason(response, DUPLICATE_ERROR_MESSAGE),
                );

                return;
            }

            const body = (await response.json()) as { data: RecordPayload };

            recordToast('create');
            onDuplicated(body.data);
        } catch {
            toast.error(DUPLICATE_ERROR_MESSAGE);
        } finally {
            duplicating.value = false;
        }
    };

    return { duplicating, duplicate };
}
