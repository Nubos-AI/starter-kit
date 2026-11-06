import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, ref } from 'vue';
import {
    destroy as destroyPushAction,
    store as storePushAction,
} from '@/actions/App/Http/Controllers/Notifications/PushSubscriptionsController';
import { buildHeaders } from '@/composables/useRequestHeaders';
import { readErrorReason } from '@/lib/errorResponse';

export type PushPermission = NotificationPermission | 'unsupported';

export interface UsePushSubscriptionReturn {
    supported: Ref<boolean>;
    permission: Ref<PushPermission>;
    subscribed: Ref<boolean>;
    busy: Ref<boolean>;
    error: Ref<string | null>;
    subscribe: () => Promise<boolean>;
    unsubscribe: () => Promise<boolean>;
}

const SERVICE_WORKER_URL = '/sw.js';

const SUBSCRIBE_ERROR_MESSAGE =
    'Push-Benachrichtigungen konnten nicht aktiviert werden.';

const UNSUBSCRIBE_ERROR_MESSAGE =
    'Push-Benachrichtigungen konnten nicht deaktiviert werden.';

const PERMISSION_DENIED_MESSAGE =
    'Der Browser hat Push-Benachrichtigungen abgelehnt.';

function isSupported(): boolean {
    return (
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        typeof window !== 'undefined' &&
        'PushManager' in window &&
        'Notification' in window
    );
}

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const buffer = new ArrayBuffer(rawData.length);
    const output = new Uint8Array(buffer);

    for (let index = 0; index < rawData.length; index += 1) {
        output[index] = rawData.charCodeAt(index);
    }

    return output;
}

export function usePushSubscription(): UsePushSubscriptionReturn {
    const supportedRef = ref<boolean>(isSupported());
    const permission = ref<PushPermission>(
        supportedRef.value ? Notification.permission : 'unsupported',
    );
    const subscribed = ref<boolean>(false);
    const busy = ref<boolean>(false);
    const error = ref<string | null>(null);

    const supported = computed<boolean>(() => supportedRef.value);

    const vapidPublicKey = (): string => {
        const key = usePage().props.vapidPublicKey;

        return typeof key === 'string' ? key : '';
    };

    const sendSubscription = async (
        subscription: PushSubscription,
    ): Promise<string | null> => {
        const json = subscription.toJSON();

        const response = await fetch(storePushAction.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: buildHeaders({ hasBody: true }),
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                public_key: json.keys?.p256dh ?? null,
                auth_token: json.keys?.auth ?? null,
                content_encoding:
                    'supportedContentEncodings' in PushManager &&
                    Array.isArray(
                        (
                            PushManager as unknown as {
                                supportedContentEncodings?: string[];
                            }
                        ).supportedContentEncodings,
                    )
                        ? (
                              PushManager as unknown as {
                                  supportedContentEncodings: string[];
                              }
                          ).supportedContentEncodings[0]
                        : null,
            }),
        });

        return response.ok
            ? null
            : await readErrorReason(response, SUBSCRIBE_ERROR_MESSAGE);
    };

    const subscribe = async (): Promise<boolean> => {
        if (!supportedRef.value) {
            error.value = SUBSCRIBE_ERROR_MESSAGE;

            return false;
        }

        const applicationServerKey = vapidPublicKey();

        if (applicationServerKey === '') {
            error.value = SUBSCRIBE_ERROR_MESSAGE;

            return false;
        }

        busy.value = true;
        error.value = null;

        try {
            const result = await Notification.requestPermission();
            permission.value = result;

            if (result !== 'granted') {
                error.value = PERMISSION_DENIED_MESSAGE;

                return false;
            }

            const registration =
                await navigator.serviceWorker.register(SERVICE_WORKER_URL);
            const ready = await navigator.serviceWorker.ready.catch(
                () => registration,
            );

            const existing = await ready.pushManager.getSubscription();
            const subscription =
                existing ??
                (await ready.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey:
                        urlBase64ToUint8Array(applicationServerKey),
                }));

            const refusal = await sendSubscription(subscription);

            if (refusal !== null) {
                error.value = refusal;

                return false;
            }

            subscribed.value = true;

            return true;
        } catch {
            error.value = SUBSCRIBE_ERROR_MESSAGE;

            return false;
        } finally {
            busy.value = false;
        }
    };

    const unsubscribe = async (): Promise<boolean> => {
        if (!supportedRef.value) {
            return false;
        }

        busy.value = true;
        error.value = null;

        try {
            const registration =
                await navigator.serviceWorker.getRegistration(
                    SERVICE_WORKER_URL,
                );
            const subscription =
                (await registration?.pushManager.getSubscription()) ?? null;

            if (subscription !== null) {
                const response = await fetch(destroyPushAction.url(), {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: buildHeaders({ hasBody: true }),
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });

                if (!response.ok) {
                    error.value = await readErrorReason(
                        response,
                        UNSUBSCRIBE_ERROR_MESSAGE,
                    );

                    return false;
                }

                await subscription.unsubscribe();
            }

            subscribed.value = false;

            return true;
        } catch {
            error.value = UNSUBSCRIBE_ERROR_MESSAGE;

            return false;
        } finally {
            busy.value = false;
        }
    };

    return {
        supported,
        permission,
        subscribed,
        busy,
        error,
        subscribe,
        unsubscribe,
    };
}
