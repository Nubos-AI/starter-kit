import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentsController from '@/actions/App/Http/Controllers/Segments/SegmentsController';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { useSegments } from './useSegments';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const objectType: RecordObjectType = {
  id: '01OBJECTTYPE000000000001',
  key: 'companies',
  slug: 'companies',
  name: 'Companies',
  label: 'Firmen',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const SAVE_FALLBACK = 'Das Segment konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

const LOAD_FALLBACK = 'Die Segmente konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const indexRoute = SegmentsController.index();

const storeRoute = SegmentsController.store();

const payload = {
  name: 'Meine Kunden',
  object_type_id: objectType.id,
  filter_definition: null,
};

function segment(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    id: '01SEGMENT0000000000000001',
    name: 'Meine Kunden',
    object_type_id: objectType.id,
    is_system: false,
    is_default: false,
    is_owner: true,
    ...overrides,
  };
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useSegments — routes through Wayfinder', () => {
  it('posts to the active team prefix instead of a bare path', async () => {
    const served = serveJson(storeRoute, { data: segment() }, { status: 201 });

    const { save } = useSegments(objectType);

    await save(payload);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/segments');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.body).toEqual(payload);
  });

  it('loads from the active team prefix as well', async () => {
    const served = serveJson(indexRoute, { data: [] });

    const { load } = useSegments(objectType);

    await load();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/segments');
    expect(served.lastRequest()?.url.searchParams.get('object_type')).toBe('companies');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
  });
});

describe('useSegments — loading the segments', () => {
  it('sorts the segments into default, system, own and shared', async () => {
    serveJson(indexRoute, {
      data: [
        segment({ id: 's-default', is_default: true }),
        segment({ id: 's-system', is_system: true }),
        segment({ id: 's-own' }),
        segment({ id: 's-shared', is_owner: false }),
      ],
    });

    const { load, groups, error, loading } = useSegments(objectType);

    await load();

    expect(groups.value.default.map((entry) => entry.id)).toEqual(['s-default']);
    expect(groups.value.system.map((entry) => entry.id)).toEqual(['s-system']);
    expect(groups.value.own.map((entry) => entry.id)).toEqual(['s-own']);
    expect(groups.value.shared.map((entry) => entry.id)).toEqual(['s-shared']);
    expect(error.value).toBeNull();
    expect(loading.value).toBe(false);
  });

  it('marks the segments as loading until the server answers', async () => {
    const served = serveJson(indexRoute, { data: [] }, { held: true });

    const { load, loading } = useSegments(objectType);

    const loadingSegments = load();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingSegments;

    expect(loading.value).toBe(false);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(indexRoute)],
    ['the connection fails', () => serveNetworkError(indexRoute)],
  ])('names the failure when %s', async (_name, serve) => {
    serve();

    const { load, segments, error, loading } = useSegments(objectType);

    await load();

    expect(segments.value).toEqual([]);
    expect(error.value).toBe(LOAD_FALLBACK);
    expect(loading.value).toBe(false);
  });
});

describe('useSegments — a redirected write is not a success', () => {
  it('reports an error when the response carries no saved segment', async () => {
    serveJson(storeRoute, { data: [] });

    const { save, error } = useSegments(objectType);

    const saved = await save(payload);

    expect(saved).toBeNull();
    expect(error.value).toBe(SAVE_FALLBACK);
  });
});

describe('useSegments — saving a segment', () => {
  it('returns the saved segment and clears the error on success', async () => {
    serveJson(storeRoute, { data: segment() }, { status: 201 });

    const { save, error } = useSegments(objectType);

    const saved = await save(payload);

    expect(saved?.name).toBe('Meine Kunden');
    expect(error.value).toBeNull();
  });

  it.each<[string, () => ServedRoute, string]>([
    [
      'shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          storeRoute,
          {
            message: 'Ein Segment mit diesem Namen gibt es bereits.',
            errors: { name: ['egal'] },
          },
          { status: 422 },
        ),
      'Ein Segment mit diesem Namen gibt es bereits.',
    ],
    [
      'shows the validation message when the server names only the field',
      () =>
        serveValidationError(storeRoute, {
          name: ['Bitte geben Sie einen Namen ein.'],
        }),
      'Bitte geben Sie einen Namen ein.',
    ],
    [
      'keeps the German fallback when the refusal carries no message',
      () => serveServerError(storeRoute, {}),
      SAVE_FALLBACK,
    ],
    [
      'keeps the German fallback when the request never reaches the server',
      () => serveNetworkError(storeRoute),
      SAVE_FALLBACK,
    ],
  ])('%s', async (_name, serve, expectedError) => {
    serve();

    const { save, error } = useSegments(objectType);

    await expect(save(payload)).resolves.toBeNull();

    expect(error.value).toBe(expectedError);
  });
});
