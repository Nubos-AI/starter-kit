import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    complete as completeAction,
    destroy as destroyAction,
} from '@/actions/App/Http/Controllers/Reminders/ReminderTasksController';
import type { ReminderItem } from '@/composables/useReminders';
import { isOverdue, useReminders } from '@/composables/useReminders';

const REMINDER_ID = '01REMIND0K5N3Q8V9WYE6M2H7C';

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

describe('useReminders composable (load, sort, create guard, complete)', () => {
    it('loadMyOpen() loads and sorts items ascending by dueAt with null-dueAt last', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
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
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadMyOpen } = useReminders();

        await loadMyOpen();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(items.value.map((item) => item.id)).toEqual([
            'r-early',
            'r-mid',
            'r-later',
            'r-nodue',
        ]);
        expect(error.value).toBeNull();
    });

    it('create({ subject: whitespace }) is rejected client-side without calling fetch', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(201, { data: {} })));
        vi.stubGlobal('fetch', fetchMock);

        const { create } = useReminders();

        await create({ subject: '   ' });
        await flushPromises();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('update({ subject: whitespace }) is rejected client-side without calling fetch', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: {} })));
        vi.stubGlobal('fetch', fetchMock);

        const { update } = useReminders();

        const result = await update(REMINDER_ID, { subject: '   ' });
        await flushPromises();

        expect(result).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('destroy(id) calls the destroy endpoint with method DELETE', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, {})));
        vi.stubGlobal('fetch', fetchMock);

        const { destroy } = useReminders();

        await destroy(REMINDER_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            destroyAction.url({ reminder: REMINDER_ID }),
            expect.objectContaining({ method: 'DELETE' }),
        );
    });

    it('complete(id) PATCHes the complete endpoint with no request body', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(jsonResponse(200, { data: { id: REMINDER_ID } })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { complete } = useReminders();

        await complete(REMINDER_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            completeAction.url({ reminder: REMINDER_ID }),
            expect.objectContaining({ method: 'PATCH' }),
        );

        const completeCall = fetchMock.mock.calls.find(
            (call) => call[0] === completeAction.url({ reminder: REMINDER_ID }),
        );
        expect(completeCall).toBeTruthy();
        expect(
            (completeCall?.[1] as RequestInit | undefined)?.body,
        ).toBeUndefined();
    });

    it('loadMyOpen() yields an empty list and no error for an empty response', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { data: [] })));
        vi.stubGlobal('fetch', fetchMock);

        const { items, error, loadMyOpen } = useReminders();

        await loadMyOpen();
        await flushPromises();

        expect(items.value).toEqual([]);
        expect(error.value).toBeNull();
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
            ...overrides,
        };
    }

    it('returns true when due in the past, not done, and now is after due', () => {
        const item = makeItem({
            dueAt: '2026-07-08T10:00:00.000Z',
            doneAt: null,
        });

        expect(isOverdue(item, NOW)).toBe(true);
    });

    it('returns false when doneAt is set, even if past due', () => {
        const item = makeItem({
            dueAt: '2026-07-08T10:00:00.000Z',
            doneAt: '2026-07-08T11:00:00.000Z',
        });

        expect(isOverdue(item, NOW)).toBe(false);
    });

    it('returns false when dueAt is null', () => {
        const item = makeItem({ dueAt: null, doneAt: null });

        expect(isOverdue(item, NOW)).toBe(false);
    });

    it('returns false when dueAt is in the future', () => {
        const item = makeItem({
            dueAt: '2026-07-10T10:00:00.000Z',
            doneAt: null,
        });

        expect(isOverdue(item, NOW)).toBe(false);
    });
});

describe('useReminders — the reason the server gave', () => {
    it('update(id, input) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message:
                            'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
                        errors: { due_at: ['egal'] },
                    }),
                ),
            ),
        );

        const { update, error } = useReminders();

        await expect(
            update(REMINDER_ID, { subject: 'Nachfassen' }),
        ).resolves.toBeNull();

        expect(error.value).toBe(
            'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
        );
    });

    it('destroy(id) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(403, {
                        message: 'Sie dürfen diese Erinnerung nicht löschen.',
                    }),
                ),
            ),
        );

        const { destroy, error } = useReminders();

        await expect(destroy(REMINDER_ID)).resolves.toBe(false);

        expect(error.value).toBe('Sie dürfen diese Erinnerung nicht löschen.');
    });

    it('bulkDestroy(ids) shows the message the server sent instead of a fixed sentence', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    jsonResponse(422, {
                        message: 'Mindestens eine Erinnerung ist geschützt.',
                    }),
                ),
            ),
        );

        const { bulkDestroy, error } = useReminders();

        await expect(bulkDestroy([REMINDER_ID])).resolves.toBe(false);

        expect(error.value).toBe('Mindestens eine Erinnerung ist geschützt.');
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(jsonResponse(500, {}))),
        );

        const { destroy, error } = useReminders();

        await expect(destroy(REMINDER_ID)).resolves.toBe(false);

        expect(error.value).toBe(
            'Die Erinnerung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        );
    });
});
