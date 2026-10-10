import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSegmentShares } from '@/composables/useSegmentShares';
import { setUrlDefaults } from '@/wayfinder';

const SEGMENT_ID = '01SEGMENT0000000000000001';

const SHARE_ID = '01SHARE000000000000000001';

const CREATE_FALLBACK =
    'Die Freigabe konnte nicht angelegt werden. Bitte versuchen Sie es erneut.';

const REVOKE_FALLBACK =
    'Die Freigabe konnte nicht widerrufen werden. Bitte versuchen Sie es erneut.';

setUrlDefaults({ activeTeam: 'nubos' });

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

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useSegmentShares — creating a share', () => {
    it('keeps the created share and clears the error on success', async () => {
        stubFetch(
            jsonResponse(201, {
                data: {
                    id: SHARE_ID,
                    grantee_type: 'App\\Models\\Team',
                    grantee_id: '01TEAM0000000000000000001',
                    can_edit: true,
                },
            }),
        );

        const { create, shares, error } = useSegmentShares(SEGMENT_ID);

        const created = await create({
            grantee_type: 'team',
            grantee_id: '01TEAM0000000000000000001',
            can_edit: true,
        });

        expect(created?.grantee_type).toBe('team');
        expect(shares.value).toHaveLength(1);
        expect(error.value).toBeNull();
    });

    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Dieses Team hat das Segment bereits.',
                errors: { grantee_id: ['egal'] },
            }),
        );

        const { create, shares, error } = useSegmentShares(SEGMENT_ID);

        await expect(
            create({
                grantee_type: 'team',
                grantee_id: '01TEAM0000000000000000001',
                can_edit: true,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe('Dieses Team hat das Segment bereits.');
        expect(shares.value).toEqual([]);
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        stubFetch(jsonResponse(500, {}));

        const { create, error } = useSegmentShares(SEGMENT_ID);

        await expect(
            create({
                grantee_type: 'team',
                grantee_id: '01TEAM0000000000000000001',
                can_edit: true,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe(CREATE_FALLBACK);
    });
});

describe('useSegmentShares — revoking a share', () => {
    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(403, {
                message: 'Sie dürfen diese Freigabe nicht widerrufen.',
            }),
        );

        const { revoke, error } = useSegmentShares(SEGMENT_ID);

        await expect(revoke(SHARE_ID)).resolves.toBe(false);

        expect(error.value).toBe('Sie dürfen diese Freigabe nicht widerrufen.');
    });

    it('keeps the German fallback when the request never reaches the server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const { revoke, error } = useSegmentShares(SEGMENT_ID);

        await expect(revoke(SHARE_ID)).resolves.toBe(false);

        expect(error.value).toBe(REVOKE_FALLBACK);
    });
});
