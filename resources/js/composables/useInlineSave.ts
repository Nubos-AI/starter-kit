import type { CellValueChangedEvent, IRowNode } from 'ag-grid-community';
import type { Ref } from 'vue';
import { nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import RecordWriteController from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorResponse, toFieldErrors } from '@/lib/errorResponse';
import { extractRecord } from '@/lib/recordPayload';
import { recordToast } from '@/lib/recordToast';
import type { RecordPayload } from '@/types/records';

const REQUEST_TIMEOUT_MS = 15000;

const SAVE_SUCCESS_MESSAGE = 'Änderung gespeichert.';

const SAVE_CONFLICT_MESSAGE =
    'Der Wert wurde zwischenzeitlich geändert und wurde zurückgesetzt.';

const SAVE_ERROR_MESSAGE =
    'Die Änderung konnte nicht gespeichert werden und wurde zurückgesetzt.';

export interface UseInlineSaveReturn {
    onCellValueChanged: (event: CellValueChangedEvent<RecordPayload>) => void;
    conflictOpen: Ref<boolean>;
    closeConflict: () => void;
    announcement: Ref<string>;
}

function revertCell(
    node: IRowNode<RecordPayload>,
    field: string,
    oldValue: unknown,
): void {
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

    const onCellValueChanged = (
        event: CellValueChangedEvent<RecordPayload>,
    ): void => {
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
            const timeout = setTimeout(
                () => controller.abort(),
                REQUEST_TIMEOUT_MS,
            );

            try {
                const response = await fetch(
                    RecordWriteController.updateCell.url({ record: recordId }),
                    {
                        method: 'PATCH',
                        credentials: 'same-origin',
                        headers: buildHeaders({ hasBody: true }),
                        body: JSON.stringify({
                            field,
                            value: newValue,
                            version,
                        }),
                        signal: controller.signal,
                    },
                );

                if (response.status === 200) {
                    node.setData(extractRecord(await response.json()));
                    recordToast('edit');
                    void announce(SAVE_SUCCESS_MESSAGE);

                    return;
                }

                if (response.status === 409) {
                    node.setData(extractRecord(await response.json()));
                    conflictOpen.value = true;
                    void announce(SAVE_CONFLICT_MESSAGE);

                    return;
                }

                const failure = await readErrorResponse(response);
                const reason =
                    toFieldErrors(failure.errors)[field] ??
                    failure.message ??
                    SAVE_ERROR_MESSAGE;

                revertCell(node, field, oldValue);
                toast.error(reason);
                void announce(reason);
            } catch {
                revertCell(node, field, oldValue);
                toast.error(SAVE_ERROR_MESSAGE);
                void announce(SAVE_ERROR_MESSAGE);
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
