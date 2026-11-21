import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  destroy as destroyAction,
  index as indexAction,
  preview as previewAction,
  store as storeAction,
  update as updateAction,
} from '@/actions/App/Http/Controllers/Notifications/NotificationRulesController';
import type { ServedRoute } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { FieldDefinition } from '@/shared/types/fields';
import { setUrlDefaults } from '@/wayfinder';
import type { RuleInput } from './useRules';
import { addLeadStage, availableWatchFields, isFieldEncrypted, removeLeadStage, useRules } from './useRules';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const OBJECT_TYPE_ID = '01OBJTYPE0K5N3Q8V9WYE6M2H7';

const OBJECT_TYPE_SLUG = 'companies';
const RULE_ID = '01RULE000K5N3Q8V9WYE6M2H7C';
const SEGMENT_ID = '01SEG0000K5N3Q8V9WYE6M2H7C';

const ACTION_ERROR = 'Die Regel konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.';

const LOAD_ERROR = 'Die Regeln konnten nicht geladen werden. Bitte versuchen Sie es erneut.';

const PREVIEW_ERROR = 'Die Vorschau konnte nicht ermittelt werden. Bitte versuchen Sie es erneut.';

const indexRoute = indexAction({ objectType: OBJECT_TYPE_SLUG });

const storeRoute = storeAction();

const updateRoute = updateAction({ rule: RULE_ID });

const destroyRoute = destroyAction({ rule: RULE_ID });

const previewRoute = previewAction();

const previewScope = {
  object_type_id: OBJECT_TYPE_ID,
  segment_id: SEGMENT_ID,
  filter_definition: null,
};

function makeField(key: string, overrides: Record<string, unknown> = {}): FieldDefinition {
  return {
    key,
    field_type: 'text_short',
    label: key,
    is_required: false,
    ...overrides,
  } as FieldDefinition;
}

function createInput(): RuleInput {
  return {
    object_type_id: OBJECT_TYPE_ID,
    name: 'Due quotes',
    trigger_type: 'date_based',
    config: {
      date_field_key: 'close_date',
      lead_stages: [{ value: 3, unit: 'day' }],
    },
    segment_id: null,
    filter_definition: null,
    action: { notify: true, create_reminder: null },
    is_active: true,
  };
}

function expectSession(served: ServedRoute): void {
  expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
  expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.useRealTimers();
  vi.clearAllMocks();
});

describe('useRules composable — CRUD over Wayfinder actions', () => {
  it('load(objectTypeSlug) GETs the index endpoint and populates rules from {data}', async () => {
    const served = serveJson(indexRoute, {
      data: [
        { id: 'rule-1', name: 'A' },
        { id: 'rule-2', name: 'B' },
      ],
    });

    const { rules, error, loading, load } = useRules();

    await load(OBJECT_TYPE_SLUG);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/notification-rules/${OBJECT_TYPE_SLUG}`);
    expectSession(served);
    expect(rules.value.map((rule) => rule.id)).toEqual(['rule-1', 'rule-2']);
    expect(error.value).toBeNull();
    expect(loading.value).toBe(false);
  });

  it('load(objectTypeSlug) marks the list as loading until the server answers', async () => {
    const served = serveJson(indexRoute, { data: [] }, { held: true });

    const { loading, load } = useRules();
    const loadingRules = load(OBJECT_TYPE_SLUG);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(loading.value).toBe(true);

    served.release();
    await loadingRules;

    expect(loading.value).toBe(false);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(indexRoute)],
    ['the connection fails', () => serveNetworkError(indexRoute)],
  ])('load(objectTypeSlug) names the failure when %s', async (_name, serve) => {
    serve();

    const { rules, error, loading, load } = useRules();

    await load(OBJECT_TYPE_SLUG);

    expect(rules.value).toEqual([]);
    expect(error.value).toBe(LOAD_ERROR);
    expect(loading.value).toBe(false);
  });

  it('create(input) POSTs the store endpoint carrying the full rule payload', async () => {
    const served = serveJson(storeRoute, { data: { id: RULE_ID, name: 'Due quotes' } }, { status: 201 });

    const { create, error } = useRules();

    const result = await create(createInput());

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/notification-rules');
    expectSession(served);
    expect(served.lastRequest()?.body).toEqual({
      object_type_id: OBJECT_TYPE_ID,
      name: 'Due quotes',
      trigger_type: 'date_based',
      config: {
        date_field_key: 'close_date',
        lead_stages: [{ value: 3, unit: 'day' }],
      },
      segment_id: null,
      filter_definition: null,
      action: { notify: true, create_reminder: null },
      is_active: true,
    });
    expect(result).toEqual({ id: RULE_ID, name: 'Due quotes' });
    expect(error.value).toBeNull();
  });

  it('update(id, input) PUTs the update endpoint with the changed fields', async () => {
    const served = serveJson(updateRoute, { data: { id: RULE_ID } });

    const { update } = useRules();

    await update(RULE_ID, { ...createInput(), name: 'Umbenannt' });

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/notification-rules/${RULE_ID}`);
    expect(served.lastRequest()?.body).toEqual({
      ...createInput(),
      name: 'Umbenannt',
    });
  });

  it('destroy(id) DELETEs the destroy endpoint and returns true', async () => {
    const served = serveEmpty(destroyRoute);

    const { destroy, error } = useRules();

    const result = await destroy(RULE_ID);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/notification-rules/${RULE_ID}`);
    expectSession(served);
    expect(result).toBe(true);
    expect(error.value).toBeNull();
  });

  it('toggleActive(id, isActive) PUTs the update endpoint carrying only is_active', async () => {
    const served = serveJson(updateRoute, { data: { id: RULE_ID } });

    const { toggleActive } = useRules();

    await toggleActive(RULE_ID, false);

    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.body).toEqual({ is_active: false });
  });
});

describe('useRules composable — preview on scope commit', () => {
  it.each<[number, boolean]>([
    [12, false],
    [5000, true],
  ])('preview(scope) issues exactly one request and lands {count: %s, approximate: %s}', async (count, approximate) => {
    const served = serveJson(previewRoute, { count, approximate });

    const { preview, previewCount, previewApproximate, previewLoading } = useRules();

    await preview(previewScope);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/notification-rules/preview');
    expect(served.lastRequest()?.body).toEqual(previewScope);

    expect(previewCount.value).toBe(count);
    expect(previewApproximate.value).toBe(approximate);
    expect(previewLoading.value).toBe(false);
  });

  it('preview(scope) marks the preview as loading until the server answers', async () => {
    const served = serveJson(previewRoute, { count: 3, approximate: false }, { held: true });

    const { preview, previewLoading } = useRules();
    const previewing = preview(previewScope);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(previewLoading.value).toBe(true);

    served.release();
    await previewing;

    expect(previewLoading.value).toBe(false);
  });
});

describe('useRules composable — failure and abort handling', () => {
  it('create(input) sets a German error, returns null, and does not throw on a non-ok response', async () => {
    serveServerError(storeRoute, {});

    const { create, error } = useRules();

    const result = await create(createInput());

    expect(result).toBeNull();
    expect(error.value).toBe(ACTION_ERROR);
  });

  it.each<[string, () => ServedRoute]>([
    ['the server fails', () => serveServerError(previewRoute, {})],
    ['the connection fails', () => serveNetworkError(previewRoute)],
  ])('preview(scope) sets an error without throwing when %s', async (_name, serve) => {
    serve();

    const { preview, error, previewCount, previewLoading } = useRules();

    await preview({
      object_type_id: OBJECT_TYPE_ID,
      segment_id: null,
      filter_definition: null,
    });

    expect(error.value).toBe(PREVIEW_ERROR);
    expect(previewCount.value).toBeNull();
    expect(previewLoading.value).toBe(false);
  });

  it('create(input) resolves to null on a timed-out request without an uncaught rejection', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const served = serveJson(storeRoute, { data: { id: RULE_ID } }, { held: true });

    const { create, error } = useRules();

    const creating = create(createInput());
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await vi.advanceTimersByTimeAsync(15000);
    const result = await creating;
    served.release();

    expect(served.lastRequest()?.signal.aborted).toBe(true);
    expect(result).toBeNull();
    expect(error.value).toBe(ACTION_ERROR);
  });
});

describe('addLeadStage / removeLeadStage (pure helpers)', () => {
  it('addLeadStage appends a new {value, unit} defaulting to unit "day" and preserves existing stages', () => {
    expect(addLeadStage([])).toEqual([{ value: 0, unit: 'day' }]);
    expect(addLeadStage([{ value: 2, unit: 'week' }])).toEqual([
      { value: 2, unit: 'week' },
      { value: 0, unit: 'day' },
    ]);
  });

  it('removeLeadStage shrinks the list by dropping the entry at the given index', () => {
    const shrunk = removeLeadStage(
      [
        { value: 1, unit: 'day' },
        { value: 2, unit: 'week' },
        { value: 3, unit: 'month' },
      ],
      1,
    ) as Array<{ value: number; unit: string }>;

    expect(shrunk).toEqual([
      { value: 1, unit: 'day' },
      { value: 3, unit: 'month' },
    ]);
  });
});

describe('isFieldEncrypted / availableWatchFields (R-13 encrypted fields disabled)', () => {
  it('isFieldEncrypted is true only for a field flagged is_encrypted', () => {
    expect(isFieldEncrypted(makeField('ssn', { is_encrypted: true }))).toBe(true);
    expect(isFieldEncrypted(makeField('name'))).toBe(false);
    expect(isFieldEncrypted(makeField('phone', { is_encrypted: false }))).toBe(false);
  });

  it('availableWatchFields marks encrypted fields disabled while keeping plain fields selectable', () => {
    const fields = [makeField('name'), makeField('ssn', { is_encrypted: true })];

    const options = availableWatchFields(fields) as Array<{
      key: string;
      disabled: boolean;
    }>;

    expect(options).toHaveLength(2);

    const plain = options.find((option) => option.key === 'name');
    const encrypted = options.find((option) => option.key === 'ssn');

    expect(plain?.disabled).toBe(false);
    expect(encrypted?.disabled).toBe(true);
  });
});

describe('useRules composable — the reason the server gave', () => {
  it.each<[string, () => ServedRoute, (rules: ReturnType<typeof useRules>) => Promise<unknown>, unknown, string]>([
    [
      'create(input) shows the message the server sent instead of a fixed sentence',
      () =>
        serveJson(
          storeRoute,
          {
            message: 'Das Feld ist verschlüsselt.',
            errors: { 'config.date_field_key': ['egal'] },
          },
          { status: 422 },
        ),
      (rules) => rules.create(createInput()),
      null,
      'Das Feld ist verschlüsselt.',
    ],
    [
      'update(id, input) shows the validation message the server sent',
      () =>
        serveValidationError(updateRoute, {
          name: ['Der Name ist bereits vergeben.'],
        }),
      (rules) => rules.update(RULE_ID, createInput()),
      null,
      'Der Name ist bereits vergeben.',
    ],
    [
      'destroy(id) shows the message the server sent instead of a fixed sentence',
      () => serveJson(destroyRoute, { message: 'Sie dürfen diese Regel nicht löschen.' }, { status: 403 }),
      (rules) => rules.destroy(RULE_ID),
      false,
      'Sie dürfen diese Regel nicht löschen.',
    ],
    [
      'destroy(id) keeps the German fallback when the refusal carries no message',
      () => serveServerError(destroyRoute, {}),
      (rules) => rules.destroy(RULE_ID),
      false,
      ACTION_ERROR,
    ],
    [
      'toggleActive(id, isActive) keeps the German fallback when the connection fails',
      () => serveNetworkError(updateRoute),
      (rules) => rules.toggleActive(RULE_ID, true),
      null,
      ACTION_ERROR,
    ],
    [
      'destroy(id) keeps the German fallback when the connection fails',
      () => serveNetworkError(destroyRoute),
      (rules) => rules.destroy(RULE_ID),
      false,
      ACTION_ERROR,
    ],
  ])('%s', async (_name, serve, run, expectedResult, expectedError) => {
    serve();

    const rules = useRules();

    await expect(run(rules)).resolves.toBe(expectedResult);

    expect(rules.error.value).toBe(expectedError);
  });
});

describe('useRules composable — field errors of a refused save', () => {
  it('keeps each validation message under the rule field the server named', async () => {
    serveValidationError(storeRoute, {
      'config.date_field_key': ['Das Datumsfeld steht nicht zur Verfügung.'],
      'config.watched_field_keys': ['Ein beobachtetes Feld fehlt.'],
    });

    const { create, fieldErrors } = useRules();

    await create(createInput());

    expect(fieldErrors.value).toEqual({
      'config.date_field_key': 'Das Datumsfeld steht nicht zur Verfügung.',
      'config.watched_field_keys': 'Ein beobachtetes Feld fehlt.',
    });
  });

  it('clears the field errors once the next save succeeds', async () => {
    serveValidationError(updateRoute, {
      'config.date_field_key': ['Das Datumsfeld steht nicht zur Verfügung.'],
    });

    const { update, fieldErrors } = useRules();

    await update(RULE_ID, createInput());

    serveJson(updateRoute, { data: { id: RULE_ID, name: 'Due quotes' } });

    await update(RULE_ID, createInput());

    expect(fieldErrors.value).toEqual({});
  });
});
