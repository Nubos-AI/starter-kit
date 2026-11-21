import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentShareOptionsController from '@/actions/App/Http/Controllers/Segments/SegmentShareOptionsController';
import SegmentSharesController from '@/actions/App/Http/Controllers/Segments/SegmentSharesController';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { fetchGranteeOptions, useSegmentShares } from './useSegmentShares';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const SEGMENT_ID = '01SEGMENT0000000000000001';

const SHARE_ID = '01SHARE000000000000000001';

const TEAM_ID = '01TEAM0000000000000000001';

const CREATE_FALLBACK = 'Die Freigabe konnte nicht angelegt werden. Bitte versuchen Sie es erneut.';

const REVOKE_FALLBACK = 'Die Freigabe konnte nicht widerrufen werden. Bitte versuchen Sie es erneut.';

const LOAD_FALLBACK = 'Die Freigaben konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

setUrlDefaults({ activeTeam: 'nubos' });

const indexRoute = SegmentSharesController.index({ segment: SEGMENT_ID });

const storeRoute = SegmentSharesController.store({ segment: SEGMENT_ID });

const destroyRoute = SegmentSharesController.destroy({
  segment: SEGMENT_ID,
  share: SHARE_ID,
});

const optionsRoute = SegmentShareOptionsController({ segment: SEGMENT_ID });

const teamShare = {
  id: SHARE_ID,
  grantee_type: 'App\\Models\\Team',
  grantee_id: TEAM_ID,
  can_edit: true,
};

function expectSession(served: ServedRoute): void {
  expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
  expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useSegmentShares — loading the shares', () => {
  it('reads the shares of the segment and names the grantee type', async () => {
    const served = serveJson(indexRoute, { data: [teamShare] });

    const { load, shares, error, loading } = useSegmentShares(SEGMENT_ID);

    await load();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares`);
    expectSession(served);
    expect(shares.value).toEqual([
      {
        id: SHARE_ID,
        grantee_type: 'team',
        grantee_id: TEAM_ID,
        can_edit: true,
      },
    ]);
    expect(error.value).toBeNull();
    expect(loading.value).toBe(false);
  });

  it('marks the shares as loading until the server answers', async () => {
    const served = serveJson(indexRoute, { data: [] }, { held: true });

    const { load, loading } = useSegmentShares(SEGMENT_ID);

    const loadingShares = load();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingShares;

    expect(loading.value).toBe(false);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(indexRoute)],
    ['the connection fails', () => serveNetworkError(indexRoute)],
  ])('names the failure when %s', async (_name, serve) => {
    serve();

    const { load, shares, error, loading } = useSegmentShares(SEGMENT_ID);

    await load();

    expect(shares.value).toEqual([]);
    expect(error.value).toBe(LOAD_FALLBACK);
    expect(loading.value).toBe(false);
  });
});

describe('useSegmentShares — creating a share', () => {
  it('keeps the created share and clears the error on success', async () => {
    const served = serveJson(storeRoute, { data: teamShare }, { status: 201 });

    const { create, shares, error } = useSegmentShares(SEGMENT_ID);

    const created = await create({
      grantee_type: 'team',
      grantee_id: TEAM_ID,
      can_edit: true,
    });

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares`);
    expectSession(served);
    expect(served.lastRequest()?.body).toEqual({
      grantee_type: 'team',
      grantee_id: TEAM_ID,
      can_edit: true,
    });
    expect(created?.grantee_type).toBe('team');
    expect(shares.value).toHaveLength(1);
    expect(error.value).toBeNull();
  });

  it.each<[string, () => ServedRoute, string]>([
    [
      'shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          storeRoute,
          {
            message: 'Dieses Team hat das Segment bereits.',
            errors: { grantee_id: ['egal'] },
          },
          { status: 422 },
        ),
      'Dieses Team hat das Segment bereits.',
    ],
    [
      'shows the validation message when the server names only the field',
      () =>
        serveValidationError(storeRoute, {
          grantee_id: ['Bitte wählen Sie ein Team.'],
        }),
      'Bitte wählen Sie ein Team.',
    ],
    [
      'keeps the German fallback when the refusal carries no message',
      () => serveServerError(storeRoute, {}),
      CREATE_FALLBACK,
    ],
    [
      'keeps the German fallback when the request never reaches the server',
      () => serveNetworkError(storeRoute),
      CREATE_FALLBACK,
    ],
  ])('%s', async (_name, serve, expectedError) => {
    serve();

    const { create, shares, error } = useSegmentShares(SEGMENT_ID);

    await expect(
      create({
        grantee_type: 'team',
        grantee_id: TEAM_ID,
        can_edit: true,
      }),
    ).resolves.toBeNull();

    expect(error.value).toBe(expectedError);
    expect(shares.value).toEqual([]);
  });
});

describe('useSegmentShares — revoking a share', () => {
  it('deletes the share and drops it from the list', async () => {
    serveJson(indexRoute, { data: [teamShare] });
    const served = serveEmpty(destroyRoute);

    const { load, revoke, shares, error } = useSegmentShares(SEGMENT_ID);

    await load();
    await expect(revoke(SHARE_ID)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares/${SHARE_ID}`);
    expectSession(served);
    expect(shares.value).toEqual([]);
    expect(error.value).toBeNull();
  });

  it.each<[string, () => ServedRoute, string]>([
    [
      'shows the message the server sent instead of a fixed sentence',
      () => serveJson(destroyRoute, { message: 'Sie dürfen diese Freigabe nicht widerrufen.' }, { status: 403 }),
      'Sie dürfen diese Freigabe nicht widerrufen.',
    ],
    [
      'keeps the German fallback when the request never reaches the server',
      () => serveNetworkError(destroyRoute),
      REVOKE_FALLBACK,
    ],
  ])('%s', async (_name, serve, expectedError) => {
    serve();

    const { revoke, error } = useSegmentShares(SEGMENT_ID);

    await expect(revoke(SHARE_ID)).resolves.toBe(false);

    expect(error.value).toBe(expectedError);
  });
});

describe('fetchGranteeOptions', () => {
  it('reads the grantee options of the segment per grantee type', async () => {
    const served = serveJson(optionsRoute, {
      options: {
        team: [{ value: TEAM_ID, label: 'Vertrieb' }],
        user: [{ value: 'u-1', label: 'Ada' }],
      },
    });

    const options = await fetchGranteeOptions(SEGMENT_ID);

    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/share-options`);
    expectSession(served);
    expect(options.team).toEqual([{ id: TEAM_ID, label: 'Vertrieb' }]);
    expect(options.user).toEqual([{ id: 'u-1', label: 'Ada' }]);
    expect(options.role).toEqual([]);
  });

  it('rejects when the server refuses the options', async () => {
    serveServerError(optionsRoute);

    await expect(fetchGranteeOptions(SEGMENT_ID)).rejects.toThrow();
  });
});
