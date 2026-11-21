import { afterEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { lookup as lookupRoute } from '@/routes/engine/records';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { useRecordLookup } from './useRecordLookup';

setUrlDefaults({ activeTeam: 'nubos' });

const route = lookupRoute({ objectType: 'companies' });

function page(
  values: string[],
  hasMore: boolean,
): { data: { value: string; label: string }[]; meta: { hasMore: boolean } } {
  return {
    data: values.map((value) => ({ value, label: value })),
    meta: { hasMore },
  };
}

function servePage(values: string[], hasMore: boolean): ServedRoute {
  return serveJson(route, page(values, hasMore));
}

function settle(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 300));
}

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useRecordLookup', () => {
  it('asks the lookup endpoint of the object type for the first page', async () => {
    document.cookie = 'XSRF-TOKEN=test-xsrf-token';
    const served = servePage(['a'], false);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/object-types/companies/records/lookup');
    expect(served.lastRequest()?.url.searchParams.get('q')).toBe('');
    expect(served.lastRequest()?.url.searchParams.get('offset')).toBe('0');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(lookup.options.value).toEqual([{ value: 'a', label: 'a' }]);
  });

  it('asks the server for the term that was typed', async () => {
    const served = servePage(['a'], false);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    lookup.search.value = 'Hafen';
    await settle();

    expect(served.requests).toHaveLength(2);
    expect(served.lastRequest()?.url.searchParams.get('q')).toBe('Hafen');
    expect(served.lastRequest()?.url.searchParams.get('offset')).toBe('0');
  });

  it('appends the next page from the offset where the options end', async () => {
    const served = servePage(['a', 'b'], true);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    await lookup.loadMore();

    expect(served.lastRequest()?.url.searchParams.get('offset')).toBe('2');
    expect(lookup.options.value).toHaveLength(4);
  });

  it('stops asking once the server says there are no more', async () => {
    const served = servePage(['a'], false);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    await lookup.loadMore();

    expect(served.requests).toHaveLength(1);
    expect(lookup.hasMore.value).toBe(false);
  });

  it('starts a new term at the first page instead of appending to the old one', async () => {
    servePage(['a', 'b'], true);

    const lookup = useRecordLookup(ref('companies'));
    await settle();
    await lookup.loadMore();

    lookup.search.value = 'Hafen';
    await settle();

    expect(lookup.options.value).toHaveLength(2);
  });

  it('asks nothing while no object type is chosen', async () => {
    const served = servePage(['a'], false);

    const lookup = useRecordLookup(ref<string | null>(null));
    await settle();

    expect(served.requests).toHaveLength(0);
    expect(lookup.options.value).toEqual([]);
    expect(lookup.loading.value).toBe(false);
  });

  it('marks the lookup as loading until the server answers', async () => {
    const served = serveJson(route, page(['a'], false), { held: true });

    const lookup = useRecordLookup(ref('companies'));
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(lookup.loading.value).toBe(true);

    served.release();
    await vi.waitFor(() => expect(lookup.loading.value).toBe(false));

    expect(lookup.options.value).toHaveLength(1);
  });

  it('empties the options and stops paging when the server fails', async () => {
    serveServerError(route);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    expect(lookup.options.value).toEqual([]);
    expect(lookup.hasMore.value).toBe(false);
    expect(lookup.loading.value).toBe(false);
  });

  it('keeps the loaded page when the next page cannot be reached', async () => {
    servePage(['a', 'b'], true);

    const lookup = useRecordLookup(ref('companies'));
    await settle();

    serveNetworkError(route);
    await lookup.loadMore();

    expect(lookup.options.value).toHaveLength(2);
    expect(lookup.hasMore.value).toBe(false);
    expect(lookup.loading.value).toBe(false);
  });
});
