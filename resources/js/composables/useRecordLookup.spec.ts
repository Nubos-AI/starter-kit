import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { useRecordLookup } from '@/composables/useRecordLookup';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

function jsonResponse(body: unknown): Response {
    return {
        ok: true,
        status: 200,
        json: async () => body,
    } as unknown as Response;
}

function page(
    values: string[],
    hasMore: boolean,
): { data: { value: string; label: string }[]; meta: { hasMore: boolean } } {
    return {
        data: values.map((value) => ({ value, label: value })),
        meta: { hasMore },
    };
}

function settle(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 300));
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('useRecordLookup', () => {
    it('asks the server for the term that was typed', async () => {
        const fetchMock = vi.fn<(url: string) => Promise<Response>>(() =>
            Promise.resolve(jsonResponse(page(['a'], false))),
        );
        vi.stubGlobal('fetch', fetchMock);

        const lookup = useRecordLookup(ref('companies'));
        await settle();

        lookup.search.value = 'Hafen';
        await settle();

        expect(String(fetchMock.mock.calls.at(-1)?.[0])).toContain('q=Hafen');
    });

    it('appends the next page from the offset where the options end', async () => {
        const fetchMock = vi.fn<(url: string) => Promise<Response>>(() =>
            Promise.resolve(jsonResponse(page(['a', 'b'], true))),
        );
        vi.stubGlobal('fetch', fetchMock);

        const lookup = useRecordLookup(ref('companies'));
        await settle();

        await lookup.loadMore();

        expect(String(fetchMock.mock.calls.at(-1)?.[0])).toContain('offset=2');
        expect(lookup.options.value).toHaveLength(4);
    });

    it('stops asking once the server says there are no more', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(page(['a'], false))),
        );
        vi.stubGlobal('fetch', fetchMock);

        const lookup = useRecordLookup(ref('companies'));
        await settle();

        await lookup.loadMore();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(lookup.hasMore.value).toBe(false);
    });

    it('starts a new term at the first page instead of appending to the old one', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(jsonResponse(page(['a', 'b'], true))),
        );
        vi.stubGlobal('fetch', fetchMock);

        const lookup = useRecordLookup(ref('companies'));
        await settle();
        await lookup.loadMore();

        lookup.search.value = 'Hafen';
        await settle();

        expect(lookup.options.value).toHaveLength(2);
    });
});
