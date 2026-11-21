import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  addWatcher as addWatcherAction,
  follow as followAction,
  index as indexAction,
  sync as syncAction,
  unfollow as unfollowAction,
} from '@/actions/App/Http/Controllers/Watchers/WatchersController';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { isFollowing, useWatchers } from './useWatchers';
import type { WatcherItem } from './useWatchers';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const RECORD_ID = '01WATCH00K5N3Q8V9WYE6M2H7C';
const OTHER_USER_ID = '01USER00K5N3Q8V9WYE6M2H7CX';

const ACTION_ERROR = 'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.';

const LOAD_ERROR = 'Die Beobachter konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

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

setUrlDefaults({ activeTeam: 'nubos' });

const indexRoute = indexAction({ record: RECORD_ID });

const followRoute = followAction({ record: RECORD_ID });

const unfollowRoute = unfollowAction({ record: RECORD_ID });

const syncRoute = syncAction({ record: RECORD_ID });

const addWatcherRoute = addWatcherAction({
  record: RECORD_ID,
  user: OTHER_USER_ID,
});

function makeWatcher(userId: string | null, label = 'Ich', id = `w-${userId ?? 'system'}`): WatcherItem {
  return {
    id,
    source: 'manual',
    user: userId === null ? null : { id: userId, label },
    createdAt: '2026-07-06T10:00:00.000Z',
  };
}

function expectSession(served: ServedRoute): void {
  expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
  expect(served.lastRequest()?.headers.get('X-Requested-With')).toBe('XMLHttpRequest');
  expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useWatchers composable (load, follow, unfollow, addWatcher)', () => {
  it('loadForRecord(recordId) GETs the index endpoint and normalizes {data} into items via user.label', async () => {
    const served = serveJson(indexRoute, {
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
    });

    const { items, error, loading, loadForRecord } = useWatchers();

    await loadForRecord(RECORD_ID);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/watchers`);
    expectSession(served);
    expect(items.value.map((item) => item.id)).toEqual(['w1', 'w2']);
    expect(items.value[0].user).toEqual({ id: 'u1', label: 'Ich' });
    expect(items.value[1].user).toEqual({ id: 'u2', label: 'Kollege' });
    expect(error.value).toBeNull();
    expect(loading.value).toBe(false);
  });

  it('loadForRecord(recordId) marks the list as loading until the server answers', async () => {
    const served = serveJson(indexRoute, { data: [] }, { held: true });

    const { loading, loadForRecord } = useWatchers();

    const loadingWatchers = loadForRecord(RECORD_ID);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingWatchers;

    expect(loading.value).toBe(false);
  });

  it('follow(recordId) POSTs the follow endpoint and leaves the current user following', async () => {
    const served = serveJson(followRoute, { data: makeWatcher('u1') }, { status: 201 });

    const { items, error, following, follow } = useWatchers();

    await follow(RECORD_ID);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/watchers/follow`);
    expectSession(served);
    expect(isFollowing(items.value, 'u1')).toBe(true);
    expect(following.value).toBe(true);
    expect(error.value).toBeNull();
  });

  it('unfollow(recordId) DELETEs the follow endpoint, parses no body on a 204, and removes the current user', async () => {
    serveJson(indexRoute, { data: [makeWatcher('u1')] });
    const served = serveEmpty(unfollowRoute);

    const { items, error, loadForRecord, unfollow } = useWatchers();

    await loadForRecord(RECORD_ID);
    expect(isFollowing(items.value, 'u1')).toBe(true);

    await expect(unfollow(RECORD_ID)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/watchers/follow`);
    expect(error.value).toBeNull();
    expect(isFollowing(items.value, 'u1')).toBe(false);
  });

  it('addWatcher(recordId, userId) POSTs the add endpoint and normalizes the returned {data}', async () => {
    const served = serveJson(
      addWatcherRoute,
      { data: makeWatcher(OTHER_USER_ID, 'Kollege', 'w-added') },
      { status: 201 },
    );

    const { addWatcher, items } = useWatchers();

    const result = await addWatcher(RECORD_ID, OTHER_USER_ID);

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/watchers/${OTHER_USER_ID}`);
    expect(result).not.toBeNull();
    expect(result?.id).toBe('w-added');
    expect(result?.user).toEqual({ id: OTHER_USER_ID, label: 'Kollege' });
    expect(items.value.map((item) => item.id)).toEqual(['w-added']);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(indexRoute, {})],
    ['the connection fails', () => serveNetworkError(indexRoute)],
  ])('loadForRecord(recordId) sets error and leaves items untouched when %s', async (_name, serve) => {
    serveJson(indexRoute, { data: [makeWatcher('u1')] }, { once: true });

    const { items, error, loading, loadForRecord } = useWatchers();

    await loadForRecord(RECORD_ID);
    const before = [...items.value];
    serve();

    await loadForRecord(RECORD_ID);

    expect(before).toHaveLength(1);
    expect(error.value).toBe(LOAD_ERROR);
    expect(items.value).toEqual(before);
    expect(loading.value).toBe(false);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(unfollowRoute, {})],
    ['the connection fails', () => serveNetworkError(unfollowRoute)],
  ])('unfollow(recordId) sets error, returns false, and leaves items untouched when %s', async (_name, serve) => {
    serveJson(indexRoute, { data: [makeWatcher('u1')] });
    serve();

    const { items, error, loadForRecord, unfollow } = useWatchers();

    await loadForRecord(RECORD_ID);
    const before = [...items.value];

    const result = await unfollow(RECORD_ID);

    expect(result).toBe(false);
    expect(error.value).toBe(ACTION_ERROR);
    expect(items.value).toEqual(before);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(addWatcherRoute, {})],
    ['the connection fails', () => serveNetworkError(addWatcherRoute)],
  ])(
    'addWatcher(recordId, userId) sets error, returns null, and leaves items untouched when %s',
    async (_name, serve) => {
      serveJson(indexRoute, { data: [makeWatcher('u1')] });
      serve();

      const { items, error, loadForRecord, addWatcher } = useWatchers();

      await loadForRecord(RECORD_ID);
      const before = [...items.value];

      const result = await addWatcher(RECORD_ID, OTHER_USER_ID);

      expect(before).toHaveLength(1);
      expect(result).toBeNull();
      expect(error.value).toBe(ACTION_ERROR);
      expect(items.value).toEqual(before);
    },
  );
});

describe('isFollowing(items, currentUserId)', () => {
  it.each<[string, WatcherItem[], boolean]>([
    [
      'returns true when currentUserId is among items[].user.id',
      [makeWatcher('u2', 'Kollege'), makeWatcher('u1', 'Ich')],
      true,
    ],
    ['returns false for an empty list', [], false],
    ['returns false when the currentUserId is not present', [makeWatcher('u2', 'Kollege')], false],
    ['ignores watchers whose user is null', [makeWatcher(null)], false],
  ])('%s', (_name, items, expected) => {
    expect(isFollowing(items, 'u1')).toBe(expected);
  });
});

describe('useWatchers composable — the reason the server gave', () => {
  it.each<[string, () => ServedRoute, (watchers: ReturnType<typeof useWatchers>) => Promise<unknown>, unknown, string]>(
    [
      [
        'follow(recordId) shows the message the server sent instead of a fixed sentence',
        () => serveJson(followRoute, { message: 'Sie dürfen diesen Datensatz nicht sehen.' }, { status: 403 }),
        (watchers) => watchers.follow(RECORD_ID),
        null,
        'Sie dürfen diesen Datensatz nicht sehen.',
      ],
      [
        'syncWatchers(recordId, userIds) shows the message the server sent',
        () =>
          serveJson(
            syncRoute,
            {
              message: 'Ein Benutzer gehört nicht zum Mandanten.',
              errors: { user_ids: ['egal'] },
            },
            { status: 422 },
          ),
        (watchers) => watchers.syncWatchers(RECORD_ID, ['u9']),
        false,
        'Ein Benutzer gehört nicht zum Mandanten.',
      ],
      [
        'syncWatchers(recordId, userIds) shows the validation message when only the field is named',
        () =>
          serveValidationError(syncRoute, {
            user_ids: ['Bitte wählen Sie gültige Benutzer.'],
          }),
        (watchers) => watchers.syncWatchers(RECORD_ID, ['u9']),
        false,
        'Bitte wählen Sie gültige Benutzer.',
      ],
      [
        'follow(recordId) keeps the German fallback when the refusal carries no message',
        () => serveServerError(followRoute, {}),
        (watchers) => watchers.follow(RECORD_ID),
        null,
        ACTION_ERROR,
      ],
      [
        'follow(recordId) keeps the German fallback when the connection fails',
        () => serveNetworkError(followRoute),
        (watchers) => watchers.follow(RECORD_ID),
        null,
        ACTION_ERROR,
      ],
      [
        'syncWatchers(recordId, userIds) keeps the German fallback when the connection fails',
        () => serveNetworkError(syncRoute),
        (watchers) => watchers.syncWatchers(RECORD_ID, ['u9']),
        false,
        ACTION_ERROR,
      ],
    ],
  )('%s', async (_name, serve, run, expectedResult, expectedError) => {
    serve();

    const watchers = useWatchers();

    await expect(run(watchers)).resolves.toBe(expectedResult);

    expect(watchers.error.value).toBe(expectedError);
  });
});

describe('useWatchers — replacing the watcher list', () => {
  it('PUTs the chosen user ids and takes the returned list as the new watchers', async () => {
    const served = serveJson(syncRoute, {
      data: [makeWatcher('u1', 'Ich', 'w-own'), makeWatcher(OTHER_USER_ID, 'Kollege', 'w-other')],
    });

    const watchers = useWatchers();

    const synced = await watchers.syncWatchers(RECORD_ID, ['u1', OTHER_USER_ID]);

    expect(synced).toBe(true);
    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/watchers`);
    expect(served.lastRequest()?.body).toEqual({
      user_ids: ['u1', OTHER_USER_ID],
    });
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(watchers.items.value.map((item) => item.id)).toEqual(['w-own', 'w-other']);
    expect(watchers.error.value).toBeNull();
  });
});
