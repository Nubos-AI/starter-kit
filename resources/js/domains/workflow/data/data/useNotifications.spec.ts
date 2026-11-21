import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  archive as archiveAction,
  destroy as destroyAction,
  index as inboxIndexAction,
  markRead as markReadAction,
  markUnread as markUnreadAction,
  snooze as snoozeAction,
  unreadCount as unreadCountAction,
} from '@/actions/App/Http/Controllers/Notifications/InboxesController';
import { serveEmpty, serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { resetNotificationsStateForTests, useNotifications } from './useNotifications';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

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

setUrlDefaults({ activeTeam: 'nubos' });

const MAX_CONSECUTIVE_FAILURES = 5;

const POLL_INTERVAL_MS = 10000;

const INBOX_ID = '01INBOX0K5N3Q8V9WYE6M2H7CO';

const actionErrorMessage = 'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.';

const loadErrorMessage = 'Die Benachrichtigungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const unreadCountRoute = unreadCountAction();

const inboxRoute = inboxIndexAction();

const inboxItem = {
  id: INBOX_ID,
  type: 'record.assigned',
  data: {},
  priority: 'normal',
  readAt: null,
  snoozedUntil: null,
  archivedAt: null,
  createdAt: '2026-07-06T08:00:00.000Z',
};

function serveUnreadCount(count: number): ServedRoute {
  return serveJson(unreadCountRoute, { count });
}

function serveInbox(): ServedRoute {
  return serveJson(inboxRoute, {
    data: [inboxItem],
    meta: { currentPage: 1, lastPage: 1, perPage: 25, total: 1 },
  });
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  resetNotificationsStateForTests();
  vi.useRealTimers();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useNotifications composable (poll, inbox actions, backoff)', () => {
  it('polls the unread-count endpoint and reflects the returned count', async () => {
    const served = serveUnreadCount(7);

    const { unreadCount, startPolling, stopPolling } = useNotifications();

    startPolling();

    try {
      await vi.waitFor(() => expect(unreadCount.value).toBe(7));

      expect(served.requests).toHaveLength(1);
      expect(served.lastRequest()?.method).toBe('GET');
      expect(served.lastRequest()?.url.pathname).toBe('/nubos/notifications/inbox/unread-count');
      expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    } finally {
      stopPolling();
    }
  });

  it('snooze() PATCHes the snooze endpoint with snoozed_until, archive() PATCHes the archive endpoint', async () => {
    const unread = serveUnreadCount(0);
    const snoozed = serveJson(snoozeAction({ inbox: INBOX_ID }), {
      id: INBOX_ID,
    });
    const archived = serveJson(archiveAction({ inbox: INBOX_ID }), {
      id: INBOX_ID,
    });

    const { snooze, archive } = useNotifications();
    const snoozedUntil = '2026-07-07T09:00:00.000Z';

    await snooze(INBOX_ID, snoozedUntil);
    await flushPromises();

    expect(snoozed.requests).toHaveLength(1);
    expect(snoozed.lastRequest()?.method).toBe('PATCH');
    expect(snoozed.lastRequest()?.url.pathname).toBe(`/nubos/notifications/inbox/${INBOX_ID}/snooze`);
    expect(snoozed.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(snoozed.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(snoozed.lastRequest()?.body).toEqual({
      snoozed_until: snoozedUntil,
    });

    await archive(INBOX_ID);
    await flushPromises();

    expect(archived.requests).toHaveLength(1);
    expect(archived.lastRequest()?.method).toBe('PATCH');
    expect(archived.lastRequest()?.body).toBeNull();
    await vi.waitFor(() => expect(unread.requests).toHaveLength(2));
  });

  it('markUnread() PATCHes the unread endpoint and adopts the returned row', async () => {
    serveUnreadCount(1);
    serveInbox();
    const served = serveJson(markUnreadAction({ inbox: INBOX_ID }), {
      ...inboxItem,
      readAt: null,
      priority: 'high',
    });

    const notifications = useNotifications();

    await notifications.load();
    await notifications.markUnread(INBOX_ID);
    await flushPromises();

    expect(served.lastRequest()?.method).toBe('PATCH');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/notifications/inbox/${INBOX_ID}/unread`);
    expect(notifications.items.value[0]?.priority).toBe('high');
    expect(notifications.error.value).toBeNull();
  });

  it('shares one unread-count across callers and refreshes it immediately on markRead', async () => {
    const unread = serveUnreadCount(0);
    serveJson(markReadAction({ inbox: INBOX_ID }), { id: INBOX_ID });

    const bell = useNotifications();
    const inbox = useNotifications();

    expect(inbox.unreadCount).toBe(bell.unreadCount);

    bell.unreadCount.value = 3;

    await inbox.markRead(INBOX_ID);

    await vi.waitFor(() => expect(bell.unreadCount.value).toBe(0));
    expect(unread.requests).toHaveLength(1);
    expect(unread.lastRequest()?.method).toBe('GET');
  });

  it('remove() DELETEs the destroy endpoint and drops the row from the list', async () => {
    const unread = serveUnreadCount(0);
    const listed = serveInbox();
    const removed = serveEmpty(destroyAction({ inbox: INBOX_ID }));

    const { items, load, remove } = useNotifications();

    await load('messages');
    await flushPromises();

    expect(listed.lastRequest()?.url.searchParams.get('state')).toBe('messages');
    expect(items.value).toHaveLength(1);

    await remove(INBOX_ID);
    await flushPromises();

    expect(removed.requests).toHaveLength(1);
    expect(removed.lastRequest()?.method).toBe('DELETE');
    expect(removed.lastRequest()?.url.pathname).toBe(`/nubos/notifications/inbox/${INBOX_ID}`);
    expect(items.value).toHaveLength(0);
    await vi.waitFor(() => expect(unread.requests).toHaveLength(2));
  });

  it('stops polling and reports failed after MAX_CONSECUTIVE_FAILURES rejections (no poll storm)', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const served = serveNetworkError(unreadCountRoute);

    const { status, startPolling, stopPolling } = useNotifications();

    try {
      startPolling();

      for (let attempt = 1; attempt <= MAX_CONSECUTIVE_FAILURES; attempt++) {
        await vi.waitFor(() => expect(served.requests).toHaveLength(attempt));
        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      }

      await vi.waitFor(() => expect(status.value).toBe('failed'));

      await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

      expect(served.requests).toHaveLength(MAX_CONSECUTIVE_FAILURES);
    } finally {
      stopPolling();
    }
  });

  it('keeps polling after a refused count and adopts the next one', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    serveUnreadCount(4);
    const refused = serveServerError(unreadCountRoute, {}, { once: true });

    const { status, unreadCount, startPolling, stopPolling } = useNotifications();

    try {
      startPolling();
      await vi.waitFor(() => expect(refused.requests).toHaveLength(1));

      expect(status.value).toBe('polling');
      expect(unreadCount.value).toBe(0);

      await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS);
      await vi.waitFor(() => expect(unreadCount.value).toBe(4));

      expect(status.value).toBe('polling');
    } finally {
      stopPolling();
    }
  });

  it('load() GETs the inbox endpoint and normalises the returned items', async () => {
    serveUnreadCount(0);
    const served = serveJson(inboxRoute, {
      data: [
        {
          id: 'inbox-1',
          type: 'record.assigned',
          data: { recordId: 'r1' },
          priority: 'normal',
          readAt: null,
          snoozedUntil: null,
          archivedAt: null,
          createdAt: '2026-07-06T08:00:00.000Z',
        },
        {
          id: 'inbox-2',
          type: 'mention.created',
          data: 'not an object',
        },
      ],
      meta: {
        currentPage: 1,
        lastPage: 1,
        perPage: 25,
        total: 2,
      },
    });

    const { items, meta, load } = useNotifications();

    await load();
    await flushPromises();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/notifications/inbox');
    expect(served.lastRequest()?.url.searchParams.has('state')).toBe(false);
    expect(items.value).toHaveLength(2);
    expect(items.value[0]).toMatchObject({
      id: 'inbox-1',
      type: 'record.assigned',
    });
    expect(items.value[1]).toMatchObject({
      id: 'inbox-2',
      type: 'mention.created',
      data: {},
      priority: 'normal',
      readAt: null,
      snoozedUntil: null,
      archivedAt: null,
    });
    expect(meta.value?.total).toBe(2);
  });

  it('is loading while the inbox is on its way', async () => {
    serveUnreadCount(0);
    const served = serveJson(inboxRoute, { data: [], meta: {} }, { held: true });

    const notifications = useNotifications();

    const loading = notifications.load();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(notifications.loading.value).toBe(true);

    served.release();
    await loading;

    expect(notifications.loading.value).toBe(false);
  });

  it.each<[string, (route: ReturnType<typeof inboxIndexAction>) => void]>([
    ['the server fails', (route) => serveServerError(route)],
    ['the network drops the request', (route) => serveNetworkError(route)],
  ])('says the inbox could not be loaded when %s', async (_label, failing) => {
    failing(inboxRoute);

    const notifications = useNotifications();

    await notifications.load();

    expect(notifications.error.value).toBe(loadErrorMessage);
    expect(notifications.items.value).toEqual([]);
    expect(notifications.loading.value).toBe(false);
  });

  it('remove(inbox) keeps the item in the list when the server refuses the removal', async () => {
    serveUnreadCount(1);
    serveInbox();
    serveJson(
      destroyAction({ inbox: INBOX_ID }),
      { message: 'Die Benachrichtigung ist noch geplant.' },
      { status: 422 },
    );

    const notifications = useNotifications();

    await notifications.load();
    await flushPromises();
    await notifications.remove(INBOX_ID);
    await flushPromises();

    expect(notifications.items.value.map((item) => item.id)).toEqual([INBOX_ID]);
  });
});

describe('useNotifications — the reason the server gave', () => {
  it.each<[string, () => void, (notifications: ReturnType<typeof useNotifications>) => Promise<unknown>, string]>([
    [
      'markRead(inbox) shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          markReadAction({ inbox: INBOX_ID }),
          { message: 'Diese Benachrichtigung gehört Ihnen nicht.' },
          { status: 403 },
        ),
      (notifications) => notifications.markRead(INBOX_ID),
      'Diese Benachrichtigung gehört Ihnen nicht.',
    ],
    [
      'remove(inbox) shows the message the server sent and keeps the item',
      () =>
        serveJson(
          destroyAction({ inbox: INBOX_ID }),
          {
            message: 'Die Benachrichtigung ist noch geplant.',
            errors: { state: ['egal'] },
          },
          { status: 422 },
        ),
      (notifications) => notifications.remove(INBOX_ID),
      'Die Benachrichtigung ist noch geplant.',
    ],
    [
      'markRead(inbox) keeps the German fallback when the refusal carries no message',
      () => serveServerError(markReadAction({ inbox: INBOX_ID }), {}),
      (notifications) => notifications.markRead(INBOX_ID),
      actionErrorMessage,
    ],
    [
      'archive(inbox) keeps the German fallback when the network drops the request',
      () => serveNetworkError(archiveAction({ inbox: INBOX_ID })),
      (notifications) => notifications.archive(INBOX_ID),
      actionErrorMessage,
    ],
    [
      'remove(inbox) keeps the German fallback when the network drops the request',
      () => serveNetworkError(destroyAction({ inbox: INBOX_ID })),
      (notifications) => notifications.remove(INBOX_ID),
      actionErrorMessage,
    ],
  ])('%s', async (_name, serveFailure, run, expectedError) => {
    serveFailure();

    const notifications = useNotifications();

    await run(notifications);
    await flushPromises();

    expect(notifications.error.value).toBe(expectedError);
  });
});
