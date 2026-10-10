import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    destroy as destroyPushAction,
    store as storePushAction,
} from '@/actions/App/Http/Controllers/Notifications/PushSubscriptionsController';
import { usePushSubscription } from '@/composables/usePushSubscription';

const { pageState } = vi.hoisted(() => ({
    pageState: { vapidPublicKey: '' as unknown },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageState }),
}));

interface PushSubscriptionMock {
    endpoint: string;
    toJSON: () => { keys: { p256dh: string; auth: string } };
    unsubscribe: ReturnType<typeof vi.fn>;
}

interface SupportedEnv {
    registerMock: ReturnType<typeof vi.fn>;
    subscribeMock: ReturnType<typeof vi.fn>;
    getSubscriptionMock: ReturnType<typeof vi.fn>;
    fetchMock: ReturnType<typeof vi.fn>;
    subscription: PushSubscriptionMock;
    requestPermissionMock: ReturnType<typeof vi.fn>;
}

function installSupportedEnv(options: {
    existingSubscription: PushSubscriptionMock | null;
    permission?: NotificationPermission;
}): SupportedEnv {
    const subscription: PushSubscriptionMock = {
        endpoint: 'https://push.example.test/endpoint-1',
        toJSON: () => ({ keys: { p256dh: 'p256dh-key', auth: 'auth-key' } }),
        unsubscribe: vi.fn(() => Promise.resolve(true)),
    };

    const returnedSubscription = options.existingSubscription ?? subscription;

    const getSubscriptionMock = vi.fn(() =>
        Promise.resolve(options.existingSubscription),
    );
    const subscribeMock = vi.fn(() => Promise.resolve(returnedSubscription));
    const registration = {
        pushManager: {
            getSubscription: getSubscriptionMock,
            subscribe: subscribeMock,
        },
    };

    const registerMock = vi.fn(() => Promise.resolve(registration));
    const getRegistrationMock = vi.fn(() => Promise.resolve(registration));

    vi.stubGlobal('navigator', {
        serviceWorker: {
            register: registerMock,
            ready: Promise.resolve(registration),
            getRegistration: getRegistrationMock,
        },
    });

    vi.stubGlobal(
        'PushManager',
        Object.assign(vi.fn(), {
            supportedContentEncodings: ['aes128gcm'],
        }),
    );

    const requestPermissionMock = vi.fn(() =>
        Promise.resolve(options.permission ?? 'granted'),
    );

    vi.stubGlobal(
        'Notification',
        Object.assign(vi.fn(), {
            permission: 'default' as NotificationPermission,
            requestPermission: requestPermissionMock,
        }),
    );

    const fetchMock = vi.fn(
        () =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: async () => ({}),
            }) as unknown as Promise<Response>,
    );
    vi.stubGlobal('fetch', fetchMock);

    return {
        registerMock,
        subscribeMock,
        getSubscriptionMock,
        fetchMock,
        subscription: returnedSubscription,
        requestPermissionMock,
    };
}

function refuse(env: SupportedEnv, status: number, body: unknown): void {
    env.fetchMock.mockImplementation(
        () =>
            Promise.resolve({
                ok: false,
                status,
                json: async () => {
                    if (body === undefined) {
                        throw new SyntaxError('no body');
                    }

                    return body;
                },
            }) as unknown as Promise<Response>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('usePushSubscription (feature guards, subscribe, unsubscribe)', () => {
    it('reports unsupported and degrades gracefully when Push APIs are absent', async () => {
        pageState.vapidPublicKey = 'AQID';

        const { supported, permission, subscribe, unsubscribe } =
            usePushSubscription();

        expect(supported.value).toBe(false);
        expect(permission.value).toBe('unsupported');

        await expect(subscribe()).resolves.toBe(false);
        await expect(unsubscribe()).resolves.toBe(false);
    });

    it('decodes the base64url VAPID key and subscribes with the exact applicationServerKey bytes', async () => {
        pageState.vapidPublicKey = '-_wA';
        const env = installSupportedEnv({ existingSubscription: null });

        const { subscribe, subscribed } = usePushSubscription();

        const result = await subscribe();
        await flushPromises();

        expect(result).toBe(true);
        expect(subscribed.value).toBe(true);
        expect(env.registerMock).toHaveBeenCalledWith('/sw.js');

        expect(env.subscribeMock).toHaveBeenCalledTimes(1);
        const subscribeOptions = env.subscribeMock.mock.calls[0][0] as {
            userVisibleOnly: boolean;
            applicationServerKey: Uint8Array;
        };
        expect(subscribeOptions.userVisibleOnly).toBe(true);
        expect(Array.from(subscribeOptions.applicationServerKey)).toEqual([
            251, 252, 0,
        ]);
    });

    it('POSTs the subscription payload to the push store endpoint after permission is granted', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({ existingSubscription: null });

        const { subscribe } = usePushSubscription();

        await subscribe();
        await flushPromises();

        expect(env.requestPermissionMock).toHaveBeenCalledTimes(1);

        const storeCall = env.fetchMock.mock.calls.find(
            (call) => call[0] === storePushAction.url(),
        );
        expect(storeCall).toBeTruthy();
        expect(storeCall?.[1]).toEqual(
            expect.objectContaining({ method: 'POST' }),
        );
        expect(
            JSON.parse((storeCall?.[1] as RequestInit).body as string),
        ).toMatchObject({
            endpoint: 'https://push.example.test/endpoint-1',
            public_key: 'p256dh-key',
            auth_token: 'auth-key',
        });
    });

    it('does not POST and reports an error when permission is denied', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({
            existingSubscription: null,
            permission: 'denied',
        });

        const { subscribe, error, permission } = usePushSubscription();

        const result = await subscribe();
        await flushPromises();

        expect(result).toBe(false);
        expect(permission.value).toBe('denied');
        expect(error.value).not.toBeNull();
        expect(env.subscribeMock).not.toHaveBeenCalled();
        expect(env.fetchMock).not.toHaveBeenCalled();
    });

    it('unsubscribe() DELETEs the destroy endpoint and tears down the browser subscription', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({
            existingSubscription: {
                endpoint: 'https://push.example.test/endpoint-1',
                toJSON: () => ({
                    keys: { p256dh: 'p256dh-key', auth: 'auth-key' },
                }),
                unsubscribe: vi.fn(() => Promise.resolve(true)),
            },
        });

        const { unsubscribe, subscribed } = usePushSubscription();

        const result = await unsubscribe();
        await flushPromises();

        expect(result).toBe(true);
        expect(subscribed.value).toBe(false);
        expect(env.subscription.unsubscribe).toHaveBeenCalledTimes(1);

        const destroyCall = env.fetchMock.mock.calls.find(
            (call) => call[0] === destroyPushAction.url(),
        );
        expect(destroyCall).toBeTruthy();
        expect(destroyCall?.[1]).toEqual(
            expect.objectContaining({ method: 'DELETE' }),
        );
        expect(
            JSON.parse((destroyCall?.[1] as RequestInit).body as string),
        ).toMatchObject({
            endpoint: 'https://push.example.test/endpoint-1',
        });
    });
    it('keeps the subscription unconfirmed and shows the server reason when the store endpoint refuses', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({ existingSubscription: null });

        refuse(env, 422, {
            message: 'Der Endpunkt ist bereits registriert.',
            errors: { endpoint: ['Der Endpunkt ist bereits registriert.'] },
        });

        const { subscribe, subscribed, error } = usePushSubscription();

        const result = await subscribe();
        await flushPromises();

        expect(result).toBe(false);
        expect(subscribed.value).toBe(false);
        expect(error.value).toBe('Der Endpunkt ist bereits registriert.');
    });

    it('falls back to the German default when the refusal carries no readable body', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({ existingSubscription: null });

        refuse(env, 403, undefined);

        const { subscribe, subscribed, error } = usePushSubscription();

        const result = await subscribe();
        await flushPromises();

        expect(result).toBe(false);
        expect(subscribed.value).toBe(false);
        expect(error.value).toBe(
            'Push-Benachrichtigungen konnten nicht aktiviert werden.',
        );
    });

    it('keeps the browser subscription alive and shows the server reason when the destroy endpoint refuses', async () => {
        pageState.vapidPublicKey = 'AQID';
        const env = installSupportedEnv({
            existingSubscription: {
                endpoint: 'https://push.example.test/endpoint-1',
                toJSON: () => ({
                    keys: { p256dh: 'p256dh-key', auth: 'auth-key' },
                }),
                unsubscribe: vi.fn(() => Promise.resolve(true)),
            },
        });

        const { subscribe, unsubscribe, subscribed, error } =
            usePushSubscription();

        await subscribe();
        await flushPromises();
        expect(subscribed.value).toBe(true);

        refuse(env, 403, { message: 'Ihnen fehlt die Berechtigung.' });

        const result = await unsubscribe();
        await flushPromises();

        expect(result).toBe(false);
        expect(subscribed.value).toBe(true);
        expect(error.value).toBe('Ihnen fehlt die Berechtigung.');
        expect(env.subscription.unsubscribe).not.toHaveBeenCalled();
    });
});
