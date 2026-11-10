import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    archive as archiveAction,
    destroy as destroyAction,
    index as inboxIndexAction,
    snooze as snoozeAction,
    unreadCount as unreadCountAction,
} from '@/actions/App/Http/Controllers/Notifications/InboxesController';
import {
    resetNotificationsStateForTests,
    useNotifications,
} from '@/composables/useNotifications';

const MAX_CONSECUTIVE_FAILURES = 5;

const INBOX_ID = '01INBOX0K5N3Q8V9WYE6M2H7CO';

const { formSubmitSpy, patchSpy, postSpy, deleteSpy } = vi.hoisted(() => ({
    formSubmitSpy: vi.fn(),
    patchSpy: vi.fn(),
    postSpy: vi.fn(),
    deleteSpy: vi.fn(),
}));

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

const { authState } = vi.hoisted(() => ({
    authState: {
        user: { id: 'u1' },
        can: {},
        authority: null as string | null,
    },
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, ref } = await import('vue');

    const Form = defineComponent({
        name: 'FormStub',
        props: {
            action: { type: String, default: '' },
            method: { type: String, default: 'get' },
        },
        setup(props, { slots }) {
            const root = ref<HTMLFormElement | null>(null);

            const onSubmit = (event: Event): void => {
                event.preventDefault();
                const data: Record<string, unknown> = {};
                const element = root.value;

                if (element) {
                    element
                        .querySelectorAll<HTMLInputElement>('[name]')
                        .forEach((node) => {
                            data[node.name] = node.value;
                        });
                }

                formSubmitSpy(props.action, props.method, data);
            };

            return () =>
                h('form', { ref: root, onSubmit }, [
                    slots.default
                        ? slots.default({ errors: {}, processing: false })
                        : [],
                ]);
        },
    });

    const Head = defineComponent({
        name: 'HeadStub',
        setup:
            (_props, { slots }) =>
            () =>
                slots.default ? slots.default() : null,
    });

    const Link = defineComponent({
        name: 'LinkStub',
        setup:
            (_props, { slots }) =>
            () =>
                h('a', {}, slots.default ? slots.default() : []),
    });

    return {
        Form,
        Head,
        Link,
        router: {
            on: vi.fn(() => vi.fn()),
            patch: patchSpy,
            post: postSpy,
            delete: deleteSpy,
            visit: vi.fn(),
            reload: vi.fn(),
        },
        usePage: () => ({
            props: {
                vapidPublicKey: 'BTestVapidPublicKey',
                auth: authState,
            },
        }),
        useForm: (data: Record<string, unknown>) => ({ ...data }),
    };
});

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

afterEach(() => {
    resetNotificationsStateForTests();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useNotifications composable (poll, inbox actions, backoff)', () => {
    it('polls the unread-count endpoint and reflects the returned count', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(200, { count: 7 })),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { unreadCount, startPolling, stopPolling } = useNotifications();

        startPolling();
        await flushPromises();

        try {
            expect(fetchMock).toHaveBeenCalledWith(
                unreadCountAction.url(),
                expect.objectContaining({ method: 'GET' }),
            );
            expect(unreadCount.value).toBe(7);
        } finally {
            stopPolling();
        }
    });

    it('snooze() PATCHes the snooze endpoint with snoozed_until, archive() PATCHes the archive endpoint', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() => Promise.resolve(jsonResponse(200, { id: INBOX_ID })));
        vi.stubGlobal('fetch', fetchMock);

        const { snooze, archive } = useNotifications();
        const snoozedUntil = '2026-07-07T09:00:00.000Z';

        await snooze(INBOX_ID, snoozedUntil);
        await flushPromises();

        const snoozeCall = fetchMock.mock.calls.find(
            (call) => call[0] === snoozeAction.url({ inbox: INBOX_ID }),
        );
        expect(snoozeCall).toBeTruthy();
        expect(snoozeCall?.[1]).toEqual(
            expect.objectContaining({ method: 'PATCH' }),
        );
        expect(
            JSON.parse((snoozeCall?.[1] as RequestInit).body as string),
        ).toMatchObject({ snoozed_until: snoozedUntil });

        await archive(INBOX_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            archiveAction.url({ inbox: INBOX_ID }),
            expect.objectContaining({ method: 'PATCH' }),
        );
    });

    it('shares one unread-count across callers and refreshes it immediately on markRead', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >((url) => {
            if (String(url).includes('unread-count')) {
                return Promise.resolve(jsonResponse(200, { count: 0 }));
            }

            return Promise.resolve(jsonResponse(200, { id: INBOX_ID }));
        });
        vi.stubGlobal('fetch', fetchMock);

        const bell = useNotifications();
        const inbox = useNotifications();

        expect(inbox.unreadCount).toBe(bell.unreadCount);

        bell.unreadCount.value = 3;

        await inbox.markRead(INBOX_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            unreadCountAction.url(),
            expect.objectContaining({ method: 'GET' }),
        );
        expect(bell.unreadCount.value).toBe(0);
    });

    it('remove() DELETEs the destroy endpoint and drops the row from the list', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >((url) => {
            if (String(url).includes(inboxIndexAction.url())) {
                return Promise.resolve(
                    jsonResponse(200, {
                        data: [
                            {
                                id: INBOX_ID,
                                type: 'record.assigned',
                                data: {},
                                priority: 'normal',
                                readAt: null,
                                snoozedUntil: null,
                                archivedAt: null,
                                createdAt: '2026-07-06T08:00:00.000Z',
                            },
                        ],
                        meta: {
                            currentPage: 1,
                            lastPage: 1,
                            perPage: 25,
                            total: 1,
                        },
                    }),
                );
            }

            return Promise.resolve(jsonResponse(200, { id: INBOX_ID }));
        });
        vi.stubGlobal('fetch', fetchMock);

        const { items, load, remove } = useNotifications();

        await load('messages');
        await flushPromises();
        expect(items.value).toHaveLength(1);

        await remove(INBOX_ID);
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            destroyAction.url({ inbox: INBOX_ID }),
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(items.value).toHaveLength(0);
    });

    it('stops polling and reports failed after MAX_CONSECUTIVE_FAILURES rejections (no poll storm)', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(() =>
            Promise.reject(new Error('network down')),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { status, startPolling, stopPolling } = useNotifications();

        try {
            startPolling();
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(fetchMock).toHaveBeenCalledTimes(MAX_CONSECUTIVE_FAILURES);
            expect(status.value).toBe('failed');

            const plateau = fetchMock.mock.calls.length;
            await vi.advanceTimersByTimeAsync(60 * 60 * 1000);

            expect(fetchMock.mock.calls.length).toBe(plateau);
        } finally {
            stopPolling();
        }
    });

    it('load() GETs the inbox endpoint and normalises the returned items', async () => {
        const fetchMock = vi.fn<
            (url: string, init?: RequestInit) => Promise<Response>
        >(() =>
            Promise.resolve(
                jsonResponse(200, {
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
                }),
            ),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { items, load } = useNotifications();

        await load();
        await flushPromises();

        const requestedUrl = String(fetchMock.mock.calls.at(-1)?.[0]);
        expect(requestedUrl).toContain(inboxIndexAction.url());
        expect(fetchMock.mock.calls.at(-1)?.[1]).toEqual(
            expect.objectContaining({ method: 'GET' }),
        );
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
    });

    it('remove(inbox) keeps the item in the list when the server refuses the removal', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn((_url: string, init?: RequestInit) =>
                Promise.resolve(
                    init?.method === 'DELETE'
                        ? jsonResponse(422, {
                              message: 'Die Benachrichtigung ist noch geplant.',
                          })
                        : jsonResponse(200, {
                              count: 1,
                              data: [{ id: INBOX_ID, type: 'record.assigned' }],
                              meta: {},
                          }),
                ),
            ),
        );

        const notifications = useNotifications();

        await notifications.load();
        await flushPromises();
        await notifications.remove(INBOX_ID);
        await flushPromises();

        expect(notifications.items.value.map((item) => item.id)).toEqual([
            INBOX_ID,
        ]);
    });
});

describe('useNotifications — the reason the server gave', () => {
    it.each<
        [
            string,
            Response,
            (
                notifications: ReturnType<typeof useNotifications>,
            ) => Promise<unknown>,
            string,
        ]
    >([
        [
            'markRead(inbox) shows the message the server sent instead of a fixed sentence',
            jsonResponse(403, {
                message: 'Diese Benachrichtigung gehört Ihnen nicht.',
            }),
            (notifications) => notifications.markRead(INBOX_ID),
            'Diese Benachrichtigung gehört Ihnen nicht.',
        ],
        [
            'remove(inbox) shows the message the server sent and keeps the item',
            jsonResponse(422, {
                message: 'Die Benachrichtigung ist noch geplant.',
                errors: { state: ['egal'] },
            }),
            (notifications) => notifications.remove(INBOX_ID),
            'Die Benachrichtigung ist noch geplant.',
        ],
        [
            'markRead(inbox) keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            (notifications) => notifications.markRead(INBOX_ID),
            'Die Aktion konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.',
        ],
    ])('%s', async (_name, response, run, expectedError) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(response)),
        );

        const notifications = useNotifications();

        await run(notifications);
        await flushPromises();

        expect(notifications.error.value).toBe(expectedError);
    });
});
