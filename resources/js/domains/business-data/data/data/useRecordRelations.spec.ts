import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordRelationsController from '@/actions/App/Http/Controllers/Engine/RecordRelationsController';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RelationGroup } from '../types/relations';
import { useRecordRelations } from './useRecordRelations';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const RECORD_ID = '01RECORD00000000000000000A';

const RELATIONSHIP_TYPE_ID = '01RELTYPE000000000000000B';

const TARGET_RECORD_ID = '01RECORD00000000000000000C';

const LINK_ID = '01LINK00000000000000000000';

const WRITE_FALLBACK = 'Die Beziehung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

const LOAD_FALLBACK = 'Die Beziehungen konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

setUrlDefaults({ activeTeam: 'nubos' });

const indexRoute = RecordRelationsController.index({ record: RECORD_ID });

const storeRoute = RecordRelationsController.store({ record: RECORD_ID });

const destroyRoute = RecordRelationsController.destroy({
  record: RECORD_ID,
  link: LINK_ID,
});

const entriesRoute = RecordRelationsController.entries({ record: RECORD_ID });

const candidatesRoute = RecordRelationsController.candidates({
  record: RECORD_ID,
});

function bodyOf(served: ServedRoute, index = 0): Record<string, unknown> {
  const body = served.requests[index]?.body;

  if (body === null || typeof body !== 'object' || Array.isArray(body)) {
    throw new Error('the block request carries no JSON object');
  }

  return body as Record<string, unknown>;
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useRecordRelations — loading the groups', () => {
  it('reads the groups of the record', async () => {
    const served = serveJson(indexRoute, {
      data: {
        groups: [
          {
            relationshipTypeId: RELATIONSHIP_TYPE_ID,
            direction: 'outgoing',
          },
        ],
      },
    });

    const relations = useRecordRelations();
    await relations.load(RECORD_ID);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/relations`);
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBeNull();
    expect(relations.groups.value).toHaveLength(1);
    expect(relations.error.value).toBeNull();
    expect(relations.loading.value).toBe(false);
  });

  it('marks the relations as loading until the server answers', async () => {
    const served = serveJson(indexRoute, { data: { groups: [] } }, { held: true });

    const relations = useRecordRelations();
    const loading = relations.load(RECORD_ID);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(relations.loading.value).toBe(true);

    served.release();
    await loading;

    expect(relations.loading.value).toBe(false);
  });

  it.each<[string, (route: typeof indexRoute) => ServedRoute]>([
    ['the server fails', (route) => serveServerError(route)],
    ['the connection fails', (route) => serveNetworkError(route)],
  ])('empties the groups and names the failure when %s', async (_name, serve) => {
    serve(indexRoute);

    const relations = useRecordRelations();
    relations.groups.value = [{} as RelationGroup];
    await relations.load(RECORD_ID);

    expect(relations.groups.value).toEqual([]);
    expect(relations.error.value).toBe(LOAD_FALLBACK);
    expect(relations.loading.value).toBe(false);
  });
});

describe('useRecordRelations — linking a record', () => {
  it('posts the relationship type, direction and target', async () => {
    const served = serveJson(storeRoute, { data: {} }, { status: 201 });

    const relations = useRecordRelations();

    await expect(relations.link(RECORD_ID, RELATIONSHIP_TYPE_ID, 'outgoing', TARGET_RECORD_ID)).resolves.toBe(true);

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/relations`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.body).toEqual({
      relationship_type_id: RELATIONSHIP_TYPE_ID,
      direction: 'outgoing',
      target_record_id: TARGET_RECORD_ID,
    });
    expect(relations.error.value).toBeNull();
  });

  it.each<[string, () => ServedRoute, string]>([
    [
      'shows the field error the server named, not only the summary',
      () =>
        serveJson(
          storeRoute,
          {
            message: 'Die Daten sind ungültig.',
            errors: {
              target_record_id: ['Der Zieldatensatz gehört zu einem anderen Objekttyp.'],
            },
          },
          { status: 422 },
        ),
      'Der Zieldatensatz gehört zu einem anderen Objekttyp.',
    ],
    [
      'shows the message the server sent when no field error names a cause',
      () => serveJson(storeRoute, { message: 'Sie dürfen diese Beziehung nicht anlegen.' }, { status: 403 }),
      'Sie dürfen diese Beziehung nicht anlegen.',
    ],
    [
      'keeps the German fallback when the refusal carries no reason',
      () => serveServerError(storeRoute, {}),
      WRITE_FALLBACK,
    ],
    [
      'keeps the German fallback when the refusal carries no body at all',
      () => serveEmpty(storeRoute, { status: 500 }),
      WRITE_FALLBACK,
    ],
    [
      'keeps the German fallback when the request never reaches the server',
      () => serveNetworkError(storeRoute),
      WRITE_FALLBACK,
    ],
  ])('%s', async (_name, serve, expectedError) => {
    serve();

    const relations = useRecordRelations();

    await expect(relations.link(RECORD_ID, RELATIONSHIP_TYPE_ID, 'outgoing', TARGET_RECORD_ID)).resolves.toBe(false);

    expect(relations.error.value).toBe(expectedError);
    expect(relations.loading.value).toBe(false);
  });
});

describe('useRecordRelations — unlinking a record', () => {
  it('deletes the link of the record', async () => {
    const served = serveEmpty(destroyRoute);

    const relations = useRecordRelations();

    await expect(relations.unlink(RECORD_ID, LINK_ID)).resolves.toBe(true);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/relations/${LINK_ID}`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(relations.error.value).toBeNull();
  });

  it.each<[string, () => ServedRoute, string]>([
    [
      'shows the message the server sent instead of a fixed sentence',
      () =>
        serveValidationError(destroyRoute, {
          link: ['Die Beziehung ist pflichtig.'],
        }),
      'Die Beziehung ist pflichtig.',
    ],
    [
      'keeps the German fallback when the request never reaches the server',
      () => serveNetworkError(destroyRoute),
      WRITE_FALLBACK,
    ],
  ])('%s', async (_name, serve, expectedError) => {
    serve();

    const relations = useRecordRelations();

    await expect(relations.unlink(RECORD_ID, LINK_ID)).resolves.toBe(false);

    expect(relations.error.value).toBe(expectedError);
  });
});

describe('useRecordRelations — loading further options', () => {
  function cappedGroup(loaded: number, total: number): RelationGroup {
    return {
      relationshipTypeId: RELATIONSHIP_TYPE_ID,
      direction: 'outgoing',
      isHierarchy: false,
      objectTypeName: 'Projekte',
      roleLabel: 'Projekte',
      acceptsOne: false,
      canEdit: false,
      candidates: [],
      candidatesTruncated: true,
      entries: Array.from({ length: loaded }, (_, index) => ({
        linkId: `link-${index}`,
        recordId: `record-${index}`,
        recordNumber: `PR-${index}`,
        label: `Projekt ${index}`,
      })),
      entriesTotal: total,
      entriesTotalIsEstimated: false,
      entriesTruncated: true,
    };
  }

  it('asks for the block that starts where the loaded entries end', async () => {
    const served = serveJson(entriesRoute, {
      data: { entries: [], lastRow: 50 },
    });

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(50, 1200)];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    const body = bodyOf(served);

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/relations/entries`);
    expect(body.startRow).toBe(50);
    expect(body.endRow).toBe(100);
    expect(body.relationshipTypeId).toBe(RELATIONSHIP_TYPE_ID);
    expect(body.direction).toBe('outgoing');
    expect(body.search).toBeNull();
  });

  it('appends the block instead of replacing what is already there', async () => {
    serveJson(entriesRoute, {
      data: {
        entries: [
          {
            linkId: 'link-50',
            recordId: 'record-50',
            recordNumber: 'PR-50',
            label: 'Projekt 50',
          },
        ],
        lastRow: null,
      },
    });

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(50, 1200)];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(relations.groups.value[0].entries).toHaveLength(51);
    expect(relations.groups.value[0].entries[50].recordId).toBe('record-50');
  });

  it('never asks twice for the same block while the first ask is still open', async () => {
    const served = serveJson(entriesRoute, { data: { entries: [], lastRow: 50 } }, { held: true });

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(50, 1200)];

    const first = relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);
    const second = relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    served.release();
    await Promise.all([first, second]);

    expect(served.requests).toHaveLength(1);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(entriesRoute)],
    ['the connection fails', () => serveNetworkError(entriesRoute)],
  ])('keeps the loaded entries and names the failure when %s', async (_name, serve) => {
    serve();

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(50, 1200)];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(relations.groups.value[0].entries).toHaveLength(50);
    expect(relations.error.value).toBe(LOAD_FALLBACK);
  });

  it('stops asking once every entry and every candidate is loaded', async () => {
    const entries = serveJson(entriesRoute, {
      data: { entries: [], lastRow: 2 },
    });
    const candidates = serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: false },
    });

    const relations = useRecordRelations();
    relations.groups.value = [{ ...cappedGroup(2, 2), candidatesTruncated: false }];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(entries.requests).toHaveLength(0);
    expect(candidates.requests).toHaveLength(0);
  });

  it('turns to the candidates once every entry is loaded', async () => {
    const entries = serveJson(entriesRoute, {
      data: { entries: [], lastRow: 2 },
    });
    const served = serveJson(candidatesRoute, {
      data: {
        candidates: [{ id: 'record-99', label: 'Projekt 99' }],
        hasMore: false,
      },
    });

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(2, 2)];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(entries.requests).toHaveLength(0);
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/relations/candidates`);
    expect(bodyOf(served).startRow).toBe(0);
    expect(relations.groups.value[0].candidates).toHaveLength(1);
    expect(relations.groups.value[0].candidatesExhausted).toBe(true);
  });

  it('names the failure when the candidates cannot be loaded', async () => {
    serveServerError(candidatesRoute);

    const relations = useRecordRelations();
    relations.groups.value = [cappedGroup(2, 2)];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(relations.groups.value[0].candidates).toEqual([]);
    expect(relations.error.value).toBe(LOAD_FALLBACK);
  });

  it('asks for the candidate block that starts where the loaded candidates end', async () => {
    const served = serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: true },
    });

    const relations = useRecordRelations();
    relations.groups.value = [
      {
        ...cappedGroup(2, 2),
        candidates: [
          { id: 'candidate-0', label: 'Kandidat 0' },
          { id: 'candidate-1', label: 'Kandidat 1' },
        ],
      },
    ];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(bodyOf(served).startRow).toBe(2);
  });
});

describe('useRecordRelations — estimated totals', () => {
  function estimatedGroup(): RelationGroup {
    return {
      relationshipTypeId: RELATIONSHIP_TYPE_ID,
      direction: 'outgoing',
      isHierarchy: false,
      objectTypeName: 'Projekte',
      roleLabel: 'Projekte',
      acceptsOne: false,
      canEdit: false,
      candidates: [],
      candidatesTruncated: true,
      entries: Array.from({ length: 50 }, (_, index) => ({
        linkId: `link-${index}`,
        recordId: `record-${index}`,
        recordNumber: `PR-${index}`,
        label: `Projekt ${index}`,
      })),
      entriesTotal: 1234000,
      entriesTotalIsEstimated: true,
      entriesTruncated: true,
    };
  }

  it('turns to the candidates once the server reports no further entries although the estimate is higher', async () => {
    const candidates = serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: false },
    });
    const entries = serveJson(entriesRoute, {
      data: {
        entries: [],
        total: 1234000,
        isEstimated: true,
        lastRow: 50,
        hasMore: false,
      },
    });

    const relations = useRecordRelations();
    relations.groups.value = [estimatedGroup()];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(entries.requests).toHaveLength(1);
    expect(candidates.requests).toHaveLength(0);

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(entries.requests).toHaveLength(1);
    expect(candidates.requests).toHaveLength(1);
  });

  it('keeps asking for entries while the server reports more of an estimated relation', async () => {
    const candidates = serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: false },
    });
    const entries = serveJson(entriesRoute, {
      data: {
        entries: [],
        total: 1234000,
        isEstimated: true,
        lastRow: null,
        hasMore: true,
      },
    });

    const relations = useRecordRelations();
    relations.groups.value = [estimatedGroup()];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);
    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(entries.requests).toHaveLength(2);
    expect(candidates.requests).toHaveLength(0);
  });
});

describe('useRecordRelations — searching the options', () => {
  function searchGroup(): RelationGroup {
    return {
      relationshipTypeId: RELATIONSHIP_TYPE_ID,
      direction: 'outgoing',
      isHierarchy: false,
      objectTypeName: 'Projekte',
      roleLabel: 'Projekte',
      acceptsOne: false,
      canEdit: true,
      candidates: [{ id: 'candidate-0', label: 'Kandidat 0' }],
      candidatesTruncated: true,
      entries: [
        {
          linkId: 'link-0',
          recordId: 'record-0',
          recordNumber: 'PR-0',
          label: 'Projekt 0',
        },
      ],
      entriesTotal: 1200,
      entriesTotalIsEstimated: false,
      entriesTruncated: true,
    };
  }

  it('asks the server for entries and candidates that match the term', async () => {
    const candidates = serveJson(candidatesRoute, {
      data: {
        candidates: [{ id: 'record-40', label: 'Projekt 40' }],
        hasMore: false,
      },
    });
    const entries = serveJson(entriesRoute, {
      data: {
        entries: [
          {
            linkId: 'link-40',
            recordId: 'record-40b',
            recordNumber: 'PR-40',
            label: 'Projekt 40b',
          },
        ],
        total: 1,
      },
    });

    const relations = useRecordRelations();
    relations.groups.value = [searchGroup()];

    await relations.searchOptions(RECORD_ID, relations.groups.value[0], '40');

    const group = relations.groups.value[0];

    expect(entries.requests).toHaveLength(1);
    expect(candidates.requests).toHaveLength(1);
    expect(bodyOf(entries).search).toBe('40');
    expect(bodyOf(entries).startRow).toBe(0);
    expect(bodyOf(candidates).search).toBe('40');
    expect(bodyOf(candidates).startRow).toBe(0);
    expect(group.optionsSearch).toBe('40');
    expect(group.entries).toHaveLength(1);
    expect(group.entriesMatched).toBe(1);
    expect(group.candidates).toEqual([{ id: 'record-40', label: 'Projekt 40' }]);
    expect(group.candidatesExhausted).toBe(true);
  });

  it('keeps the previous options and names the failure when one half of the search fails', async () => {
    serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: false },
    });
    serveServerError(entriesRoute);

    const relations = useRecordRelations();
    relations.groups.value = [searchGroup()];

    await relations.searchOptions(RECORD_ID, relations.groups.value[0], '40');

    const group = relations.groups.value[0];

    expect(group.entries).toHaveLength(1);
    expect(group.candidates).toEqual([{ id: 'candidate-0', label: 'Kandidat 0' }]);
    expect(relations.error.value).toBe(LOAD_FALLBACK);
  });

  it('names the failure when the search cannot reach the server', async () => {
    serveNetworkError(candidatesRoute);
    serveNetworkError(entriesRoute);

    const relations = useRecordRelations();
    relations.groups.value = [searchGroup()];

    await relations.searchOptions(RECORD_ID, relations.groups.value[0], '40');

    expect(relations.error.value).toBe(LOAD_FALLBACK);
  });

  it('carries the term into every further block it asks for', async () => {
    const served = serveJson(candidatesRoute, {
      data: { candidates: [], hasMore: true },
    });

    const relations = useRecordRelations();
    relations.groups.value = [{ ...searchGroup(), optionsSearch: '40', entriesMatched: 1 }];

    await relations.loadMoreOptions(RECORD_ID, relations.groups.value[0]);

    expect(bodyOf(served).search).toBe('40');
  });
});
