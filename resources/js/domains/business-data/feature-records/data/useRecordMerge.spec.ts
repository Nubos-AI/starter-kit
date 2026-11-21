import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordMergeController from '@/actions/App/Http/Controllers/Engine/RecordMergeController';
import type { ServedRouteDefinition } from '@/shared/testing/msw';
import { serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { MergePlan, MergeRequestPayload } from '../../data/types/merge';
import { useRecordMerge } from './useRecordMerge';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const toastError = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: { error: (...args: unknown[]) => toastError(...args) },
}));

const RECORD_ID = '01RECORD0K5N3Q8V9WYE6M2H7X';

const payload: MergeRequestPayload = {
  targetId: RECORD_ID,
  sourceId: '01SOURCE0K5N3Q8V9WYE6M2H7X',
  reason: null,
  overrides: {},
};

const plan = {
  target_id: RECORD_ID,
  source_id: payload.sourceId,
  target_version: 1,
  source_version: 1,
  mode: 'allow',
  rule_id: null,
  rule_name: null,
  deny_reason: null,
  requires_reason: false,
  is_mergeable: true,
  fields: [],
  transfers: [],
  blockers: [],
} as MergePlan;

function previewRoute(): ServedRouteDefinition {
  return RecordMergeController.preview({ record: RECORD_ID });
}

function storeRoute(): ServedRouteDefinition {
  return RecordMergeController.store({ record: RECORD_ID });
}

function undoRoute(): ServedRouteDefinition {
  return RecordMergeController.undo({ record: RECORD_ID, merge: 'm-1' });
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useRecordMerge — the preview', () => {
  it('posts to the preview endpoint of this record and keeps the plan', async () => {
    const served = serveJson(previewRoute(), { data: plan });

    const merge = useRecordMerge(vi.fn());

    await merge.preview(RECORD_ID, payload);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/merge/preview`);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual(payload);
    expect(merge.plan.value).toEqual(plan);
    expect(merge.previewing.value).toBe(false);
  });

  it.each<[string, number, Record<string, unknown>, string]>([
    [
      'reports a failing preview and leaves the plan alone',
      500,
      {},
      'Die Vorschau für das Zusammenführen konnte nicht geladen werden.',
    ],
    [
      'shows the reason the server refused the preview with',
      422,
      {
        message: 'Die Zieldatensätze passen nicht zusammen.',
        errors: { sourceId: ['Ungültiger Datensatz.'] },
      },
      'Die Zieldatensätze passen nicht zusammen.',
    ],
  ])('%s', async (_name, status, body, expectedToast) => {
    serveJson(previewRoute(), body, { status });

    const merge = useRecordMerge(vi.fn());

    await merge.preview(RECORD_ID, payload);

    expect(toastError).toHaveBeenCalledTimes(1);
    expect(toastError).toHaveBeenCalledWith(expectedToast);
    expect(merge.plan.value).toBeNull();
  });

  it('survives a network failure without leaving the flag set', async () => {
    serveNetworkError(previewRoute());

    const merge = useRecordMerge(vi.fn());

    await merge.preview(RECORD_ID, payload);

    expect(toastError).toHaveBeenCalledTimes(1);
    expect(toastError).toHaveBeenCalledWith('Die Vorschau für das Zusammenführen konnte nicht geladen werden.');
    expect(merge.previewing.value).toBe(false);
  });

  it('marks the preview as running and asks only once until the server answers', async () => {
    const served = serveJson(previewRoute(), { data: plan }, { held: true });

    const merge = useRecordMerge(vi.fn());

    const first = merge.preview(RECORD_ID, payload);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(merge.previewing.value).toBe(true);
    await merge.preview(RECORD_ID, payload);

    served.release();
    await first;

    expect(served.requests).toHaveLength(1);
    expect(merge.previewing.value).toBe(false);
    expect(merge.plan.value).toEqual(plan);
  });
});

describe('useRecordMerge — the merge', () => {
  it('hands the result to the caller once the merge was accepted', async () => {
    const result = {
      merge_id: 'm-1',
      target_id: RECORD_ID,
      source_id: payload.sourceId,
    };

    const served = serveJson(storeRoute(), { data: result }, { status: 201 });

    const onMerged = vi.fn();
    const merge = useRecordMerge(onMerged);

    const returned = await merge.merge(RECORD_ID, payload);

    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/merge`);
    expect(served.lastRequest()?.body).toEqual(payload);
    expect(returned).toEqual(result);
    expect(onMerged).toHaveBeenCalledWith(result);
    expect(merge.merging.value).toBe(false);
  });

  it.each<[string, number, Record<string, unknown>, string]>([
    [
      'treats a refused merge as a failure and calls nobody back',
      422,
      {
        blockers: [{ reason: 'rule_forbids', detail: 'Nein.' }],
      },
      'Die Datensätze konnten nicht zusammengeführt werden.',
    ],
    [
      'shows the reason the server refused the merge with',
      422,
      {
        message: 'Das Zusammenführen wurde abgelehnt.',
        blockers: [{ reason: 'rule_forbids', detail: 'Nein.' }],
      },
      'Das Zusammenführen wurde abgelehnt.',
    ],
  ])('%s', async (_name, status, body, expectedToast) => {
    serveJson(storeRoute(), body, { status });

    const onMerged = vi.fn();
    const merge = useRecordMerge(onMerged);

    expect(await merge.merge(RECORD_ID, payload)).toBeNull();
    expect(onMerged).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledTimes(1);
    expect(toastError).toHaveBeenCalledWith(expectedToast);
  });

  it('keeps the field errors of a refused merge for the form', async () => {
    serveValidationError(storeRoute(), {
      sourceId: ['Bitte wählen Sie einen Datensatz.'],
    });

    const merge = useRecordMerge(vi.fn());

    expect(await merge.merge(RECORD_ID, payload)).toBeNull();
    expect(merge.errors.value).toEqual({
      sourceId: 'Bitte wählen Sie einen Datensatz.',
    });
  });

  it('warns with the generic notice when the connection fails during the merge', async () => {
    serveNetworkError(storeRoute());

    const onMerged = vi.fn();
    const merge = useRecordMerge(onMerged);

    expect(await merge.merge(RECORD_ID, payload)).toBeNull();
    expect(onMerged).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith('Die Datensätze konnten nicht zusammengeführt werden.');
    expect(merge.merging.value).toBe(false);
  });

  it('refuses to fire twice while one merge is still running', async () => {
    const served = serveJson(
      storeRoute(),
      {
        data: {
          merge_id: 'm-1',
          target_id: RECORD_ID,
          source_id: payload.sourceId,
        },
      },
      { status: 201, held: true },
    );

    const merge = useRecordMerge(vi.fn());

    const first = merge.merge(RECORD_ID, payload);
    const second = await merge.merge(RECORD_ID, payload);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(second).toBeNull();
    expect(merge.merging.value).toBe(true);

    served.release();
    await first;

    expect(served.requests).toHaveLength(1);
    expect(merge.merging.value).toBe(false);
  });
});

describe('useRecordMerge — taking a merge back', () => {
  it('posts to the undo endpoint of this record and returns the report', async () => {
    const report = {
      merge_id: 'm-1',
      target_id: RECORD_ID,
      source_id: payload.sourceId,
      restored_fields: ['volume'],
      kept_fields: [],
      restored_transfers: { notes: 2 },
      unrecoverable: { links: 1 },
    };

    const served = serveJson(undoRoute(), { data: report });

    const merge = useRecordMerge(vi.fn());

    expect(await merge.undo(RECORD_ID, 'm-1')).toEqual(report);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${RECORD_ID}/merge/m-1/undo`);
    expect(served.lastRequest()?.body).toStrictEqual({});
    expect(merge.undoing.value).toBe(false);
  });

  it.each<[string, number, Record<string, unknown>, string]>([
    [
      'reports a refused undo instead of pretending it worked',
      422,
      { reason: 'window_expired' },
      'Das Zusammenführen konnte nicht zurückgenommen werden.',
    ],
    [
      'shows the reason the server refused the undo with',
      422,
      {
        message: 'Das Zusammenführen lässt sich nicht mehr zurücknehmen.',
        reason: 'window_expired',
      },
      'Das Zusammenführen lässt sich nicht mehr zurücknehmen.',
    ],
  ])('%s', async (_name, status, body, expectedToast) => {
    serveJson(undoRoute(), body, { status });

    const merge = useRecordMerge(vi.fn());

    expect(await merge.undo(RECORD_ID, 'm-1')).toBeNull();
    expect(toastError).toHaveBeenCalledTimes(1);
    expect(toastError).toHaveBeenCalledWith(expectedToast);
  });

  it('warns with the generic notice when the connection fails during the undo', async () => {
    serveNetworkError(undoRoute());

    const merge = useRecordMerge(vi.fn());

    expect(await merge.undo(RECORD_ID, 'm-1')).toBeNull();
    expect(toastError).toHaveBeenCalledWith('Das Zusammenführen konnte nicht zurückgenommen werden.');
    expect(merge.undoing.value).toBe(false);
  });

  it('marks the undo as running and asks only once until the server answers', async () => {
    const served = serveJson(
      undoRoute(),
      {
        data: {
          merge_id: 'm-1',
          target_id: RECORD_ID,
          source_id: payload.sourceId,
          restored_fields: [],
          kept_fields: [],
          restored_transfers: {},
          unrecoverable: {},
        },
      },
      { held: true },
    );

    const merge = useRecordMerge(vi.fn());

    const first = merge.undo(RECORD_ID, 'm-1');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(merge.undoing.value).toBe(true);
    expect(await merge.undo(RECORD_ID, 'm-1')).toBeNull();

    served.release();
    await first;

    expect(served.requests).toHaveLength(1);
    expect(merge.undoing.value).toBe(false);
  });
});
