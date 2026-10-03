import type { Ref } from 'vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    store,
    update,
} from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorResponse, toFieldErrors } from '@/lib/errorResponse';
import { extractRecord } from '@/lib/recordPayload';
import { recordToast } from '@/lib/recordToast';
import type { RecordObjectType, RecordPayload } from '@/types/records';

const REQUEST_TIMEOUT_MS = 15000;

const SAVE_ERROR_MESSAGE = 'Der Datensatz konnte nicht gespeichert werden.';

const ASSIGNMENT_ERROR_MESSAGE =
    'Die Zuständigkeit konnte nicht gespeichert werden.';

export type RecordFormMode = 'create' | 'edit';

export interface UseRecordFormOptions {
    mode: RecordFormMode;
    objectType: RecordObjectType;
    record: RecordPayload | null;
    collaboratorIds?: string[];
    attributes?: () => Record<string, unknown>;
    validateExtensions?: () => Record<string, string>;
    onSaved: (record: RecordPayload) => void;
}

export interface UseRecordFormReturn {
    values: Ref<Record<string, unknown>>;
    currentRecord: Ref<RecordPayload | null>;
    ownerId: Ref<string | null>;
    collaboratorIds: Ref<string[]>;
    errors: Ref<Record<string, string>>;
    saving: Ref<boolean>;
    conflictOpen: Ref<boolean>;
    submit: () => Promise<void>;
    saveAssignment: () => Promise<void>;
    closeConflict: () => void;
    adoptRecord: (record: RecordPayload) => void;
}

export function useRecordForm(
    options: UseRecordFormOptions,
): UseRecordFormReturn {
    const values = ref<Record<string, unknown>>({
        ...(options.record?.data ?? {}),
    });
    const ownerId = ref<string | null>(options.record?.ownerId ?? null);
    const collaboratorIds = ref<string[]>([...(options.collaboratorIds ?? [])]);
    const errors = ref<Record<string, string>>({});
    const saving = ref<boolean>(false);
    const conflictOpen = ref<boolean>(false);
    const current = ref<RecordPayload | null>(options.record);
    const confirmedOwnerId = ref<string | null>(
        options.record?.ownerId ?? null,
    );
    const confirmedCollaboratorIds = ref<string[]>([
        ...(options.collaboratorIds ?? []),
    ]);

    const closeConflict = (): void => {
        conflictOpen.value = false;
    };

    const restoreAssignment = (): void => {
        ownerId.value = confirmedOwnerId.value;
        collaboratorIds.value = [...confirmedCollaboratorIds.value];
    };

    const rememberAssignment = (): void => {
        confirmedOwnerId.value = ownerId.value;
        confirmedCollaboratorIds.value = [...collaboratorIds.value];
    };

    const request = async (): Promise<Response> => {
        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            if (options.mode === 'edit' && current.value !== null) {
                return await fetch(update.url({ record: current.value.id }), {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: true }),
                    body: JSON.stringify({
                        data: values.value,
                        version: current.value.version,
                        owner_id: ownerId.value,
                        collaborator_ids: collaboratorIds.value,
                    }),
                    signal: controller.signal,
                });
            }

            return await fetch(store.url({ objectType: options.objectType }), {
                method: 'POST',
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                body: JSON.stringify({
                    data: values.value,
                    owner_id: ownerId.value,
                    collaborator_ids: collaboratorIds.value,
                    ...options.attributes?.(),
                }),
                signal: controller.signal,
            });
        } finally {
            clearTimeout(timeout);
        }
    };

    const saveAssignment = async (): Promise<void> => {
        if (options.mode !== 'edit' || current.value === null || saving.value) {
            return;
        }

        saving.value = true;

        const controller = new AbortController();
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            const response = await fetch(
                update.url({ record: current.value.id }),
                {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: true }),
                    body: JSON.stringify({
                        version: current.value.version,
                        owner_id: ownerId.value,
                        collaborator_ids: collaboratorIds.value,
                    }),
                    signal: controller.signal,
                },
            );

            if (response.status === 200) {
                current.value = extractRecord(await response.json());
                rememberAssignment();
                recordToast('edit');

                return;
            }

            if (response.status === 409) {
                current.value = extractRecord(await response.json());
                restoreAssignment();
                conflictOpen.value = true;

                return;
            }

            restoreAssignment();
            toast.error(ASSIGNMENT_ERROR_MESSAGE);
        } catch {
            restoreAssignment();
            toast.error(ASSIGNMENT_ERROR_MESSAGE);
        } finally {
            clearTimeout(timeout);
            saving.value = false;
        }
    };

    const submit = async (): Promise<void> => {
        if (saving.value) {
            return;
        }

        saving.value = true;
        errors.value = options.validateExtensions?.() ?? {};

        if (Object.keys(errors.value).length > 0) {
            return;
        }

        try {
            const response = await request();

            if (response.status === 200 || response.status === 201) {
                const record = extractRecord(await response.json());
                current.value = record;
                rememberAssignment();
                recordToast(options.mode === 'edit' ? 'edit' : 'create');
                options.onSaved(record);

                return;
            }

            if (response.status === 409) {
                const fresh = extractRecord(await response.json());
                current.value = fresh;
                values.value = { ...fresh.data };
                conflictOpen.value = true;

                return;
            }

            if (response.status === 422) {
                const failure = await readErrorResponse(response);
                errors.value = toFieldErrors(failure.errors);
                toast.error(failure.message ?? SAVE_ERROR_MESSAGE);

                return;
            }

            toast.error(SAVE_ERROR_MESSAGE);
        } catch {
            toast.error(SAVE_ERROR_MESSAGE);
        } finally {
            saving.value = false;
        }
    };

    const adoptRecord = (record: RecordPayload): void => {
        current.value = record;
    };

    return {
        values,
        currentRecord: current,
        ownerId,
        collaboratorIds,
        errors,
        saving,
        conflictOpen,
        submit,
        saveAssignment,
        closeConflict,
        adoptRecord,
    };
}
