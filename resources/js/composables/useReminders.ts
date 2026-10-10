import type { Ref } from 'vue';
import { getCurrentScope, onScopeDispose, ref } from 'vue';
import {
    bulkDestroy as bulkDestroyAction,
    complete as completeAction,
    destroy as destroyAction,
    forRecord as forRecordAction,
    myOpen as myOpenAction,
    store as storeAction,
    update as updateAction,
} from '@/actions/App/Http/Controllers/Reminders/ReminderTasksController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE =
    'Die Erinnerungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const ACTION_ERROR_MESSAGE =
    'Die Erinnerung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

export interface ReminderUserRef {
    id: string;
    label: string;
}

export interface ReminderRecordRef {
    id: string;
    label: string | null;
    deleted: boolean;
}

export interface ReminderTypeRef {
    id: string;
    label: string;
}

export interface ReminderItem {
    id: string;
    subject: string;
    note: string | null;
    type: ReminderTypeRef | null;
    dueAt: string | null;
    doneAt: string | null;
    owner: ReminderUserRef | null;
    assignee: ReminderUserRef | null;
    record: ReminderRecordRef | null;
}

export interface ReminderInput {
    subject: string;
    due_at?: string | null;
    note?: string | null;
    reminder_type_id?: string | null;
    assignee_id?: string | null;
    record_id?: string | null;
}

export interface UseRemindersReturn {
    items: Ref<ReminderItem[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    loadMyOpen: () => Promise<void>;
    loadForRecord: (recordId: string) => Promise<void>;
    create: (input: ReminderInput) => Promise<ReminderItem | null>;
    update: (id: string, input: ReminderInput) => Promise<ReminderItem | null>;
    complete: (id: string) => Promise<ReminderItem | null>;
    destroy: (id: string) => Promise<boolean>;
    bulkDestroy: (ids: string[]) => Promise<boolean>;
}

function nullableString(value: unknown): string | null {
    return value === undefined || value === null ? null : String(value);
}

function normalizeUserRef(raw: unknown): ReminderUserRef | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const source = raw as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        label: String(source.label ?? ''),
    };
}

function normalizeRecordRef(raw: unknown): ReminderRecordRef | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const source = raw as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        label: nullableString(source.label),
        deleted: source.deleted === true,
    };
}

function normalizeTypeRef(raw: unknown): ReminderTypeRef | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const source = raw as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        label: String(source.label ?? ''),
    };
}

function normalizeItem(raw: unknown): ReminderItem {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        subject: String(source.subject ?? ''),
        note: nullableString(source.note),
        type: normalizeTypeRef(source.type ?? null),
        dueAt: nullableString(source.dueAt),
        doneAt: nullableString(source.doneAt),
        owner: normalizeUserRef(source.owner ?? null),
        assignee: normalizeUserRef(source.assignee ?? null),
        record: normalizeRecordRef(source.record ?? null),
    };
}

export function compareByDueAt(a: ReminderItem, b: ReminderItem): number {
    if (a.dueAt === null && b.dueAt === null) {
        return 0;
    }

    if (a.dueAt === null) {
        return 1;
    }

    if (b.dueAt === null) {
        return -1;
    }

    return a.dueAt.localeCompare(b.dueAt);
}

function sortByDueAt(list: ReminderItem[]): ReminderItem[] {
    return [...list].sort(compareByDueAt);
}

export function isOverdue(
    item: ReminderItem,
    now: number = Date.now(),
): boolean {
    return (
        item.dueAt !== null &&
        item.doneAt === null &&
        new Date(item.dueAt).getTime() < now
    );
}

export function formatDueAt(value: string | null): string {
    if (value === null) {
        return 'Kein Fälligkeitsdatum';
    }

    return new Date(value).toLocaleString('de-DE', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

export function useReminders(): UseRemindersReturn {
    const items = ref<ReminderItem[]>([]);
    const loading = ref<boolean>(false);
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
                headers: buildHeaders({
                    hasBody: init.body !== undefined && init.body !== null,
                }),
                signal: controller.signal,
                ...init,
            });
        } finally {
            clearTimeout(timeout);
            controllers.delete(controller);
        }
    };

    const loadFrom = async (url: string): Promise<void> => {
        loading.value = true;

        try {
            const response = await request(url, { method: 'GET' });

            if (!response.ok) {
                throw new Error(
                    `Reminder list endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as { data?: unknown[] };

            items.value = sortByDueAt((payload.data ?? []).map(normalizeItem));
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const loadMyOpen = (): Promise<void> => loadFrom(myOpenAction.url());

    const loadForRecord = (recordId: string): Promise<void> =>
        loadFrom(forRecordAction.url({ record: recordId }));

    const mutate = async (
        url: string,
        method: string,
        body?: ReminderInput,
    ): Promise<ReminderItem | null> => {
        try {
            const init: RequestInit = { method };

            if (body !== undefined) {
                init.body = JSON.stringify(body);
            }

            const response = await request(url, init);

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return null;
            }

            error.value = null;

            const payload = (await response.json()) as { data?: unknown };

            return payload.data === undefined || payload.data === null
                ? null
                : normalizeItem(payload.data);
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return null;
        }
    };

    const create = (input: ReminderInput): Promise<ReminderItem | null> => {
        const subject = input.subject.trim();

        if (subject === '') {
            error.value = ACTION_ERROR_MESSAGE;

            return Promise.resolve(null);
        }

        return mutate(storeAction.url(), 'POST', { ...input, subject });
    };

    const update = (
        id: string,
        input: ReminderInput,
    ): Promise<ReminderItem | null> => {
        const subject = input.subject.trim();

        if (subject === '') {
            error.value = ACTION_ERROR_MESSAGE;

            return Promise.resolve(null);
        }

        return mutate(updateAction.url({ reminder: id }), 'PUT', {
            ...input,
            subject,
        });
    };

    const complete = (id: string): Promise<ReminderItem | null> =>
        mutate(completeAction.url({ reminder: id }), 'PATCH');

    const destroy = async (id: string): Promise<boolean> => {
        try {
            const response = await request(
                destroyAction.url({ reminder: id }),
                {
                    method: 'DELETE',
                },
            );

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return false;
            }

            error.value = null;

            return true;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return false;
        }
    };

    const bulkDestroy = async (targetIds: string[]): Promise<boolean> => {
        if (targetIds.length === 0) {
            return true;
        }

        try {
            const response = await request(bulkDestroyAction.url(), {
                method: 'POST',
                body: JSON.stringify({ ids: targetIds }),
            });

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return false;
            }

            error.value = null;

            return true;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return false;
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

    return {
        items,
        loading,
        error,
        loadMyOpen,
        loadForRecord,
        create,
        update,
        complete,
        destroy,
        bulkDestroy,
    };
}
