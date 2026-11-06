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

    it.each<[string, Response, string]>([
        [
            'shows the message the server sent instead of a fixed sentence',
            jsonResponse(422, {
                message: 'Dieses Team hat das Segment bereits.',
                errors: { grantee_id: ['egal'] },
            }),
            'Dieses Team hat das Segment bereits.',
        ],
        [
            'keeps the German fallback when the refusal carries no message',
            jsonResponse(500, {}),
            CREATE_FALLBACK,
        ],
    ])('%s', async (_name, response, expectedError) => {
        stubFetch(response);

        const { create, shares, error } = useSegmentShares(SEGMENT_ID);

        await expect(
            create({
                grantee_type: 'team',
                grantee_id: '01TEAM0000000000000000001',
                can_edit: true,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe(expectedError);
        expect(shares.value).toEqual([]);
    });
});

describe('useSegmentShares — revoking a share', () => {
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
            REVOKE_FALLBACK,
        ],
    ])('%s', async (_name, answer, expectedError) => {
        vi.stubGlobal('fetch', vi.fn(answer));

        const { revoke, error } = useSegmentShares(SEGMENT_ID);

        await expect(revoke(SHARE_ID)).resolves.toBe(false);

        expect(error.value).toBe(expectedError);
    });
});
