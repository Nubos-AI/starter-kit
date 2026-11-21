import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import { serveJson, serveNetworkError, serveServerError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { useGlobalSearch } from './useGlobalSearch';
import type { UseGlobalSearchReturn } from './useGlobalSearch';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const searchRoute = SearchController();

const searchFailedMessage = 'Die Suche konnte nicht ausgeführt werden. Bitte versuchen Sie es erneut.';

function startSearch(term: string): UseGlobalSearchReturn {
  const scope = effectScope();
  const search = scope.run(() => useGlobalSearch()) as UseGlobalSearchReturn;

  search.search(term);

  return search;
}

async function searched(term: string): Promise<UseGlobalSearchReturn> {
  const search = startSearch(term);

  await vi.advanceTimersByTimeAsync(500);
  await flushPromises();

  return search;
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  vi.useRealTimers();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useGlobalSearch — group types', () => {
  it.each([
    ['reduces a model class name to its lower-case base name', 'App\\Models\\Company', 'company'],
    ['keeps a type that carries no namespace', 'deals', 'deals'],
  ])('%s', async (_case, raw, expected) => {
    serveJson(searchRoute, {
      data: {
        groups: [
          {
            type: raw,
            label: 'Firmen',
            records: [{ id: 'rec-1', title: 'Acme' }],
          },
        ],
      },
    });

    const search = await searched('acme');

    expect(search.groups.value).toEqual([
      {
        type: expected,
        label: 'Firmen',
        records: [{ id: 'rec-1', title: 'Acme' }],
      },
    ]);
  });
});

describe('useGlobalSearch — request', () => {
  it('sends one trimmed GET with the term as query after the debounce', async () => {
    const served = serveJson(searchRoute, { data: { groups: [] } });

    const search = startSearch('  acme  ');
    await vi.advanceTimersByTimeAsync(100);

    expect(served.requests).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(400);
    await flushPromises();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/search');
    expect(served.lastRequest()?.url.searchParams.get('q')).toBe('acme');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(search.groups.value).toEqual([]);
    expect(search.error.value).toBeNull();
  });

  it('stays loading until the server answers', async () => {
    const served = serveJson(searchRoute, { data: { groups: [] } }, { held: true });

    const search = startSearch('acme');
    await vi.advanceTimersByTimeAsync(500);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(search.loading.value).toBe(true);

    served.release();
    await vi.waitFor(() => expect(search.loading.value).toBe(false));

    expect(search.error.value).toBeNull();
  });
});

describe('useGlobalSearch — failures', () => {
  it('clears the results and names the failure when the server errors', async () => {
    serveServerError(searchRoute);

    const search = await searched('acme');

    await vi.waitFor(() => expect(search.loading.value).toBe(false));
    expect(search.groups.value).toEqual([]);
    expect(search.error.value).toBe(searchFailedMessage);
  });

  it('names the failure when the network drops the request', async () => {
    serveNetworkError(searchRoute);

    const search = await searched('acme');

    await vi.waitFor(() => expect(search.loading.value).toBe(false));
    expect(search.groups.value).toEqual([]);
    expect(search.error.value).toBe(searchFailedMessage);
  });
});
