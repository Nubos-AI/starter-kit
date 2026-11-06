import type { Ref } from 'vue';
import { ref } from 'vue';
import {
    archive as archiveAction,
    destroy as destroyAction,
    index as inboxIndexAction,
    markRead as markReadAction,
    markUnread as markUnreadAction,
    snooze as snoozeAction,
    unreadCount as unreadCountAction,
} from '@/actions/App/Http/Controllers/Notifications/InboxesController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const POLL_INTERVAL_MS = 10000;

const MAX_CONSECUTIVE_FAILURES = 5;

export type NotificationInboxState = 'messages' | 'snoozed' | 'archived';

export type NotificationPollStatus = 'idle' | 'polling' | 'failed';

export interface NotificationItem {
    id: string;
    type: string;
    data: Record<string, unknown>;
    priority: string;
    readAt: string | null;
    snoozedUntil: string | null;
    archivedAt: string | null;
    createdAt: string | null;
}

export interface NotificationPageMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

export interface UseNotificationsReturn {
    unreadCount: Ref<number>;
    items: Ref<NotificationItem[]>;
    meta: Ref<NotificationPageMeta | null>;
    status: Ref<NotificationPollStatus>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    startPolling: () => void;
    stopPolling: () => void;
    load: (state?: NotificationInboxState) => Promise<void>;
    markRead: (inbox: string) => Promise<void>;
    markUnread: (inbox: string) => Promise<void>;
    snooze: (inbox: string, snoozedUntil: string) => Promise<void>;
    archive: (inbox: string) => Promise<void>;
    remove: (inbox: string) => Promise<void>;
}

const LOAD_ERROR_MESSAGE =
    'Die Benachrichtigungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const ACTION_ERROR_MESSAGE =
    'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.';

function normalizeItem(raw: unknown): NotificationItem {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        type: String(source.type ?? ''),
        data:
            source.data && typeof source.data === 'object'
                ? (source.data as Record<string, unknown>)
                : {},
        priority: String(source.priority ?? 'normal'),
        readAt:
            source.readAt === undefined
                ? null
                : (source.readAt as string | null),
        snoozedUntil:
            source.snoozedUntil === undefined
                ? null
                : (source.snoozedUntil as string | null),
        archivedAt:
            source.archivedAt === undefined
                ? null
                : (source.archivedAt as string | null),
        createdAt:
            source.createdAt === undefined
                ? null
                : (source.createdAt as string | null),
    };
}

const unreadCount = ref<number>(0);
const items = ref<NotificationItem[]>([]);
const meta = ref<NotificationPageMeta | null>(null);
const status = ref<NotificationPollStatus>('idle');
const loading = ref<boolean>(false);
const error = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | null = null;
let active = false;
let consecutiveFailures = 0;

function clearTimer(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

async function request(url: string, init: RequestInit): Promise<Response> {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

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
    }
}

async function readUnreadCount(): Promise<number | null> {
    try {
        const response = await request(unreadCountAction.url(), {
            method: 'GET',
        });

        if (!response.ok) {
            return null;
        }

        const data = (await response.json()) as { count?: number };

        return Number(data.count ?? 0);
    } catch {
        return null;
    }
}

async function refreshUnreadCount(): Promise<void> {
    const count = await readUnreadCount();

    if (count !== null) {
        unreadCount.value = count;
    }
}

async function poll(): Promise<void> {
    if (!active) {
        return;
    }

    const count = await readUnreadCount();

    if (!active) {
        return;
    }

    if (count === null) {
        consecutiveFailures += 1;

        if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES) {
            clearTimer();
            active = false;
            status.value = 'failed';

            return;
        }

        timer = setTimeout(() => void poll(), POLL_INTERVAL_MS);

        return;
    }

    consecutiveFailures = 0;
    unreadCount.value = count;

    timer = setTimeout(() => void poll(), POLL_INTERVAL_MS);
}

function startPolling(): void {
    clearTimer();
    active = true;
    consecutiveFailures = 0;
    status.value = 'polling';
    void poll();
}

function stopPolling(): void {
    active = false;
    clearTimer();

    if (status.value === 'polling') {
        status.value = 'idle';
    }
}

async function load(state?: NotificationInboxState): Promise<void> {
    loading.value = true;

    const url = state
        ? `${inboxIndexAction.url()}?state=${encodeURIComponent(state)}`
        : inboxIndexAction.url();

    try {
        const response = await request(url, { method: 'GET' });

        if (!response.ok) {
            throw new Error(
                `Inbox endpoint responded with status ${response.status}`,
            );
        }

        const payload = (await response.json()) as {
            data?: unknown[];
            meta?: NotificationPageMeta;
        };

        items.value = (payload.data ?? []).map(normalizeItem);
        meta.value = payload.meta ?? null;
        error.value = null;
        void refreshUnreadCount();
    } catch {
        error.value = LOAD_ERROR_MESSAGE;
    } finally {
        loading.value = false;
    }
}

function applyUpdatedItem(raw: unknown): void {
    const updated = normalizeItem(raw);

    if (updated.id === '') {
        return;
    }

    items.value = items.value.map((item) =>
        item.id === updated.id ? updated : item,
    );
}

async function mutate(url: string, body?: unknown): Promise<void> {
    try {
        const response = await request(url, {
            method: 'PATCH',
            body: body === undefined ? undefined : JSON.stringify(body),
        });

        if (!response.ok) {
            error.value = await readErrorReason(response, ACTION_ERROR_MESSAGE);

            return;
        }

        applyUpdatedItem(await response.json());
        error.value = null;
        void refreshUnreadCount();
    } catch {
        error.value = ACTION_ERROR_MESSAGE;
    }
}

const markRead = (inbox: string): Promise<void> =>
    mutate(markReadAction.url({ inbox }));

const markUnread = (inbox: string): Promise<void> =>
    mutate(markUnreadAction.url({ inbox }));

const snooze = (inbox: string, snoozedUntil: string): Promise<void> =>
    mutate(snoozeAction.url({ inbox }), { snoozed_until: snoozedUntil });

const archive = (inbox: string): Promise<void> =>
    mutate(archiveAction.url({ inbox }));

async function remove(inbox: string): Promise<void> {
    try {
        const response = await request(destroyAction.url({ inbox }), {
            method: 'DELETE',
        });

        if (!response.ok) {
            error.value = await readErrorReason(response, ACTION_ERROR_MESSAGE);

            return;
        }

        items.value = items.value.filter((item) => item.id !== inbox);
        error.value = null;
        void refreshUnreadCount();
    } catch {
        error.value = ACTION_ERROR_MESSAGE;
    }
}

export function useNotifications(): UseNotificationsReturn {
    return {
        unreadCount,
        items,
        meta,
        status,
        loading,
        error,
        startPolling,
        stopPolling,
        load,
        markRead,
        markUnread,
        snooze,
        archive,
        remove,
    };
}

export function resetNotificationsStateForTests(): void {
    stopPolling();
    unreadCount.value = 0;
    items.value = [];
    meta.value = null;
    status.value = 'idle';
    loading.value = false;
    error.value = null;
    consecutiveFailures = 0;
}
