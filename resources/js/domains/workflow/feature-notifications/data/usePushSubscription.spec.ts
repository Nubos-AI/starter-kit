import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  destroy as destroyPushAction,
  store as storePushAction,
} from '@/actions/App/Http/Controllers/Notifications/PushSubscriptionsController';
import { serveEmpty, serveJson, serveNetworkError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { usePushSubscription } from './usePushSubscription';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const storeRoute = storePushAction();

const destroyRoute = destroyPushAction();

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

  const getSubscriptionMock = vi.fn(() => Promise.resolve(options.existingSubscription));
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

  const requestPermissionMock = vi.fn(() => Promise.resolve(options.permission ?? 'granted'));

  vi.stubGlobal(
    'Notification',
    Object.assign(vi.fn(), {
      permission: 'default' as NotificationPermission,
      requestPermission: requestPermissionMock,
    }),
  );

  return {
    registerMock,
    subscribeMock,
    getSubscriptionMock,
    subscription: returnedSubscription,
    requestPermissionMock,
  };
}

function serveStored(): ServedRoute {
  return serveJson(storeRoute, {}, { status: 201 });
}

function existingSubscription(): PushSubscriptionMock {
  return {
    endpoint: 'https://push.example.test/endpoint-1',
    toJSON: () => ({
      keys: { p256dh: 'p256dh-key', auth: 'auth-key' },
    }),
    unsubscribe: vi.fn(() => Promise.resolve(true)),
  };
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.unstubAllGlobals();
  vi.clearAllMocks();
  vi.restoreAllMocks();
});

describe('usePushSubscription (feature guards, subscribe, unsubscribe)', () => {
  it('reports unsupported and degrades gracefully when Push APIs are absent', async () => {
    pageState.vapidPublicKey = 'AQID';

    const { supported, permission, subscribe, unsubscribe } = usePushSubscription();

    expect(supported.value).toBe(false);
    expect(permission.value).toBe('unsupported');

    await expect(subscribe()).resolves.toBe(false);
    await expect(unsubscribe()).resolves.toBe(false);
  });

  it('decodes the base64url VAPID key and subscribes with the exact applicationServerKey bytes', async () => {
    pageState.vapidPublicKey = '-_wA';
    const env = installSupportedEnv({ existingSubscription: null });
    serveStored();

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
    expect(Array.from(subscribeOptions.applicationServerKey)).toEqual([251, 252, 0]);
  });

  it('POSTs the subscription payload to the push store endpoint after permission is granted', async () => {
    pageState.vapidPublicKey = 'AQID';
    const env = installSupportedEnv({ existingSubscription: null });
    const stored = serveStored();

    const { subscribe } = usePushSubscription();

    await subscribe();
    await flushPromises();

    expect(env.requestPermissionMock).toHaveBeenCalledTimes(1);

    expect(stored.requests).toHaveLength(1);
    expect(stored.lastRequest()?.method).toBe('POST');
    expect(stored.lastRequest()?.url.pathname).toBe('/nubos/notifications/push-subscriptions');
    expect(stored.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(stored.lastRequest()?.body).toEqual({
      endpoint: 'https://push.example.test/endpoint-1',
      public_key: 'p256dh-key',
      auth_token: 'auth-key',
      content_encoding: 'aes128gcm',
    });
  });

  it('is busy until the server confirmed the subscription', async () => {
    pageState.vapidPublicKey = 'AQID';
    installSupportedEnv({ existingSubscription: null });
    const stored = serveJson(storeRoute, {}, { held: true });

    const { subscribe, busy } = usePushSubscription();

    const subscribing = subscribe();
    await vi.waitFor(() => expect(stored.requests).toHaveLength(1));

    expect(busy.value).toBe(true);

    stored.release();
    await subscribing;

    expect(busy.value).toBe(false);
  });

  it('does not POST and reports an error when permission is denied', async () => {
    pageState.vapidPublicKey = 'AQID';
    const env = installSupportedEnv({
      existingSubscription: null,
      permission: 'denied',
    });
    const stored = serveStored();

    const { subscribe, error, permission } = usePushSubscription();

    const result = await subscribe();
    await flushPromises();

    expect(result).toBe(false);
    expect(permission.value).toBe('denied');
    expect(error.value).toBe('Der Browser hat Push-Benachrichtigungen abgelehnt.');
    expect(env.subscribeMock).not.toHaveBeenCalled();
    expect(stored.requests).toHaveLength(0);
  });

  it('unsubscribe() DELETEs the destroy endpoint and tears down the browser subscription', async () => {
    pageState.vapidPublicKey = 'AQID';
    const env = installSupportedEnv({
      existingSubscription: existingSubscription(),
    });
    const destroyed = serveEmpty(destroyRoute);

    const { unsubscribe, subscribed } = usePushSubscription();

    const result = await unsubscribe();
    await flushPromises();

    expect(result).toBe(true);
    expect(subscribed.value).toBe(false);
    expect(env.subscription.unsubscribe).toHaveBeenCalledTimes(1);

    expect(destroyed.requests).toHaveLength(1);
    expect(destroyed.lastRequest()?.method).toBe('DELETE');
    expect(destroyed.lastRequest()?.url.pathname).toBe('/nubos/notifications/push-subscriptions');
    expect(destroyed.lastRequest()?.body).toEqual({
      endpoint: 'https://push.example.test/endpoint-1',
    });
  });

  it('keeps the subscription unconfirmed and shows the server reason when the store endpoint refuses', async () => {
    pageState.vapidPublicKey = 'AQID';
    installSupportedEnv({ existingSubscription: null });
    serveJson(
      storeRoute,
      {
        message: 'Der Endpunkt ist bereits registriert.',
        errors: {
          endpoint: ['Der Endpunkt ist bereits registriert.'],
        },
      },
      { status: 422 },
    );

    const { subscribe, subscribed, error } = usePushSubscription();

    const result = await subscribe();
    await flushPromises();

    expect(result).toBe(false);
    expect(subscribed.value).toBe(false);
    expect(error.value).toBe('Der Endpunkt ist bereits registriert.');
  });

  it('falls back to the German default when the refusal carries no readable body', async () => {
    pageState.vapidPublicKey = 'AQID';
    installSupportedEnv({ existingSubscription: null });
    serveEmpty(storeRoute, { status: 403 });

    const { subscribe, subscribed, error } = usePushSubscription();

    const result = await subscribe();
    await flushPromises();

    expect(result).toBe(false);
    expect(subscribed.value).toBe(false);
    expect(error.value).toBe('Push-Benachrichtigungen konnten nicht aktiviert werden.');
  });

  it('falls back to the German default when the network drops the subscription', async () => {
    pageState.vapidPublicKey = 'AQID';
    installSupportedEnv({ existingSubscription: null });
    serveNetworkError(storeRoute);

    const { subscribe, subscribed, busy, error } = usePushSubscription();

    const result = await subscribe();

    expect(result).toBe(false);
    expect(subscribed.value).toBe(false);
    expect(busy.value).toBe(false);
    expect(error.value).toBe('Push-Benachrichtigungen konnten nicht aktiviert werden.');
  });

  it('keeps the browser subscription alive and shows the server reason when the destroy endpoint refuses', async () => {
    pageState.vapidPublicKey = 'AQID';
    const env = installSupportedEnv({
      existingSubscription: existingSubscription(),
    });
    serveStored();
    serveJson(destroyRoute, { message: 'Ihnen fehlt die Berechtigung.' }, { status: 403 });

    const { subscribe, unsubscribe, subscribed, error } = usePushSubscription();

    await subscribe();
    await flushPromises();
    expect(subscribed.value).toBe(true);

    const result = await unsubscribe();
    await flushPromises();

    expect(result).toBe(false);
    expect(subscribed.value).toBe(true);
    expect(error.value).toBe('Ihnen fehlt die Berechtigung.');
    expect(env.subscription.unsubscribe).not.toHaveBeenCalled();
  });

  it('keeps the browser subscription and says so when the network drops the unsubscribe', async () => {
    pageState.vapidPublicKey = 'AQID';
    const env = installSupportedEnv({
      existingSubscription: existingSubscription(),
    });
    serveNetworkError(destroyRoute);

    const { unsubscribe, error } = usePushSubscription();

    const result = await unsubscribe();

    expect(result).toBe(false);
    expect(error.value).toBe('Push-Benachrichtigungen konnten nicht deaktiviert werden.');
    expect(env.subscription.unsubscribe).not.toHaveBeenCalled();
  });
});
