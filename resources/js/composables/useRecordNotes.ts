import type { Ref } from 'vue';
import { getCurrentScope, onScopeDispose, ref } from 'vue';
import { store as storeAction } from '@/actions/App/Http/Controllers/Notes/RecordNotesController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readBodyReason } from '@/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const EMPTY_BODY_MESSAGE = 'Bitte geben Sie einen Text für die Notiz ein.';

const SAVE_ERROR_MESSAGE =
    'Die Notiz konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export interface RecordNoteAuthorRef {
    id: string;
    label: string;
}

export interface RecordNoteItem {
    id: string;
    body: string;
    author: RecordNoteAuthorRef | null;
    createdAt: string | null;
    updatedAt: string | null;
}

export interface UseRecordNotesReturn {
    saving: Ref<boolean>;
    error: Ref<string | null>;
    create: (recordId: string, body: string) => Promise<RecordNoteItem | null>;
}

function nullableString(value: unknown): string | null {
    return value === undefined || value === null ? null : String(value);
}

function normalizeAuthor(raw: unknown): RecordNoteAuthorRef | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const source = raw as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        label: String(source.label ?? ''),
    };
}

function normalizeItem(raw: unknown): RecordNoteItem {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        body: String(source.body ?? ''),
        author: normalizeAuthor(source.author ?? null),
        createdAt: nullableString(source.createdAt),
        updatedAt: nullableString(source.updatedAt),
    };
}

export function useRecordNotes(): UseRecordNotesReturn {
    const saving = ref<boolean>(false);
    const error = ref<string | null>(null);

    const controllers = new Set<AbortController>();

    const request = async (
        url: string,
        init: RequestInit,
    ): Promise<Response> => {
        const controller = new AbortController();
        controllers.add(controller);
        const timeout = setTimeout(
            () => controller.abort(),
            REQUEST_TIMEOUT_MS,
        );

        try {
            return await fetch(url, {
                credentials: 'same-origin',
                headers: buildHeaders({ hasBody: true }),
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    };

    const create = async (
        recordId: string,
        body: string,
    ): Promise<RecordNoteItem | null> => {
        const trimmed = body.trim();

        if (trimmed === '') {
            error.value = EMPTY_BODY_MESSAGE;

            return null;
        }

        saving.value = true;

        try {
            const response = await request(
                storeAction.url({ record: recordId }),
                { method: 'POST', body: JSON.stringify({ body: trimmed }) },
            );

            const payload = (await response.json()) as { data?: unknown };

            if (!response.ok) {
                error.value = readBodyReason(payload) ?? SAVE_ERROR_MESSAGE;

                return null;
            }

            error.value = null;

            return payload.data === undefined || payload.data === null
                ? null
                : normalizeItem(payload.data);
        } catch {
            error.value = SAVE_ERROR_MESSAGE;

            return null;
        } finally {
            saving.value = false;
        }
    };

    if (getCurrentScope()) {
        onScopeDispose(() => {
            for (const controller of controllers) {
                controller.abort();
            }

            controllers.clear();
        });
    }

    return { saving, error, create };
}
