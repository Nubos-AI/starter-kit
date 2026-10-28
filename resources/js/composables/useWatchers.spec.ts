import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    addWatcher as addWatcherAction,
    follow as followAction,
    index as indexAction,
    unfollow as unfollowAction,
} from '@/actions/App/Http/Controllers/Watchers/WatchersController';
import type { WatcherItem } from '@/composables/useWatchers';
import { isFollowing, useWatchers } from '@/composables/useWatchers';

const RECORD_ID = '01WATCH00K5N3Q8V9WYE6M2H7C';
const OTHER_USER_ID = '01USER00K5N3Q8V9WYE6M2H7CX';

vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        info: vi.fn(),
        warning: vi.fn(),
        message: vi.fn(),
    },
    Toaster: { name: 'ToasterStub', render: () => null },
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        patch: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({ props: { auth: { user: { id: 'u1' } } } }),
}));

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function makeWatcher(
    userId: string | null,
    label = 'Ich',
    id = `w-${userId ?? 'system'}`,
): WatcherItem {
    return {
        id,
        source: 'manual',
        user: userId === null ? null : { id: userId, label },
        createdAt: '2026-07-06T10:00:00.000Z',
    };
}

beforeEach(() => {
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=test-xsrf-token',
    } as unknown as Document);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useWatchers composable (load, follow, unfollow, addWatcher)', () => {
    it('loadForRecord(recordId) GETs the index endpoint and normalizes {data} into items via user.label', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: [
                        {
                            id: 'w1',
                            source: 'manual',
                            user: { id: 'u1', label: 'Ich' },
                            createdAt: '2026-07-06T10:00:00.000Z',
                        },
                        {
                            id: 'w2',
                            source: 'system',
                            user: { id: 'u2', label: 'Kollege' },
                            createdAt: null,
                        },
                    ],
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            indexAction.url({ record: RECORD_ID }),
            expect.objectContaining({ method: 'GET' }),
        );
        expect(items.value.map((item) => item.id)).toEqual(['w1', 'w2']);
        expect(items.value[0].user).toEqual({ id: 'u1', label: 'Ich' });
        expect(items.value[1].user).toEqual({ id: 'u2', label: 'Kollege' });
        expect(error.value).toBeNull();

        const headers = (fetchMock.mock.calls[0][1] as RequestInit)
            .headers as Record<string, string>;
        expect(headers['Accept']).toBe('application/json');
        expect(headers['X-Requested-With']).toBe('XMLHttpRequest');
        expect(headers['X-XSRF-TOKEN']).toBe('test-xsrf-token');
    });

    it('loadForRecord(recordId) yields an empty list and no error for {data: []}', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: [] })));
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();

        expect(items.value).toEqual([]);
        expect(error.value).toBeNull();
    });

    it('follow(recordId) POSTs the follow endpoint and leaves the current user following', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >((url, init) => {
            if (
                url === followAction.url({ record: RECORD_ID }) &&
                init?.method === 'POST'
            ) {
                return Promise.resolve(
                    jsonResponse(201, { data: makeWatcher('u1') }),
                );
            }

            return Promise.resolve(
                jsonResponse(200, { data: [makeWatcher('u1')] }),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, follow } = useWatchers();

        await follow(RECORD_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            followAction.url({ record: RECORD_ID }),
            expect.objectContaining({ method: 'POST' }),
        );
        expect(isFollowing(items.value, 'u1')).toBe(true);
        expect(error.value).toBeNull();
    });

    it('unfollow(recordId) DELETEs the follow endpoint, parses no body on a 204, and removes the current user', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >((url, init) => {
            if (
                url === unfollowAction.url({ record: RECORD_ID }) &&
                init?.method === 'DELETE'
            ) {
                return Promise.resolve(new Response(null, { status: 204 }));
            }

            return Promise.resolve(
                jsonResponse(200, { data: [makeWatcher('u1')] }),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord, unfollow } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();
        expect(isFollowing(items.value, 'u1')).toBe(true);

        await unfollow(RECORD_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            unfollowAction.url({ record: RECORD_ID }),
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(error.value).toBeNull();
        expect(isFollowing(items.value, 'u1')).toBe(false);
    });

    it('addWatcher(recordId, userId) POSTs the add endpoint and normalizes the returned {data}', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(201, {
                    data: makeWatcher(OTHER_USER_ID, 'Kollege', 'w-added'),
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { addWatcher } = useWatchers();

        const result = await addWatcher(RECORD_ID, OTHER_USER_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            addWatcherAction.url({ record: RECORD_ID, user: OTHER_USER_ID }),
            expect.objectContaining({ method: 'POST' }),
        );
        expect(result).not.toBeNull();
        expect(result?.id).toBe('w-added');
        expect(result?.user).toEqual({ id: OTHER_USER_ID, label: 'Kollege' });
    });

    it('loadForRecord(recordId) sets error and leaves items untouched on a non-ok response without throwing', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();

        expect(error.value).not.toBeNull();
        expect(items.value).toEqual([]);
    });

    it('follow(recordId) sets error, returns null, and leaves items untouched on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, follow } = useWatchers();

        const result = await follow(RECORD_ID);
        await flushPromises();

        expect(result).toBeNull();
        expect(error.value).not.toBeNull();
        expect(items.value).toEqual([]);
    });

    it('unfollow(recordId) sets error, returns false, and leaves items untouched on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >((url, init) => {
            if (
                url === unfollowAction.url({ record: RECORD_ID }) &&
                init?.method === 'DELETE'
            ) {
                return Promise.resolve(jsonResponse(500, {}));
            }

            return Promise.resolve(
                jsonResponse(200, { data: [makeWatcher('u1')] }),
            );
        });
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord, unfollow } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();
        const before = [...items.value];

        const result = await unfollow(RECORD_ID);
        await flushPromises();

        expect(result).toBe(false);
        expect(error.value).not.toBeNull();
        expect(items.value).toEqual(before);
    });

    it('addWatcher(recordId, userId) sets error, returns null, and leaves items untouched on a non-ok response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(500, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, addWatcher } = useWatchers();

        const result = await addWatcher(RECORD_ID, OTHER_USER_ID);
        await flushPromises();

        expect(result).toBeNull();
        expect(error.value).not.toBeNull();
        expect(items.value).toEqual([]);
    });
});

describe('isFollowing(items, currentUserId)', () => {
    it('returns true when currentUserId is among items[].user.id', () => {
        const items = [makeWatcher('u2', 'Kollege'), makeWatcher('u1', 'Ich')];

        expect(isFollowing(items, 'u1')).toBe(true);
    });

    it('returns false for an empty list', () => {
        expect(isFollowing([], 'u1')).toBe(false);
    });

    it('returns false when the currentUserId is not present', () => {
        expect(isFollowing([makeWatcher('u2', 'Kollege')], 'u1')).toBe(false);
    });

    it('ignores watchers whose user is null', () => {
        expect(isFollowing([makeWatcher(null)], 'u1')).toBe(false);
    });
});

describe('useWatchers composable — the reason the server gave', () => {
    it('follow(recordId) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(403, {
                        message: 'Sie dürfen diesen Datensatz nicht sehen.',
                    }),
                ),
            ),
        );

        const { follow, error } = useWatchers();

        await expect(follow(RECORD_ID)).resolves.toBeNull();

        expect(error.value).toBe('Sie dürfen diesen Datensatz nicht sehen.');
    });

    it('syncWatchers(recordId, userIds) shows the message the server sent', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Ein Benutzer gehört nicht zum Mandanten.',
                        errors: { user_ids: ['egal'] },
                    }),
                ),
            ),
        );

        const { syncWatchers, error } = useWatchers();

        await expect(syncWatchers(RECORD_ID, ['u9'])).resolves.toBe(false);

        expect(error.value).toBe('Ein Benutzer gehört nicht zum Mandanten.');
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(500, {}))),
        );

        const { follow, error } = useWatchers();

        await expect(follow(RECORD_ID)).resolves.toBeNull();

        expect(error.value).toBe(
            'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.',
        );
    });
});
