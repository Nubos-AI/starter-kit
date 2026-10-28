import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import {
    SHARE_CREATE_ERROR_MESSAGE,
    SHARE_REVOKE_ERROR_MESSAGE,
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

    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Dieser Empfänger hat das Dashboard bereits.',
                errors: {
                    grantee_id: ['Dieser Empfänger hat das Dashboard bereits.'],
                },
            }),
        );

        const { shares, stop } = scoped();

        await expect(
            shares.create({
                grantee_type: 'user',
                grantee_id: '01USER0000000000000000001',
                can_edit: false,
            }),
        ).resolves.toBeNull();

        expect(shares.error.value).toBe(
            'Dieser Empfänger hat das Dashboard bereits.',
        );
        expect(toastError).toHaveBeenCalledWith(
            'Dieser Empfänger hat das Dashboard bereits.',
        );

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

    it('keeps the German fallback when the refusal carries no message', async () => {
        stubFetch(jsonResponse(500, {}));

        const { shares, stop } = scoped();

        await expect(
            shares.create({
                grantee_type: 'user',
                grantee_id: '01USER0000000000000000001',
                can_edit: false,
            }),
        ).resolves.toBeNull();

        expect(shares.error.value).toBe(SHARE_CREATE_ERROR_MESSAGE);

        stop();
    });
});

describe('useDashboardShares — revoking a share', () => {
    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(403, {
                message: 'Sie dürfen diese Freigabe nicht widerrufen.',
            }),
        );

        const { shares, stop } = scoped();

        await expect(shares.revoke(SHARE_ID)).resolves.toBe(false);

        expect(shares.error.value).toBe(
            'Sie dürfen diese Freigabe nicht widerrufen.',
        );

        stop();
    });

    it('keeps the German fallback when the request never reaches the server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const { shares, stop } = scoped();

        await expect(shares.revoke(SHARE_ID)).resolves.toBe(false);

        expect(shares.error.value).toBe(SHARE_REVOKE_ERROR_MESSAGE);

        stop();
    });
});

describe('useDashboardShares — the tenant-wide switch', () => {
    it('puts the switch back and states the reason the server gave', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Das Dashboard hat keinen Eigentümer mehr.',
                errors: {
                    is_tenant_wide: [
                        'Das Dashboard hat keinen Eigentümer mehr.',
                    ],
                },
            }),
        );

        const { shares, stop } = scoped();

        await expect(shares.setTenantWide(true)).resolves.toBe(false);
        await flushPromises();

        expect(shares.isTenantWide.value).toBe(false);
        expect(shares.tenantWideError.value).toBe(
            'Das Dashboard hat keinen Eigentümer mehr.',
        );

        stop();
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        stubFetch(jsonResponse(500, {}));

        const { shares, stop } = scoped();

        await expect(shares.setTenantWide(true)).resolves.toBe(false);

        expect(shares.tenantWideError.value).toBe(TENANT_WIDE_ERROR_MESSAGE);

        stop();
    });
});
