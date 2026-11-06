import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import RecordMergeController from '@/actions/App/Http/Controllers/Engine/RecordMergeController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';
import type { MergePlan, MergeRequestPayload } from '@/types/merge';

const PREVIEW_ERROR_MESSAGE =
    'Die Vorschau für das Zusammenführen konnte nicht geladen werden.';

const MERGE_ERROR_MESSAGE =
    'Die Datensätze konnten nicht zusammengeführt werden.';

const UNDO_ERROR_MESSAGE =
    'Das Zusammenführen konnte nicht zurückgenommen werden.';

export interface MergeResult {
    merge_id: string;
    target_id: string;
    source_id: string;
}

export interface UndoResult {
    merge_id: string;
    target_id: string;
    source_id: string;
    restored_fields: string[];
    kept_fields: string[];
    restored_transfers: Record<string, number>;
    unrecoverable: Record<string, number>;
}

export interface UseRecordMergeReturn {
    plan: Ref<MergePlan | null>;
    previewing: Ref<boolean>;
    merging: Ref<boolean>;
    undoing: Ref<boolean>;
    preview: (recordId: string, payload: MergeRequestPayload) => Promise<void>;
    merge: (
        recordId: string,
        payload: MergeRequestPayload,
    ) => Promise<MergeResult | null>;
    undo: (recordId: string, mergeId: string) => Promise<UndoResult | null>;
}

export function useRecordMerge(
    onMerged: (result: MergeResult) => void,
): UseRecordMergeReturn {
    const plan = ref<MergePlan | null>(null);
    const previewing = ref<boolean>(false);
    const merging = ref<boolean>(false);
    const undoing = ref<boolean>(false);

    const post = async (
        url: string,
        payload: MergeRequestPayload,
    ): Promise<Response> =>
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: buildHeaders({ hasBody: true }),
            body: JSON.stringify(payload),
        });

    const preview = async (
        recordId: string,
        payload: MergeRequestPayload,
    ): Promise<void> => {
        if (previewing.value) {
            return;
        }

        previewing.value = true;

        try {
            const response = await post(
                RecordMergeController.preview.url({ record: recordId }),
                payload,
            );

            if (!response.ok) {
                toast.error(
                    await readErrorReason(response, PREVIEW_ERROR_MESSAGE),
                );

                return;
            }

            const body = (await response.json()) as { data: MergePlan };

            plan.value = body.data;
        } catch {
            toast.error(PREVIEW_ERROR_MESSAGE);
        } finally {
            previewing.value = false;
        }
    };

    const merge = async (
        recordId: string,
        payload: MergeRequestPayload,
    ): Promise<MergeResult | null> => {
        if (merging.value) {
            return null;
        }

        merging.value = true;

        try {
            const response = await post(
                RecordMergeController.store.url({ record: recordId }),
                payload,
            );

            if (response.status !== 201) {
                toast.error(
                    await readErrorReason(response, MERGE_ERROR_MESSAGE),
                );

                return null;
            }

            const body = (await response.json()) as { data: MergeResult };

            onMerged(body.data);

            return body.data;
        } catch {
            toast.error(MERGE_ERROR_MESSAGE);

            return null;
        } finally {
            merging.value = false;
        }
    };

    const undo = async (
        recordId: string,
        mergeId: string,
    ): Promise<UndoResult | null> => {
        if (undoing.value) {
            return null;
        }

        undoing.value = true;

        try {
            const response = await fetch(
                RecordMergeController.undo.url({
                    record: recordId,
                    merge: mergeId,
                }),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: true }),
                    body: '{}',
                },
            );

            if (!response.ok) {
                toast.error(
                    await readErrorReason(response, UNDO_ERROR_MESSAGE),
                );

                return null;
            }

            const body = (await response.json()) as { data: UndoResult };

            return body.data;
        } catch {
            toast.error(UNDO_ERROR_MESSAGE);

            return null;
        } finally {
            undoing.value = false;
        }
    };

    return { plan, previewing, merging, undoing, preview, merge, undo };
}
