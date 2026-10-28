import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store as storeAction } from '@/actions/App/Http/Controllers/Notes/RecordNotesController';
import { useRecordNotes } from '@/composables/useRecordNotes';

const RECORD_ID = '01RECORD00000000000000000A';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 'u1' } } } }),
}));

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function noteResponse(body: string): unknown {
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
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=test-xsrf-token',
    } as unknown as Document);
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useRecordNotes', () => {
    it('posts the body to the note endpoint of that record', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(201, noteResponse('Angerufen.'))));
        vi.stubGlobal('fetch', fetchMock);

        const { create } = useRecordNotes();

        await create(RECORD_ID, 'Angerufen.');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            storeAction.url({ record: RECORD_ID }),
            expect.objectContaining({ method: 'POST' }),
        );

        const call = fetchMock.mock.calls[0];
        expect(
            JSON.parse(String((call?.[1] as RequestInit | undefined)?.body)),
        ).toEqual({ body: 'Angerufen.' });
    });

    it('returns the stored note so the caller can show it straight away', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(jsonResponse(201, noteResponse('Angerufen.'))),
            ),
        );

        const { create } = useRecordNotes();
        const note = await create(RECORD_ID, 'Angerufen.');

        expect(note?.body).toBe('Angerufen.');
        expect(note?.author?.label).toBe('Ada');
        expect(note?.createdAt).toBe('2026-08-24T10:00:00.000000Z');
    });

    it('rejects a whitespace-only body client-side without calling fetch', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const { create, error } = useRecordNotes();
        const note = await create(RECORD_ID, '   ');

        expect(note).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(error.value).not.toBeNull();
    });

    it('trims the body before sending it', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(201, noteResponse('Angerufen.'))));
        vi.stubGlobal('fetch', fetchMock);

        const { create } = useRecordNotes();
        await create(RECORD_ID, '  Angerufen.  ');

        expect(
            JSON.parse(
                String(
                    (fetchMock.mock.calls[0]?.[1] as RequestInit | undefined)
                        ?.body,
                ),
            ),
        ).toEqual({ body: 'Angerufen.' });
    });

    it('surfaces the server validation message for a body the server rejects', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'The body field is required.',
                        errors: { body: ['The body field is required.'] },
                    }),
                ),
            ),
        );

        const { create, error } = useRecordNotes();
        const note = await create(RECORD_ID, 'x');

        expect(note).toBeNull();
        expect(error.value).toBe('The body field is required.');
    });

    it('reports a transport failure without throwing at the caller', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('offline'))),
        );
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const { create, error } = useRecordNotes();
        const note = await create(RECORD_ID, 'Angerufen.');

        expect(note).toBeNull();
        expect(error.value).not.toBeNull();
    });
});
