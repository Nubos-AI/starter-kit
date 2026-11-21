import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  bulkDestroy as bulkDestroyAction,
  complete as completeAction,
  destroy as destroyAction,
  forRecord as forRecordAction,
  myOpen as myOpenAction,
  store as storeAction,
  update as updateAction,
} from '@/actions/App/Http/Controllers/Reminders/ReminderTasksController';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { isOverdue, useReminders } from './useReminders';
import type { ReminderItem } from './useReminders';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const REMINDER_ID = '01REMIND0K5N3Q8V9WYE6M2H7C';

const RECORD_ID = '01RECORD0K5N3Q8V9WYE6M2H7X';

const ACTION_ERROR = 'Die Erinnerung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

const LOAD_ERROR = 'Die Erinnerungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

function expectXsrf(served: ServedRoute): void {
  expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
  expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useReminders composable (load, sort, create guard, complete)', () => {
  it('loadMyOpen() loads and sorts items ascending by dueAt with null-dueAt last', async () => {
    const served = serveJson(myOpenAction(), {
      data: [
        {
          id: 'r-later',
          subject: 'Angebot nachfassen',
          note: null,
          type: 'call',
          dueAt: '2026-07-10T10:00:00.000Z',
          doneAt: null,
          owner: null,
          assignee: null,
          record: null,
        },
        {
          id: 'r-nodue',
          subject: 'No due date',
          note: null,
          type: null,
          dueAt: null,
          doneAt: null,
          owner: null,
          assignee: null,
          record: null,
        },
        {
          id: 'r-early',
          subject: 'Call back',
          note: null,
          type: 'call',
          dueAt: '2026-07-08T10:00:00.000Z',
          doneAt: null,
          owner: null,
          assignee: null,
          record: null,
        },
        {
          id: 'r-mid',
          subject: 'E-Mail senden',
          note: null,
          type: 'email',
          dueAt: '2026-07-09T10:00:00.000Z',
          doneAt: null,
          owner: null,
          assignee: null,
          record: null,
        },
      ],
    });

    const { items, error, loadMyOpen } = useReminders();

    await loadMyOpen();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/reminders/my-open');
    expectXsrf(served);
    expect(items.value.map((item) => item.id)).toEqual(['r-early', 'r-mid', 'r-later', 'r-nodue']);
    expect(error.value).toBeNull();
  });

  it('loadForRecord(id) reads the reminders of that record', async () => {
    const served = serveJson(forRecordAction({ record: RECORD_ID }), {
      data: [{ id: 'r-1', subject: 'Anrufen', dueAt: null }],
    });

    const { items, loadForRecord } = useReminders();

    await loadForRecord(RECORD_ID);

    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/reminders/for-record/${RECORD_ID}`);
    expect(items.value.map((item) => item.id)).toEqual(['r-1']);
  });

  it('marks the list as loading until the server answers', async () => {
    const served = serveJson(myOpenAction(), { data: [] }, { held: true });

    const { loading, loadMyOpen } = useReminders();
    const loadingList = loadMyOpen();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingList;

    expect(loading.value).toBe(false);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(myOpenAction())],
    ['the connection fails', () => serveNetworkError(myOpenAction())],
  ])('names the failure and keeps the list when %s', async (_name, serve) => {
    serve();

    const { items, error, loading, loadMyOpen } = useReminders();

    await loadMyOpen();

    expect(items.value).toEqual([]);
    expect(error.value).toBe(LOAD_ERROR);
    expect(loading.value).toBe(false);
  });

  it('keeps the quick links the server resolved and drops a link without a target', async () => {
    const mailLink = {
      key: 'email_message',
      id: '01MAIL00000000000000000000',
      label: 'E-Mail: Angebot',
      href: '/nubos/mails/01MAIL00000000000000000000',
    };
    serveJson(myOpenAction(), {
      data: [
        {
          id: 'r-linked',
          subject: 'Angebot nachfassen',
          dueAt: null,
          links: [mailLink, { key: 'record', id: 'x', label: 'C-1' }, 'not a link'],
        },
        { id: 'r-bare', subject: 'Ohne Bezug', dueAt: null },
      ],
    });

    const { items, loadMyOpen } = useReminders();

    await loadMyOpen();

    expect(items.value.map((item) => item.links)).toEqual([[mailLink], []]);
  });

  it('create(input) posts the trimmed subject and returns the stored reminder', async () => {
    const served = serveJson(storeAction(), { data: { id: REMINDER_ID, subject: 'Nachfassen' } }, { status: 201 });

    const reminders = useReminders();
    const created = await reminders.create({
      subject: '  Nachfassen  ',
      due_at: '2026-07-10T10:00:00.000Z',
      record_id: RECORD_ID,
    });

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/reminders');
    expectXsrf(served);
    expect(served.lastRequest()?.body).toEqual({
      subject: 'Nachfassen',
      due_at: '2026-07-10T10:00:00.000Z',
      record_id: RECORD_ID,
    });
    expect(created?.id).toBe(REMINDER_ID);
    expect(reminders.error.value).toBeNull();
  });

  it('update(id, input) puts the trimmed subject to the reminder', async () => {
    const served = serveJson(updateAction({ reminder: REMINDER_ID }), {
      data: { id: REMINDER_ID, subject: 'Nachfassen' },
    });

    const reminders = useReminders();
    const updated = await reminders.update(REMINDER_ID, {
      subject: ' Nachfassen ',
    });

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/reminders/${REMINDER_ID}`);
    expect(served.lastRequest()?.body).toEqual({ subject: 'Nachfassen' });
    expect(updated?.subject).toBe('Nachfassen');
  });

  it.each<[string, (reminders: ReturnType<typeof useReminders>) => Promise<unknown>]>([
    ['create', (reminders) => reminders.create({ subject: '   ' })],
    ['update', (reminders) => reminders.update(REMINDER_ID, { subject: '   ' })],
  ])('%s({ subject: whitespace }) is rejected client-side without sending a request', async (_operation, run) => {
    const stored = serveJson(storeAction(), { data: {} });
    const updated = serveJson(updateAction({ reminder: REMINDER_ID }), {
      data: {},
    });

    const reminders = useReminders();

    const result = await run(reminders);

    expect(result).toBeNull();
    expect(reminders.error.value).toBe(ACTION_ERROR);
    expect(stored.requests).toHaveLength(0);
    expect(updated.requests).toHaveLength(0);
  });

  it('destroy(id) calls the destroy endpoint with method DELETE', async () => {
    const served = serveEmpty(destroyAction({ reminder: REMINDER_ID }));

    const { destroy, error } = useReminders();

    await expect(destroy(REMINDER_ID)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/reminders/${REMINDER_ID}`);
    expectXsrf(served);
    expect(error.value).toBeNull();
  });

  it('bulkDestroy(ids) posts the ids to the bulk endpoint', async () => {
    const served = serveEmpty(bulkDestroyAction());

    const { bulkDestroy } = useReminders();

    await expect(bulkDestroy([REMINDER_ID, 'r-2'])).resolves.toBe(true);

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/reminders/bulk-delete');
    expect(served.lastRequest()?.body).toEqual({
      ids: [REMINDER_ID, 'r-2'],
    });
  });

  it('bulkDestroy([]) succeeds without sending a request', async () => {
    const served = serveEmpty(bulkDestroyAction());

    const { bulkDestroy } = useReminders();

    await expect(bulkDestroy([])).resolves.toBe(true);

    expect(served.requests).toHaveLength(0);
  });

  it('complete(id) PATCHes the complete endpoint with no request body', async () => {
    const served = serveJson(completeAction({ reminder: REMINDER_ID }), {
      data: { id: REMINDER_ID, doneAt: '2026-07-09T12:00:00.000Z' },
    });

    const { complete } = useReminders();

    const completed = await complete(REMINDER_ID);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PATCH');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/reminders/${REMINDER_ID}/complete`);
    expect(served.lastRequest()?.body).toBeNull();
    expect(served.lastRequest()?.headers.get('Content-Type')).toBeNull();
    expect(completed?.doneAt).toBe('2026-07-09T12:00:00.000Z');
  });
});

describe('isOverdue(item, now)', () => {
  const NOW = new Date('2026-07-09T12:00:00.000Z').getTime();

  function makeItem(overrides: Partial<ReminderItem>): ReminderItem {
    return {
      id: REMINDER_ID,
      subject: 'Angebot nachfassen',
      note: null,
      type: null,
      dueAt: null,
      doneAt: null,
      owner: null,
      assignee: null,
      record: null,
      links: [],
      ...overrides,
    };
  }

  it.each<[string, Partial<ReminderItem>, boolean]>([
    [
      'returns true when due in the past, not done, and now is after due',
      { dueAt: '2026-07-08T10:00:00.000Z', doneAt: null },
      true,
    ],
    [
      'returns false when doneAt is set, even if past due',
      {
        dueAt: '2026-07-08T10:00:00.000Z',
        doneAt: '2026-07-08T11:00:00.000Z',
      },
      false,
    ],
    ['returns false when dueAt is null', { dueAt: null, doneAt: null }, false],
    ['returns false when dueAt is in the future', { dueAt: '2026-07-10T10:00:00.000Z', doneAt: null }, false],
  ])('%s', (_name, overrides, expected) => {
    expect(isOverdue(makeItem(overrides), NOW)).toBe(expected);
  });
});

describe('useReminders — the reason the server gave', () => {
  it.each<
    [string, () => ServedRoute, (reminders: ReturnType<typeof useReminders>) => Promise<unknown>, unknown, string]
  >([
    [
      'update(id, input) shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          updateAction({ reminder: REMINDER_ID }),
          {
            message: 'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
            errors: { due_at: ['egal'] },
          },
          { status: 422 },
        ),
      (reminders) => reminders.update(REMINDER_ID, { subject: 'Nachfassen' }),
      null,
      'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
    ],
    [
      'create(input) shows the validation message the server sent',
      () =>
        serveValidationError(storeAction(), {
          subject: ['Der Betreff ist zu lang.'],
        }),
      (reminders) => reminders.create({ subject: 'Nachfassen' }),
      null,
      'Der Betreff ist zu lang.',
    ],
    [
      'destroy(id) shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          destroyAction({ reminder: REMINDER_ID }),
          { message: 'Sie dürfen diese Erinnerung nicht löschen.' },
          { status: 403 },
        ),
      (reminders) => reminders.destroy(REMINDER_ID),
      false,
      'Sie dürfen diese Erinnerung nicht löschen.',
    ],
    [
      'bulkDestroy(ids) shows the message the server sent instead of a fixed sentence',
      () => serveJson(bulkDestroyAction(), { message: 'Mindestens eine Erinnerung ist geschützt.' }, { status: 422 }),
      (reminders) => reminders.bulkDestroy([REMINDER_ID]),
      false,
      'Mindestens eine Erinnerung ist geschützt.',
    ],
    [
      'destroy(id) keeps the German fallback when the refusal carries no message',
      () => serveServerError(destroyAction({ reminder: REMINDER_ID }), {}),
      (reminders) => reminders.destroy(REMINDER_ID),
      false,
      ACTION_ERROR,
    ],
    [
      'complete(id) keeps the German fallback when the connection fails',
      () => serveNetworkError(completeAction({ reminder: REMINDER_ID })),
      (reminders) => reminders.complete(REMINDER_ID),
      null,
      ACTION_ERROR,
    ],
    [
      'destroy(id) keeps the German fallback when the connection fails',
      () => serveNetworkError(destroyAction({ reminder: REMINDER_ID })),
      (reminders) => reminders.destroy(REMINDER_ID),
      false,
      ACTION_ERROR,
    ],
    [
      'bulkDestroy(ids) keeps the German fallback when the connection fails',
      () => serveNetworkError(bulkDestroyAction()),
      (reminders) => reminders.bulkDestroy([REMINDER_ID]),
      false,
      ACTION_ERROR,
    ],
  ])('%s', async (_name, serve, run, expectedResult, expectedError) => {
    serve();

    const reminders = useReminders();

    await expect(run(reminders)).resolves.toBe(expectedResult);

    expect(reminders.error.value).toBe(expectedError);
  });
});
