import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { duplicate as duplicateRoute } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { serveEmpty, serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordPayload } from '../../../../shared/business-data/types/records';
import { useRecordDuplicate } from './useRecordDuplicate';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

const toastError = vi.fn();
const recordToast = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('../../data/utils/recordToast', () => ({
  recordToast: (...args: unknown[]) => recordToast(...args),
}));

setUrlDefaults({ activeTeam: 'nubos' });

const record = {
  id: '01RECORD0K5N3Q8V9WYE6M2H7X',
  objectTypeId: 'ot-1',
  recordNumber: 'REC-1',
  title: 'Angebot A',
  externalReferenceId: null,
  version: 3,
  data: {},
} as unknown as RecordPayload;

const route = duplicateRoute({ record: record.id });

const genericError = 'Der Datensatz konnte nicht dupliziert werden.';

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockClear();
  recordToast.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useRecordDuplicate', () => {
  it('posts to the duplicate endpoint of this record', async () => {
    const served = serveJson(route, { data: { ...record, id: 'copy-1' } }, { status: 201 });

    const onDuplicated = vi.fn();
    const { duplicate } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${record.id}/duplicate`);
    expect(served.lastRequest()?.body).toStrictEqual({});
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
  });

  it('hands the copy back to the caller', async () => {
    serveJson(route, { data: { ...record, id: 'copy-1' } }, { status: 201 });

    const onDuplicated = vi.fn();
    const { duplicate } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(onDuplicated).toHaveBeenCalledWith(expect.objectContaining({ id: 'copy-1' }));
    expect(recordToast).toHaveBeenCalledWith('create', expect.any(Function));
    expect(toastError).not.toHaveBeenCalled();
  });

  it('warns and hands nothing back when the server refuses', async () => {
    serveJson(route, { message: 'no' }, { status: 403 });

    const onDuplicated = vi.fn();
    const { duplicate } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(onDuplicated).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith('no');
  });

  it('shows the reason the server refused the duplicate with', async () => {
    serveValidationError(route, {
      'data.title': ['Das Pflichtfeld „Titel" fehlt in der Kopie.'],
    });

    const onDuplicated = vi.fn();
    const { duplicate } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(onDuplicated).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith('Das Pflichtfeld „Titel" fehlt in der Kopie.');
  });

  it('warns with the generic notice when the server answers without a reason', async () => {
    serveEmpty(route, { status: 500 });

    const onDuplicated = vi.fn();
    const { duplicate, duplicating } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(onDuplicated).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith(genericError);
    expect(duplicating.value).toBe(false);
  });

  it('warns with the generic notice when the connection fails', async () => {
    serveNetworkError(route);

    const onDuplicated = vi.fn();
    const { duplicate, duplicating } = useRecordDuplicate(onDuplicated);

    await duplicate(record);

    expect(onDuplicated).not.toHaveBeenCalled();
    expect(recordToast).not.toHaveBeenCalled();
    expect(toastError).toHaveBeenCalledWith(genericError);
    expect(duplicating.value).toBe(false);
  });

  it('asks the server only once while a duplicate is still running', async () => {
    const served = serveJson(route, { data: { ...record, id: 'copy-1' } }, { status: 201, held: true });

    const { duplicate, duplicating } = useRecordDuplicate(vi.fn());

    const first = duplicate(record);
    await duplicate(record);
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(duplicating.value).toBe(true);

    served.release();
    await first;

    expect(served.requests).toHaveLength(1);
    expect(duplicating.value).toBe(false);
  });
});
