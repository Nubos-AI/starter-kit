import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    addWatcher as addWatcherAction,
    follow as followAction,
    index as indexAction,
    sync as syncAction,
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
        let isFailing = false;
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                isFailing
                    ? jsonResponse(500, {})
                    : jsonResponse(200, { data: [makeWatcher('u1')] }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();
        const before = [...items.value];
        isFailing = true;

        await loadForRecord(RECORD_ID);
        await flushPromises();

        expect(before).toHaveLength(1);
        expect(error.value).not.toBeNull();
        expect(items.value).toEqual(before);
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
        >((_url, init) =>
            Promise.resolve(
                init?.method === 'POST'
                    ? jsonResponse(500, {})
                    : jsonResponse(200, { data: [makeWatcher('u1')] }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadForRecord, addWatcher } = useWatchers();

        await loadForRecord(RECORD_ID);
        await flushPromises();
        const before = [...items.value];

        const result = await addWatcher(RECORD_ID, OTHER_USER_ID);
        await flushPromises();

        expect(before).toHaveLength(1);
        expect(result).toBeNull();
        expect(error.value).not.toBeNull();
        expect(items.value).toEqual(before);
    });
});

describe('isFollowing(items, currentUserId)', () => {
    it.each<[string, WatcherItem[], boolean]>([
        [
            'returns true when currentUserId is among items[].user.id',
            [makeWatcher('u2', 'Kollege'), makeWatcher('u1', 'Ich')],
            true,
        ],
        ['returns false for an empty list', [], false],
        [
            'returns false when the currentUserId is not present',
            [makeWatcher('u2', 'Kollege')],
            false,
        ],
        ['ignores watchers whose user is null', [makeWatcher(null)], false],
    ])('%s', (_name, items, expected) => {
        expect(isFollowing(items, 'u1')).toBe(expected);
    });
});

describe('useWatchers composable — the reason the server gave', () => {
    it.each<
        [
            string,
            Response,
            (watchers: ReturnType<typeof useWatchers>) => Promise<unknown>,
            unknown,
            string,
        ]
    >([
        [
            'follow(recordId) shows the message the server sent instead of a fixed sentence',
            jsonResponse(403, {
                message: 'Sie dürfen diesen Datensatz nicht sehen.',
            }),
            (watchers) => watchers.follow(RECORD_ID),
            null,
            'Sie dürfen diesen Datensatz nicht sehen.',
        ],
        [
            'syncWatchers(recordId, userIds) shows the message the server sent',
            jsonResponse(422, {
                message: 'Ein Benutzer gehört nicht zum Mandanten.',
                errors: { user_ids: ['egal'] },
            }),
            (watchers) => watchers.syncWatchers(RECORD_ID, ['u9']),
            false,
            'Ein Benutzer gehört nicht zum Mandanten.',
        ],
        [
            'follow(recordId) keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            (watchers) => watchers.follow(RECORD_ID),
            null,
            'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.',
        ],
    ])('%s', async (_name, response, run, expectedResult, expectedError) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(response)),
        );

        const watchers = useWatchers();

        await expect(run(watchers)).resolves.toBe(expectedResult);

        expect(watchers.error.value).toBe(expectedError);
    });
});

describe('useWatchers — replacing the watcher list', () => {
    it('PUTs the chosen user ids and takes the returned list as the new watchers', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: [
                        makeWatcher('u1', 'Ich', 'w-own'),
                        makeWatcher(OTHER_USER_ID, 'Kollege', 'w-other'),
                    ],
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const watchers = useWatchers();

        const synced = await watchers.syncWatchers(RECORD_ID, [
            'u1',
            OTHER_USER_ID,
        ]);

        expect(synced).toBe(true);
        expect(fetchMock).toHaveBeenCalledWith(
            syncAction.url({ record: RECORD_ID }),
            expect.objectContaining({
                method: 'PUT',
                body: JSON.stringify({ user_ids: ['u1', OTHER_USER_ID] }),
            }),
        );
        expect(watchers.items.value.map((item) => item.id)).toEqual([
            'w-own',
            'w-other',
        ]);
        expect(watchers.error.value).toBeNull();
    });
});
