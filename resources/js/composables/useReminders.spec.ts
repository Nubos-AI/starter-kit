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

    it('keeps the quick links the server resolved and drops a link without a target', async () => {
        const mailLink = {
            key: 'email_message',
            id: '01MAIL00000000000000000000',
            label: 'E-Mail: Angebot',
            href: '/nubos/mails/01MAIL00000000000000000000',
        };
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
                    data: [
                        {
                            id: 'r-linked',
                            subject: 'Angebot nachfassen',
                            dueAt: null,
                            links: [
                                mailLink,
                                { key: 'record', id: 'x', label: 'C-1' },
                                'not a link',
                            ],
                        },
                        { id: 'r-bare', subject: 'Ohne Bezug', dueAt: null },
                    ],
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, loadMyOpen } = useReminders();

        await loadMyOpen();
        await flushPromises();

        expect(items.value.map((item) => item.links)).toEqual([[mailLink], []]);
    });

    it.each<
        [
            string,
            (reminders: ReturnType<typeof useReminders>) => Promise<unknown>,
        ]
    >([
        ['create', (reminders) => reminders.create({ subject: '   ' })],
        [
            'update',
            (reminders) => reminders.update(REMINDER_ID, { subject: '   ' }),
        ],
    ])(
        '%s({ subject: whitespace }) is rejected client-side without calling fetch',
        async (_operation, run) => {
            const fetchMock = vi.fn<
                (url: string, init?: RequestInit) => Promise<Response>
            >(() => Promise.resolve(jsonResponse(200, { data: {} })));
            vi.stubGlobal('fetch', fetchMock);

            const reminders = useReminders();

            const result = await run(reminders);
            await flushPromises();

            expect(result).toBeNull();
            expect(reminders.error.value).toBe(
                'Die Erinnerung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
            );
            expect(fetchMock).not.toHaveBeenCalled();
        },
    );

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
        [
            'returns false when dueAt is null',
            { dueAt: null, doneAt: null },
            false,
        ],
        [
            'returns false when dueAt is in the future',
            { dueAt: '2026-07-10T10:00:00.000Z', doneAt: null },
            false,
        ],
    ])('%s', (_name, overrides, expected) => {
        expect(isOverdue(makeItem(overrides), NOW)).toBe(expected);
    });
});

describe('useReminders — the reason the server gave', () => {
    it.each<
        [
            string,
            Response,
            (reminders: ReturnType<typeof useReminders>) => Promise<unknown>,
            unknown,
            string,
        ]
    >([
        [
            'update(id, input) shows the message the server sent instead of a fixed sentence',
            jsonResponse(422, {
                message: 'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
                errors: { due_at: ['egal'] },
            }),
            (reminders) =>
                reminders.update(REMINDER_ID, { subject: 'Nachfassen' }),
            null,
            'Der Fälligkeitszeitpunkt liegt zu weit zurück.',
        ],
        [
            'destroy(id) shows the message the server sent instead of a fixed sentence',
            jsonResponse(403, {
                message: 'Sie dürfen diese Erinnerung nicht löschen.',
            }),
            (reminders) => reminders.destroy(REMINDER_ID),
            false,
            'Sie dürfen diese Erinnerung nicht löschen.',
        ],
        [
            'bulkDestroy(ids) shows the message the server sent instead of a fixed sentence',
            jsonResponse(422, {
                message: 'Mindestens eine Erinnerung ist geschützt.',
            }),
            (reminders) => reminders.bulkDestroy([REMINDER_ID]),
            false,
            'Mindestens eine Erinnerung ist geschützt.',
        ],
        [
            'destroy(id) keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            (reminders) => reminders.destroy(REMINDER_ID),
            false,
            'Die Erinnerung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.',
        ],
    ])('%s', async (_name, response, run, expectedResult, expectedError) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(response)),
        );

        const reminders = useReminders();

        await expect(run(reminders)).resolves.toBe(expectedResult);

        expect(reminders.error.value).toBe(expectedError);
    });
});
