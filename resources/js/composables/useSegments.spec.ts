import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSegments } from '@/composables/useSegments';
import type { RecordObjectType } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

const objectType: RecordObjectType = {
    id: '01OBJECTTYPE000000000001',
    key: 'companies',
    slug: 'companies',
    name: 'Companies',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const SAVE_FALLBACK =
    'Das Segment konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

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

describe('useSegments — routes through Wayfinder', () => {
    it('posts to the active team prefix instead of a bare path', async () => {
        stubFetch(jsonResponse(201, { data: {} }));

        const { save } = useSegments(objectType);

        await save({
            name: 'Meine Kunden',
            object_type_id: objectType.id,
            filter_definition: null,
        });

        expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe(
            '/nubos/engine/segments',
        );
    });

    it('loads from the active team prefix as well', async () => {
        stubFetch(jsonResponse(200, { data: [] }));

        const { load } = useSegments(objectType);

        await load();

        expect(vi.mocked(fetch).mock.calls[0]?.[0]).toBe(
            '/nubos/engine/segments?object_type=companies',
        );
    });
});

describe('useSegments — a redirected write is not a success', () => {
    it('reports an error when the response carries no saved segment', async () => {
        stubFetch(jsonResponse(200, { data: [] }));

        const { save, error } = useSegments(objectType);

        const saved = await save({
            name: 'Meine Kunden',
            object_type_id: objectType.id,
            filter_definition: null,
        });

        expect(saved).toBeNull();
        expect(error.value).toBe(SAVE_FALLBACK);
    });
});

describe('useSegments — saving a segment', () => {
    it('returns the saved segment and clears the error on success', async () => {
        stubFetch(
            jsonResponse(201, {
                data: {
                    id: '01SEGMENT0000000000000001',
                    name: 'Meine Kunden',
                    object_type_id: objectType.id,
                    is_system: false,
                    is_default: false,
                    is_owner: true,
                },
            }),
        );

        const { save, error } = useSegments(objectType);

        const saved = await save({
            name: 'Meine Kunden',
            object_type_id: objectType.id,
            filter_definition: null,
        });

        expect(saved?.name).toBe('Meine Kunden');
        expect(error.value).toBeNull();
    });

    it('shows the message the server sent instead of a fixed sentence', async () => {
        stubFetch(
            jsonResponse(422, {
                message: 'Ein Segment mit diesem Namen gibt es bereits.',
                errors: { name: ['egal'] },
            }),
        );

        const { save, error } = useSegments(objectType);

        await expect(
            save({
                name: 'Meine Kunden',
                object_type_id: objectType.id,
                filter_definition: null,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe(
            'Ein Segment mit diesem Namen gibt es bereits.',
        );
    });

    it('keeps the German fallback when the refusal carries no message', async () => {
        stubFetch(jsonResponse(500, {}));

        const { save, error } = useSegments(objectType);

        await expect(
            save({
                name: 'Meine Kunden',
                object_type_id: objectType.id,
                filter_definition: null,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe(SAVE_FALLBACK);
    });

    it('keeps the German fallback when the request never reaches the server', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new TypeError('offline'))),
        );

        const { save, error } = useSegments(objectType);

        await expect(
            save({
                name: 'Meine Kunden',
                object_type_id: objectType.id,
                filter_definition: null,
            }),
        ).resolves.toBeNull();

        expect(error.value).toBe(SAVE_FALLBACK);
    });
});
