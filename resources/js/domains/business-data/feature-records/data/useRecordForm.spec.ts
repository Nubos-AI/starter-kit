import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { store, update } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import type { ServedRoute, ServedRouteDefinition } from '@/shared/testing/msw';
import { serveEmpty, serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordObjectType, RecordPayload } from '../../../../shared/business-data/types/records';
import { useRecordForm } from './useRecordForm';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const toastError = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('../../data/utils/recordToast', () => ({ recordToast: vi.fn() }));

setUrlDefaults({ activeTeam: 'nubos' });

const objectType: RecordObjectType = {
  id: 'ot-1',
  key: 'deals',
  slug: 'deals',
  name: 'Deals',
  label: 'Verkaufschancen',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const record = {
  id: '01RECORD0K5N3Q8V9WYE6M2H7X',
  objectTypeId: 'ot-1',
  ownerId: 'u-1',
  recordNumber: 'REC-1',
  title: 'Angebot A',
  externalReferenceId: null,
  version: 3,
  data: { titel: 'Angebot A' },
} as unknown as RecordPayload;

const updateRoute = update({ record: record.id });

const storeRoute = store({ objectType });

function serveUpdate(status: number, body: Parameters<typeof serveJson>[1]): ServedRoute {
  return serveJson(updateRoute, body, { status });
}

function buildForm(onSaved: (saved: RecordPayload) => void = vi.fn()): ReturnType<typeof useRecordForm> {
  return useRecordForm({
    mode: 'edit',
    objectType,
    record,
    collaboratorIds: ['u-2'],
    onSaved,
  });
}

function lastBody(served: ServedRoute): Record<string, unknown> {
  const body = served.lastRequest()?.body;

  if (body === null || typeof body !== 'object' || Array.isArray(body)) {
    throw new Error('the record save carries no JSON object');
  }

  return body as Record<string, unknown>;
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useRecordForm — the assignment saves on its own', () => {
  it('sends owner and collaborators with the current version and no field data', async () => {
    const served = serveUpdate(200, {
      data: { ...record, version: 4, ownerId: 'u-9' },
    });

    const form = buildForm();
    form.ownerId.value = 'u-9';
    await form.saveAssignment();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${record.id}`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(lastBody(served)).toEqual({
      version: 3,
      owner_id: 'u-9',
      collaborator_ids: ['u-2'],
    });
  });

  it('carries the version the server answered into the next save', async () => {
    const served = serveUpdate(200, { data: { ...record, version: 4 } });

    const form = buildForm();
    form.ownerId.value = 'u-9';
    await form.saveAssignment();

    form.collaboratorIds.value = ['u-3'];
    await form.saveAssignment();

    expect(served.requests).toHaveLength(2);
    expect(lastBody(served).version).toBe(4);
  });

  it('puts the owner back and warns when the server refuses', async () => {
    serveUpdate(403, { message: 'nope' });

    const form = buildForm();
    form.ownerId.value = 'u-9';
    await form.saveAssignment();

    expect(form.ownerId.value).toBe('u-1');
    expect(toastError).toHaveBeenCalledWith('Die Zuständigkeit konnte nicht gespeichert werden.');
  });

  it('puts the collaborators back when the server refuses', async () => {
    serveValidationError(updateRoute, {
      collaborator_ids: ['Die Auswahl ist ungültig.'],
    });

    const form = buildForm();
    form.collaboratorIds.value = ['u-2', 'u-3'];
    await form.saveAssignment();

    expect(form.collaboratorIds.value).toEqual(['u-2']);
    expect(toastError).toHaveBeenCalledWith('Die Zuständigkeit konnte nicht gespeichert werden.');
  });

  it('puts the assignment back and warns when the connection fails', async () => {
    serveNetworkError(updateRoute);

    const form = buildForm();
    form.ownerId.value = 'u-9';
    form.collaboratorIds.value = ['u-3'];
    await form.saveAssignment();

    expect(form.ownerId.value).toBe('u-1');
    expect(form.collaboratorIds.value).toEqual(['u-2']);
    expect(form.saving.value).toBe(false);
    expect(toastError).toHaveBeenCalledWith('Die Zuständigkeit konnte nicht gespeichert werden.');
  });

  it('marks the assignment as saving and ignores a second save until the server answers', async () => {
    const served = serveJson(updateRoute, { data: { ...record, version: 4 } }, { held: true });

    const form = buildForm();
    form.ownerId.value = 'u-9';
    const saving = form.saveAssignment();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(form.saving.value).toBe(true);
    await form.saveAssignment();

    served.release();
    await saving;

    expect(served.requests).toHaveLength(1);
    expect(form.saving.value).toBe(false);
  });

  it('opens the conflict dialog and restores the assignment on a stale version', async () => {
    serveUpdate(409, { data: { ...record, version: 7 } });

    const form = buildForm();
    form.ownerId.value = 'u-9';
    await form.saveAssignment();

    expect(form.conflictOpen.value).toBe(true);
    expect(form.ownerId.value).toBe('u-1');
    expect(form.currentRecord.value?.version).toBe(7);
  });

  it('keeps unsaved field edits untouched while the assignment saves', async () => {
    serveUpdate(200, { data: { ...record, version: 4 } });

    const form = buildForm();
    form.values.value = { titel: 'Angebot B' };
    form.ownerId.value = 'u-9';
    await form.saveAssignment();

    expect(form.values.value).toEqual({ titel: 'Angebot B' });
  });
});

describe('useRecordForm — a rejected save is visible', () => {
  function buildCreateForm(onSaved: (saved: RecordPayload) => void = vi.fn()): ReturnType<typeof useRecordForm> {
    return useRecordForm({
      mode: 'create',
      objectType,
      record: null,
      onSaved,
    });
  }

  it.each<
    [
      string,
      (onSaved: (saved: RecordPayload) => void) => ReturnType<typeof useRecordForm>,
      ServedRouteDefinition,
      string,
      string,
    ]
  >([
    [
      'editing',
      (onSaved) => buildForm(onSaved),
      updateRoute,
      'ux6_kategorie',
      'ux6_kategorie contains an invalid value.',
    ],
    [
      'creating',
      (onSaved) => buildCreateForm(onSaved),
      storeRoute,
      'customer_no',
      'customer_no has already been taken.',
    ],
  ])(
    'binds a rejected field to the key the form renders and reports the server reason as a toast when %s',
    async (_mode, build, route, fieldKey, message) => {
      const onSaved = vi.fn();
      const served = serveValidationError(route, {
        [`data.${fieldKey}`]: [message],
      });

      const form = build(onSaved);
      const isSaved = await form.submit();

      expect(isSaved).toBe(false);
      expect(served.requests).toHaveLength(1);
      expect(form.errors.value).toEqual({ [fieldKey]: message });
      expect(toastError).toHaveBeenCalledWith(message);
      expect(onSaved).not.toHaveBeenCalled();
    },
  );

  it('stays visible when the server names no field at all', async () => {
    serveUpdate(422, {});

    const form = buildForm();
    await form.submit();

    expect(form.errors.value).toEqual({});
    expect(toastError).toHaveBeenCalledWith('Der Datensatz konnte nicht gespeichert werden.');
  });

  it('keeps a field error that carries no data prefix', async () => {
    serveValidationError(updateRoute, {
      version: ['The version field is required.'],
    });

    const form = buildForm();
    await form.submit();

    expect(form.errors.value).toEqual({
      version: 'The version field is required.',
    });
  });

  it('warns with the generic notice when the server fails', async () => {
    const onSaved = vi.fn();
    serveEmpty(updateRoute, { status: 500 });

    const form = buildForm(onSaved);
    const isSaved = await form.submit();

    expect(isSaved).toBe(false);
    expect(onSaved).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith('Der Datensatz konnte nicht gespeichert werden.');
  });

  it('warns with the generic notice when the connection fails', async () => {
    const onSaved = vi.fn();
    serveNetworkError(storeRoute);

    const form = buildCreateForm(onSaved);
    const isSaved = await form.submit();

    expect(isSaved).toBe(false);
    expect(onSaved).not.toHaveBeenCalled();
    expect(form.saving.value).toBe(false);
    expect(toastError).toHaveBeenCalledWith('Der Datensatz konnte nicht gespeichert werden.');
  });

  it('takes over the fresh record and opens the conflict dialog on a stale version', async () => {
    const onSaved = vi.fn();
    serveUpdate(409, {
      data: { ...record, version: 7, data: { titel: 'Fremd' } },
    });

    const form = buildForm(onSaved);
    form.values.value = { titel: 'Angebot B' };
    const isSaved = await form.submit();

    expect(isSaved).toBe(false);
    expect(form.conflictOpen.value).toBe(true);
    expect(form.values.value).toEqual({ titel: 'Fremd' });
    expect(form.currentRecord.value?.version).toBe(7);
    expect(onSaved).not.toHaveBeenCalled();
  });
});

describe('useRecordForm — a new record is posted to its object type', () => {
  it('posts the values with the assignment and the extension attributes', async () => {
    const onSaved = vi.fn();
    const served = serveJson(storeRoute, { data: { ...record, id: 'new-1' } }, { status: 201 });

    const form = useRecordForm({
      mode: 'create',
      objectType,
      record: null,
      collaboratorIds: ['u-2'],
      attributes: () => ({ probe_id: 'probe-1' }),
      onSaved,
    });
    form.values.value = { titel: 'Neu' };
    form.ownerId.value = 'u-9';
    const isSaved = await form.submit();

    expect(isSaved).toBe(true);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/deals');
    expect(lastBody(served)).toEqual({
      data: { titel: 'Neu' },
      owner_id: 'u-9',
      collaborator_ids: ['u-2'],
      probe_id: 'probe-1',
    });
    expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 'new-1' }));
  });
});

describe('useRecordForm — extension updates travel with the record save', () => {
  it('merges the extension updates into the edit save payload', async () => {
    const served = serveUpdate(200, { data: { ...record, version: 4 } });

    const form = useRecordForm({
      mode: 'edit',
      objectType,
      record,
      updates: () => ({ module_payload: { plan: 'gold' } }),
      onSaved: vi.fn(),
    });

    await form.submit();

    expect(lastBody(served)).toMatchObject({
      version: 3,
      module_payload: { plan: 'gold' },
    });
  });

  it('keeps the extension updates out of the assignment save', async () => {
    const served = serveUpdate(200, { data: { ...record, version: 4 } });

    const form = useRecordForm({
      mode: 'edit',
      objectType,
      record,
      updates: () => ({ module_payload: { plan: 'gold' } }),
      onSaved: vi.fn(),
    });

    await form.saveAssignment();

    expect(lastBody(served)).not.toHaveProperty('module_payload');
  });
});

describe('useRecordForm — an extension refuses the input before it is sent', () => {
  it('shows the refusal, sends nothing and lets the user save again', async () => {
    const served = serveUpdate(200, { data: record });
    let refusal: Record<string, string> = { cost: 'Pflichtfeld.' };

    const form = useRecordForm({
      mode: 'edit',
      objectType,
      record,
      validateExtensions: () => refusal,
      onSaved: vi.fn(),
    });

    await form.submit();

    expect(form.errors.value).toEqual({ cost: 'Pflichtfeld.' });
    expect(form.saving.value).toBe(false);
    expect(served.requests).toHaveLength(0);

    refusal = {};
    await form.submit();

    expect(served.requests).toHaveLength(1);
  });
});

describe('useRecordForm — a group saves only its own fields', () => {
  const groupedRecord = {
    ...record,
    data: { titel: 'Angebot A', betrag: 100, notiz: 'alt' },
  } as unknown as RecordPayload;

  function buildGroupedForm(updates: () => Record<string, unknown> = () => ({})): ReturnType<typeof useRecordForm> {
    return useRecordForm({
      mode: 'edit',
      objectType,
      record: groupedRecord,
      updates,
      onSaved: vi.fn(),
    });
  }

  it('reports a group as changed only when one of its fields differs', () => {
    const form = buildGroupedForm();

    form.values.value = { ...form.values.value, betrag: 200 };

    expect(form.hasChanges(['betrag'])).toBe(true);
    expect(form.hasChanges(['titel', 'notiz'])).toBe(false);
    expect(form.changedValues()).toEqual({ betrag: 200 });
  });

  it('sends only the fields and updates of the saved group', async () => {
    const served = serveUpdate(200, {
      data: {
        ...groupedRecord,
        version: 4,
        data: { titel: 'Angebot A', betrag: 200, notiz: 'alt' },
      },
    });
    const form = buildGroupedForm(() => ({
      module_payload: { plan: 'gold' },
      other_payload: { flag: true },
    }));

    form.values.value = { ...form.values.value, betrag: 200, notiz: 'neu' };

    const isSaved = await form.submit({
      fields: ['betrag'],
      updates: ['module_payload'],
    });

    expect(isSaved).toBe(true);
    expect(lastBody(served)).toEqual({
      data: { betrag: 200 },
      version: 3,
      module_payload: { plan: 'gold' },
    });
    expect(form.hasChanges(['betrag'])).toBe(false);
    expect(form.hasChanges(['notiz'])).toBe(true);
    expect(form.values.value.notiz).toBe('neu');
  });

  it('restores the stored values of a cancelled group and keeps the rest', () => {
    const form = buildGroupedForm();

    form.values.value = { ...form.values.value, betrag: 200, notiz: 'neu' };

    form.revert(['betrag']);

    expect(form.values.value.betrag).toBe(100);
    expect(form.values.value.notiz).toBe('neu');
  });
});
