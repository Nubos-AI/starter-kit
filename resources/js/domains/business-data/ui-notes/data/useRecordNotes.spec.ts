import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store as storeAction } from '@/actions/App/Http/Controllers/Notes/RecordNotesController';
import { serveEmpty, serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { useRecordNotes } from './useRecordNotes';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const RECORD_ID = '01RECORD00000000000000000A';

const route = storeAction({ record: RECORD_ID });

const saveError = 'Die Notiz konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

function noteResponse(body: string): {
  data: {
    id: string;
    body: string;
    author: { id: string; label: string };
    createdAt: string;
    updatedAt: string;
  };
} {
  return {
    data: {
      id: '01NOTE0000000000000000000B',
      body,
      author: { id: 'u1', label: 'Ada' },
      createdAt: '2026-08-24T10:00:00.000000Z',
      updatedAt: '2026-08-24T10:00:00.000000Z',
    },
  };
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useRecordNotes', () => {
  it('posts the body to the note endpoint of that record', async () => {
    const served = serveJson(route, noteResponse('Angerufen.'), {
      status: 201,
    });

    const { create } = useRecordNotes();

    await create(RECORD_ID, 'Angerufen.');

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/records/${RECORD_ID}/notes`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.body).toEqual({ body: 'Angerufen.' });
  });

  it('returns the stored note so the caller can show it straight away', async () => {
    serveJson(route, noteResponse('Angerufen.'), { status: 201 });

    const { create, error } = useRecordNotes();
    const note = await create(RECORD_ID, 'Angerufen.');

    expect(note?.body).toBe('Angerufen.');
    expect(note?.author?.label).toBe('Ada');
    expect(note?.createdAt).toBe('2026-08-24T10:00:00.000000Z');
    expect(error.value).toBeNull();
  });

  it('rejects a whitespace-only body client-side without sending a request', async () => {
    const served = serveJson(route, noteResponse('x'), { status: 201 });

    const { create, error } = useRecordNotes();
    const note = await create(RECORD_ID, '   ');

    expect(note).toBeNull();
    expect(served.requests).toHaveLength(0);
    expect(error.value).toBe('Bitte geben Sie einen Text für die Notiz ein.');
  });

  it('trims the body before sending it', async () => {
    const served = serveJson(route, noteResponse('Angerufen.'), {
      status: 201,
    });

    const { create } = useRecordNotes();
    await create(RECORD_ID, '  Angerufen.  ');

    expect(served.lastRequest()?.body).toEqual({ body: 'Angerufen.' });
  });

  it('surfaces the server validation message for a body the server rejects', async () => {
    serveValidationError(route, {
      body: ['The body field is required.'],
    });

    const { create, error } = useRecordNotes();
    const note = await create(RECORD_ID, 'x');

    expect(note).toBeNull();
    expect(error.value).toBe('The body field is required.');
  });

  it('falls back to the generic notice when the server fails without a body', async () => {
    serveEmpty(route, { status: 500 });

    const { create, error, saving } = useRecordNotes();
    const note = await create(RECORD_ID, 'Angerufen.');

    expect(note).toBeNull();
    expect(error.value).toBe(saveError);
    expect(saving.value).toBe(false);
  });

  it('reports a transport failure without throwing at the caller', async () => {
    serveNetworkError(route);

    const { create, error, saving } = useRecordNotes();
    const note = await create(RECORD_ID, 'Angerufen.');

    expect(note).toBeNull();
    expect(error.value).toBe(saveError);
    expect(saving.value).toBe(false);
  });

  it('marks the note as saving until the server answers', async () => {
    const served = serveJson(route, noteResponse('Angerufen.'), {
      status: 201,
      held: true,
    });

    const { create, saving } = useRecordNotes();
    const creating = create(RECORD_ID, 'Angerufen.');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(saving.value).toBe(true);

    served.release();
    await creating;

    expect(saving.value).toBe(false);
  });
});
