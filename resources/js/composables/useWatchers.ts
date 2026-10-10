import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import {
    addWatcher as addWatcherAction,
    follow as followAction,
    index as indexAction,
    sync as syncAction,
    unfollow as unfollowAction,
} from '@/actions/App/Http/Controllers/Watchers/WatchersController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';

const REQUEST_TIMEOUT_MS = 15000;

const LOAD_ERROR_MESSAGE =
    'Die Beobachter konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const ACTION_ERROR_MESSAGE =
    'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.';

export interface WatcherUserRef {
    id: string;
    label: string;
}

export interface WatcherItem {
    id: string;
    source: string;
    user: WatcherUserRef | null;
    createdAt: string | null;
}

export interface UseWatchersReturn {
    items: Ref<WatcherItem[]>;
    loading: Ref<boolean>;
    error: Ref<string | null>;
    following: Ref<boolean>;
    loadForRecord: (recordId: string) => Promise<void>;
    follow: (recordId: string) => Promise<WatcherItem | null>;
    unfollow: (recordId: string) => Promise<boolean>;
    addWatcher: (
        recordId: string,
        userId: string,
    ) => Promise<WatcherItem | null>;
    syncWatchers: (recordId: string, userIds: string[]) => Promise<boolean>;
}

function nullableString(value: unknown): string | null {
    return value === undefined || value === null ? null : String(value);
}

function normalizeUserRef(raw: unknown): WatcherUserRef | null {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const source = raw as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        label: String(source.label ?? ''),
    };
}

function normalizeItem(raw: unknown): WatcherItem {
    const source = (raw ?? {}) as Record<string, unknown>;

    return {
        id: String(source.id ?? ''),
        source: String(source.source ?? ''),
        user: normalizeUserRef(source.user ?? null),
        createdAt: nullableString(source.createdAt),
    };
}

export function isFollowing(
    items: WatcherItem[],
    currentUserId: string | null,
): boolean {
    if (currentUserId === null) {
        return false;
    }

    return items.some((item) => item.user?.id === currentUserId);
}

function currentUserId(): string | null {
    const auth = usePage().props.auth as
        | { user?: { id?: unknown } | null }
        | undefined;

    const id = auth?.user?.id;

    return id === undefined || id === null ? null : String(id);
}

export function useWatchers(): UseWatchersReturn {
    const items = ref<WatcherItem[]>([]);
    const loading = ref<boolean>(false);
    const error = ref<string | null>(null);

    const viewerId = currentUserId();
    const following = computed<boolean>(() =>
        isFollowing(items.value, viewerId),
    );

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

    const loadForRecord = async (recordId: string): Promise<void> => {
        loading.value = true;

        try {
            const response = await request(
                indexAction.url({ record: recordId }),
                { method: 'GET' },
            );

            if (!response.ok) {
                throw new Error(
                    `Watcher list endpoint responded with status ${response.status}`,
                );
            }

            const payload = (await response.json()) as { data?: unknown[] };

            items.value = (payload.data ?? []).map(normalizeItem);
            error.value = null;
        } catch {
            error.value = LOAD_ERROR_MESSAGE;
        } finally {
            loading.value = false;
        }
    };

    const follow = async (recordId: string): Promise<WatcherItem | null> => {
        try {
            const response = await request(
                followAction.url({ record: recordId }),
                { method: 'POST' },
            );

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return null;
            }

            error.value = null;

            const payload = (await response.json()) as { data?: unknown };
            const item =
                payload.data === undefined || payload.data === null
                    ? null
                    : normalizeItem(payload.data);

            if (
                item !== null &&
                !isFollowing(items.value, item.user?.id ?? null)
            ) {
                items.value = [...items.value, item];
            }

            return item;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return null;
        }
    };

    const unfollow = async (recordId: string): Promise<boolean> => {
        try {
            const response = await request(
                unfollowAction.url({ record: recordId }),
                { method: 'DELETE' },
            );

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return false;
            }

            error.value = null;

            items.value = items.value.filter(
                (item) => item.user?.id !== viewerId,
            );

            return true;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return false;
        }
    };

    const syncWatchers = async (
        recordId: string,
        userIds: string[],
    ): Promise<boolean> => {
        try {
            const response = await request(
                syncAction.url({ record: recordId }),
                {
                    method: 'PUT',
                    body: JSON.stringify({ user_ids: userIds }),
                },
            );

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return false;
            }

            const payload = (await response.json()) as { data?: unknown[] };

            items.value = (payload.data ?? []).map(normalizeItem);
            error.value = null;

            return true;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return false;
        }
    };

    const addWatcher = async (
        recordId: string,
        userId: string,
    ): Promise<WatcherItem | null> => {
        try {
            const response = await request(
                addWatcherAction.url({ record: recordId, user: userId }),
                { method: 'POST' },
            );

            if (!response.ok) {
                error.value = await readErrorReason(
                    response,
                    ACTION_ERROR_MESSAGE,
                );

                return null;
            }

            error.value = null;

            const payload = (await response.json()) as { data?: unknown };
            const item =
                payload.data === undefined || payload.data === null
                    ? null
                    : normalizeItem(payload.data);

            if (
                item !== null &&
                !items.value.some((row) => row.id === item.id)
            ) {
                items.value = [...items.value, item];
            }

            return item;
        } catch {
            error.value = ACTION_ERROR_MESSAGE;

            return null;
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
        following,
        loadForRecord,
        follow,
        unfollow,
        addWatcher,
        syncWatchers,
    };
}
