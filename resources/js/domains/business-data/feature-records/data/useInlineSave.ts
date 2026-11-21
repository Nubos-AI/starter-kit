import type { CellValueChangedEvent, IRowNode } from 'ag-grid-community';
import type { Ref } from 'vue';
import { nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import RecordWriteController from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { extractRecord } from '@/domains/business-data/data';
import { recordToast } from '@/domains/business-data/data';
import type { RecordPayload } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import { buildHeaders } from '@/shared/composables/useRequestHeaders';
import { readErrorResponse, toFieldErrors } from '@/shared/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const SAVE_SUCCESS_MESSAGE = 'i18n.composables.use_inline_save.save_success';

const SAVE_CONFLICT_MESSAGE = 'i18n.composables.use_inline_save.save_conflict';

const SAVE_ERROR_MESSAGE = 'i18n.composables.use_inline_save.save_error';

export interface UseInlineSaveReturn {
  onCellValueChanged: (event: CellValueChangedEvent<RecordPayload>) => void;
  conflictOpen: Ref<boolean>;
  closeConflict: () => void;
  announcement: Ref<string>;
}

function revertCell(node: IRowNode<RecordPayload>, field: string, oldValue: unknown): void {
  const current = node.data;

  if (current === undefined || current === null) {
    return;
  }

  node.setData({
    ...current,
    data: { ...current.data, [field]: oldValue },
  });
}

export function useInlineSave(): UseInlineSaveReturn {
  const { t } = useI18n();
  const conflictOpen = ref<boolean>(false);
  const announcement = ref<string>('');
  const queues = new Map<string, Promise<void>>();

  const closeConflict = (): void => {
    conflictOpen.value = false;
  };

  const announce = async (message: string): Promise<void> => {
    announcement.value = '';
    await nextTick();
    announcement.value = message;
  };

  const onCellValueChanged = (event: CellValueChangedEvent<RecordPayload>): void => {
    const node = event.node;
    const recordId = node.data?.id;

    if (recordId === undefined || recordId === null) {
      return;
    }

    const field = event.column.getColId();
    const newValue = event.newValue;
    const oldValue = event.oldValue;

    const runSave = async (): Promise<void> => {
      const version = node.data?.version;
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

      try {
        const response = await fetch(RecordWriteController.updateCell.url({ record: recordId }), {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: buildHeaders({ hasBody: true }),
          body: JSON.stringify({
            field,
            value: newValue,
            version,
          }),
          signal: controller.signal,
        });

        if (response.status === 200) {
          node.setData(extractRecord(await response.json()));
          recordToast('edit', t);
          void announce(t(SAVE_SUCCESS_MESSAGE));

          return;
        }

        if (response.status === 409) {
          node.setData(extractRecord(await response.json()));
          conflictOpen.value = true;
          void announce(t(SAVE_CONFLICT_MESSAGE));

          return;
        }

        const failure = await readErrorResponse(response);
        const reason = toFieldErrors(failure.errors)[field] ?? failure.message ?? t(SAVE_ERROR_MESSAGE);

        revertCell(node, field, oldValue);
        toast.error(reason);
        void announce(reason);
      } catch {
        revertCell(node, field, oldValue);
        toast.error(t(SAVE_ERROR_MESSAGE));
        void announce(t(SAVE_ERROR_MESSAGE));
      } finally {
        clearTimeout(timeout);
      }
    };

    const previous = queues.get(recordId) ?? Promise.resolve();
    const next = previous.then(runSave);
    queues.set(recordId, next);

    void next.finally(() => {
      if (queues.get(recordId) === next) {
        queues.delete(recordId);
      }
    });
  };

  return {
    onCellValueChanged,
    conflictOpen,
    closeConflict,
    announcement,
  };
}
