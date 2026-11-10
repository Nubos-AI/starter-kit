import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import {
    OPTIONS_LOAD_ERROR_MESSAGE,
    SHARE_CREATE_ERROR_MESSAGE,
    SHARE_REVOKE_ERROR_MESSAGE,
    SHARES_LOAD_ERROR_MESSAGE,
    TENANT_WIDE_ERROR_MESSAGE,
    useDashboardShares,
} from '@/composables/useDashboardShares';
import { setUrlDefaults } from '@/wayfinder';

const DASHBOARD_ID = '01DASHBOARD00000000000001';

const SHARE_ID = '01SHARE000000000000000001';

const { toastError } = vi.hoisted(() => ({ toastError: vi.fn() }));

vi.mock('vue-sonner', () => ({
    toast: { error: toastError, success: vi.fn() },
}));

setUrlDefaults({ activeTeam: 'nubos' });

interface ScopedShares {
    shares: ReturnType<typeof useDashboardShares>;
    stop: () => void;
}

function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as unknown as Response;
}

function stubFetch(response: Response): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(response)),
    );
}

function scoped(): ScopedShares {
    const scope = effectScope();
    const shares = scope.run(() =>
        useDashboardShares(DASHBOARD_ID, () => false),
    );

    if (shares === undefined) {
        throw new Error('the scope produced no composable');
    }

    return { shares, stop: () => scope.stop() };
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useDashboardShares — creating a share', () => {
    it('keeps the created share and clears the error on success', async () => {
        stubFetch(
            jsonResponse(201, {
                data: {
                    id: SHARE_ID,
                    grantee_type: 'user',
                    grantee_id: '01USER0000000000000000001',
                    can_edit: false,
                },
            }),
        );

        const { shares, stop } = scoped();

        const created = await shares.create({
            grantee_type: 'user',
            grantee_id: '01USER0000000000000000001',
            can_edit: false,
        });

        expect(created?.id).toBe(SHARE_ID);
        expect(shares.shares.value).toHaveLength(1);
        expect(shares.error.value).toBeNull();

        stop();
    });

    it.each<[string, Response, string]>([
        [
            'shows the message the server sent instead of a fixed sentence',
            jsonResponse(422, {
                message: 'Dieser Empfänger hat das Dashboard bereits.',
                errors: {
                    grantee_id: ['Dieser Empfänger hat das Dashboard bereits.'],
                },
            }),
            'Dieser Empfänger hat das Dashboard bereits.',
        ],
        [
            'keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            SHARE_CREATE_ERROR_MESSAGE,
        ],
    ])('%s', async (_name, response, expectedError) => {
        stubFetch(response);

        const { shares, stop } = scoped();

        await expect(
            shares.create({
                grantee_type: 'user',
                grantee_id: '01USER0000000000000000001',
                can_edit: false,
            }),
        ).resolves.toBeNull();

        expect(shares.error.value).toBe(expectedError);
        expect(toastError).toHaveBeenCalledWith(expectedError);

        stop();
    });
    it('clears its own refusal once the next create succeeds', async () => {
        stubFetch(jsonResponse(422, { message: 'Empfänger unbekannt.' }));

        const { shares, stop } = scoped();

        await shares.create({
            grantee_type: 'user',
            grantee_id: '01USER0000000000000000001',
            can_edit: false,
        });

        expect(shares.error.value).toBe('Empfänger unbekannt.');

        stubFetch(
            jsonResponse(201, {
                data: {
                    id: SHARE_ID,
                    grantee_type: 'user',
                    grantee_id: '01USER0000000000000000002',
                    can_edit: false,
                },
            }),
        );

        await shares.create({
            grantee_type: 'user',
            grantee_id: '01USER0000000000000000002',
            can_edit: false,
        });

        expect(shares.error.value).toBeNull();

        stop();
    });
});

describe('useDashboardShares — revoking a share', () => {
    it.each<[string, () => Promise<Response>, string]>([
        [
            'shows the message the server sent instead of a fixed sentence',
            () =>
                Promise.resolve(
                    jsonResponse(403, {
                        message: 'Sie dürfen diese Freigabe nicht widerrufen.',
                    }),
                ),
            'Sie dürfen diese Freigabe nicht widerrufen.',
        ],
        [
            'keeps the German fallback when the request never reaches the server',
            () => Promise.reject(new TypeError('offline')),
            SHARE_REVOKE_ERROR_MESSAGE,
        ],
    ])('%s', async (_name, answer, expectedError) => {
        vi.stubGlobal('fetch', vi.fn(answer));

        const { shares, stop } = scoped();

        await expect(shares.revoke(SHARE_ID)).resolves.toBe(false);

        expect(shares.error.value).toBe(expectedError);

        stop();
    });
});

describe('useDashboardShares — the tenant-wide switch', () => {
    it.each<[string, Response, string]>([
        [
            'puts the switch back and states the reason the server gave',
            jsonResponse(422, {
                message: 'Das Dashboard hat keinen Eigentümer mehr.',
                errors: {
                    is_tenant_wide: [
                        'Das Dashboard hat keinen Eigentümer mehr.',
                    ],
                },
            }),
            'Das Dashboard hat keinen Eigentümer mehr.',
        ],
        [
            'puts the switch back and keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            TENANT_WIDE_ERROR_MESSAGE,
        ],
    ])('%s', async (_name, response, expectedError) => {
        stubFetch(response);

        const { shares, stop } = scoped();

        await expect(shares.setTenantWide(true)).resolves.toBe(false);
        await flushPromises();

        expect(shares.isTenantWide.value).toBe(false);
        expect(shares.tenantWideError.value).toBe(expectedError);

        stop();
    });
});

describe('useDashboardShares — loading shares and recipients', () => {
    it('keeps the delivered shares and leaves the loading state', async () => {
        const share = { id: SHARE_ID };
        stubFetch(jsonResponse(200, { data: [share] }));
        const { shares, stop } = scoped();

        await shares.load();

        expect(shares.shares.value).toEqual([share]);
        expect(shares.loading.value).toBe(false);
        expect(shares.error.value).toBeNull();

        stop();
    });

    it('says the shares could not be loaded and keeps the list it had', async () => {
        stubFetch(jsonResponse(500, { message: 'boom' }));
        const { shares, stop } = scoped();

        await shares.load();

        expect(shares.shares.value).toEqual([]);
        expect(shares.loading.value).toBe(false);
        expect(shares.error.value).toBe(SHARES_LOAD_ERROR_MESSAGE);
        expect(toastError).toHaveBeenCalledWith(SHARES_LOAD_ERROR_MESSAGE);

        stop();
    });

    it('keeps only the answer of the latest recipient request when an older one arrives late', async () => {
        const resolvers: Array<(response: Response) => void> = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(
                () =>
                    new Promise<Response>((resolve) => {
                        resolvers.push(resolve);
                    }),
            ),
        );
        const { shares, stop } = scoped();

        const first = shares.loadOptions();
        const second = shares.loadOptions();

        resolvers[1](
            jsonResponse(200, {
                options: { user: [{ value: 'new', label: 'Neu' }] },
            }),
        );
        await second;

        resolvers[0](
            jsonResponse(200, {
                options: { user: [{ value: 'old', label: 'Alt' }] },
            }),
        );
        await first;
        await flushPromises();

        expect(shares.options.value.user).toEqual([
            { value: 'new', label: 'Neu' },
        ]);
        expect(shares.options.value.team).toEqual([]);

        stop();
    });

    it('says the recipients could not be loaded on a broken answer', async () => {
        stubFetch(jsonResponse(200, { unexpected: true }));
        const { shares, stop } = scoped();

        await shares.loadOptions();

        expect(shares.error.value).toBe(OPTIONS_LOAD_ERROR_MESSAGE);

        stop();
    });

    it('aborts a request still in flight once its scope ends and changes nothing afterwards', async () => {
        const signals: AbortSignal[] = [];
        let resolveLate: (response: Response) => void = () => undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn(
                (_url: string, init: RequestInit) =>
                    new Promise<Response>((resolve) => {
                        if (init.signal) {
                            signals.push(init.signal);
                        }

                        resolveLate = resolve;
                    }),
            ),
        );
        const { shares, stop } = scoped();

        const pending = shares.load();
        stop();

        expect(signals).toHaveLength(1);
        expect(signals[0].aborted).toBe(true);

        resolveLate(jsonResponse(200, { data: [{ id: SHARE_ID }] }));
        await pending;

        expect(shares.shares.value).toEqual([]);
        expect(toastError).not.toHaveBeenCalled();
    });
});
