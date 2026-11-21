import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { destroy } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { serveEmpty, serveJson, serveNetworkError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordPayload } from '../../../../shared/business-data/types/records';
import type { UseRecordDeleteReturn } from './useRecordDelete';
import { useRecordDelete } from './useRecordDelete';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const errorToast = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: {
    error: (message: string) => errorToast(message),
  },
}));

const recordToastMock = vi.fn();

vi.mock('../../data/utils/recordToast', () => ({
  recordToast: (action: string) => recordToastMock(action),
}));

const record = {
  id: '01RECORD0K5N3Q8V9WYE6M2H7X',
  recordNumber: 'REC-1',
} as RecordPayload;

const destroyRoute = destroy({ record: record.id });

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('useRecordDelete', () => {
  it('opens the confirmation for the requested record', () => {
    const deletion = useRecordDelete(vi.fn());

    deletion.request(record);

    expect(deletion.pending.value).toStrictEqual(record);
    expect(deletion.isOpen.value).toBe(true);
  });

  it('drops the pending record when the confirmation is cancelled', () => {
    const deletion = useRecordDelete(vi.fn());

    deletion.request(record);
    deletion.cancel();

    expect(deletion.pending.value).toBeNull();
  });

  it('sends the delete request and reports the deletion', async () => {
    const served = serveEmpty(destroyRoute);
    const onDeleted = vi.fn();
    const deletion = useRecordDelete(onDeleted);

    deletion.request(record);
    deletion.reason.value = '  Dublette  ';
    await deletion.confirm();
    await flushPromises();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(served.lastRequest()?.url.pathname).toBe(`/nubos/engine/records/${record.id}`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toStrictEqual({
      deletion_reason: 'Dublette',
    });
    expect(onDeleted).toHaveBeenCalledWith(record);
    expect(recordToastMock).toHaveBeenCalledWith('delete');
    expect(deletion.pending.value).toBeNull();
  });

  it('marks the deletion as running until the server answers', async () => {
    const served = serveEmpty(destroyRoute, { held: true });
    const deletion = useRecordDelete(vi.fn());

    deletion.request(record);
    const confirming = deletion.confirm();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(deletion.deleting.value).toBe(true);
    const repeated = deletion.confirm();

    served.release();
    await Promise.all([confirming, repeated]);
    await flushPromises();

    expect(served.requests).toHaveLength(1);
    expect(deletion.deleting.value).toBe(false);
  });

  it('keeps the record and warns when the server refuses', async () => {
    serveEmpty(destroyRoute, { status: 403 });
    const onDeleted = vi.fn();
    const deletion = useRecordDelete(onDeleted);

    deletion.request(record);
    await deletion.confirm();
    await flushPromises();

    expect(onDeleted).not.toHaveBeenCalled();
    expect(errorToast).toHaveBeenCalledWith('Der Datensatz konnte nicht gelöscht werden.');
    expect(deletion.pending.value).toStrictEqual(record);
  });

  it('shows the reason the server sent instead of a generic notice', async () => {
    serveJson(
      destroyRoute,
      {
        message: 'Dieser Datensatz ist gesperrt und kann nicht gelöscht werden.',
      },
      { status: 403 },
    );
    const onDeleted = vi.fn();
    const deletion = useRecordDelete(onDeleted);

    deletion.request(record);
    await deletion.confirm();
    await flushPromises();

    expect(onDeleted).not.toHaveBeenCalled();
    expect(errorToast).toHaveBeenCalledWith('Dieser Datensatz ist gesperrt und kann nicht gelöscht werden.');
  });

  it('warns with the generic notice when the connection fails', async () => {
    serveNetworkError(destroyRoute);
    const onDeleted = vi.fn();
    const deletion = useRecordDelete(onDeleted);

    deletion.request(record);
    await deletion.confirm();
    await flushPromises();

    expect(onDeleted).not.toHaveBeenCalled();
    expect(errorToast).toHaveBeenCalledWith('Der Datensatz konnte nicht gelöscht werden.');
    expect(deletion.deleting.value).toBe(false);
  });

  it('shows the reason the server rejected the deletion reason with', async () => {
    serveValidationError(destroyRoute, {
      deletion_reason: ['Der Löschgrund darf höchstens 255 Zeichen lang sein.'],
    });
    const onDeleted = vi.fn();
    const deletion = useRecordDelete(onDeleted);

    deletion.request(record);
    await deletion.confirm();
    await flushPromises();

    expect(onDeleted).not.toHaveBeenCalled();
    expect(deletion.reasonError.value).toBe('Der Löschgrund darf höchstens 255 Zeichen lang sein.');
  });

  it('starts closed without a pending record and ignores a confirmation then', async () => {
    const served = serveEmpty(destroyRoute);
    const deletion: UseRecordDeleteReturn = useRecordDelete(vi.fn());

    expect(deletion.pending.value).toBeNull();
    expect(deletion.isOpen.value).toBe(false);

    await deletion.confirm();

    expect(served.requests).toHaveLength(0);
  });
});
