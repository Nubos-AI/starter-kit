import type { CellValueChangedEvent } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { updateCell } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import type { RecordPayload } from '../../../../shared/business-data/types/records';
import { useInlineSave } from './useInlineSave';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const toastSuccess = vi.fn();
const toastError = vi.fn();
const recordToastMock = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: {
    success: (...args: unknown[]) => toastSuccess(...args),
    error: (...args: unknown[]) => toastError(...args),
  },
}));

vi.mock('../../data/utils/recordToast', () => ({
  recordToast: (...args: unknown[]) => recordToastMock(...args),
}));

interface FakeNode {
  data: RecordPayload;
  setData: (value: RecordPayload) => void;
}

function makeEvent(): {
  event: CellValueChangedEvent<RecordPayload>;
  node: FakeNode;
} {
  const node: FakeNode = {
    data: {
      id: 'r1',
      version: 3,
      data: { title: 'old' },
    } as unknown as RecordPayload,
    setData(value: RecordPayload) {
      this.data = value;
    },
  };
  vi.spyOn(node, 'setData');

  const event = {
    node,
    column: { getColId: () => 'title' },
    newValue: 'new',
    oldValue: 'old',
  } as unknown as CellValueChangedEvent<RecordPayload>;

  return { event, node };
}

const cellRoute = updateCell({ record: 'r1' });

const saveErrorMessage = 'Die Änderung konnte nicht gespeichert werden und wurde zurückgesetzt.';

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastSuccess.mockClear();
  toastError.mockClear();
  recordToastMock.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useInlineSave — auto-save on cell change (SC-7)', () => {
  it('PATCHes the cell endpoint with field, value and optimistic version', async () => {
    const served = serveJson(cellRoute, {
      id: 'r1',
      version: 4,
      data: { title: 'new' },
    });

    const { onCellValueChanged } = useInlineSave();
    const { event } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(recordToastMock).toHaveBeenCalled());

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PATCH');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/r1/cell');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.body).toEqual({
      field: 'title',
      value: 'new',
      version: 3,
    });
  });

  it('commits the server row and shows an edit record toast on 200', async () => {
    serveJson(cellRoute, {
      id: 'r1',
      version: 4,
      data: { title: 'new' },
    });

    const { onCellValueChanged, conflictOpen, announcement } = useInlineSave();
    const { event, node } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(recordToastMock).toHaveBeenCalledWith('edit', expect.any(Function)));
    expect(node.setData).toHaveBeenCalledWith(expect.objectContaining({ version: 4 }));
    expect(conflictOpen.value).toBe(false);
    expect(toastError).not.toHaveBeenCalled();
    await vi.waitFor(() => expect(announcement.value).toBe('Änderung gespeichert.'));
  });

  it('opens the conflict dialog and refreshes the row on a 409 version conflict', async () => {
    serveJson(
      cellRoute,
      {
        id: 'r1',
        version: 9,
        data: { title: 'server-wins' },
      },
      { status: 409 },
    );

    const { onCellValueChanged, conflictOpen, closeConflict } = useInlineSave();
    const { event, node } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(conflictOpen.value).toBe(true));
    expect(node.setData).toHaveBeenCalledWith(expect.objectContaining({ version: 9 }));
    expect(toastSuccess).not.toHaveBeenCalled();

    closeConflict();
    expect(conflictOpen.value).toBe(false);
  });

  it('shows the field reason the server sent with a 422 rejection', async () => {
    serveValidationError(cellRoute, {
      'data.title': ['Der Titel ist zu lang.'],
    });

    const { onCellValueChanged, announcement } = useInlineSave();
    const { event, node } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
    expect(toastError).toHaveBeenCalledWith('Der Titel ist zu lang.');
    await vi.waitFor(() => expect(announcement.value).toBe('Der Titel ist zu lang.'));
    expect(node.data.data.title).toBe('old');
  });

  it('falls back to the top level message when no field error matches', async () => {
    serveJson(cellRoute, { message: 'Sie dürfen dieses Feld nicht bearbeiten.' }, { status: 403 });

    const { onCellValueChanged } = useInlineSave();
    const { event } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
    expect(toastError).toHaveBeenCalledWith('Sie dürfen dieses Feld nicht bearbeiten.');
  });

  it('reverts the cell and shows an error toast on a non-2xx failure', async () => {
    serveServerError(cellRoute, {});

    const { onCellValueChanged } = useInlineSave();
    const { event, node } = makeEvent();
    onCellValueChanged(event);

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
    expect(toastError).toHaveBeenCalledWith(saveErrorMessage);
    expect(node.data.data.title).toBe('old');
    expect(node.setData).toHaveBeenCalled();
  });

  it('reverts the cell and shows the save error when the network drops the request', async () => {
    serveNetworkError(cellRoute);

    const { onCellValueChanged, announcement } = useInlineSave();
    const { event, node } = makeEvent();
    node.data = {
      ...node.data,
      data: { title: 'new' },
    } as unknown as RecordPayload;
    onCellValueChanged(event);

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledOnce());
    expect(toastError).toHaveBeenCalledWith(saveErrorMessage);
    expect(node.data.data.title).toBe('old');
    await vi.waitFor(() => expect(announcement.value).toBe(saveErrorMessage));
  });

  it('sends the next change of the same record only after the previous save answered', async () => {
    const served = serveJson(cellRoute, { id: 'r1', version: 4, data: { title: 'new' } }, { held: true });

    const { onCellValueChanged } = useInlineSave();
    const { event, node } = makeEvent();
    onCellValueChanged(event);
    onCellValueChanged(event);

    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    await new Promise((resolve) => setTimeout(resolve, 20));

    expect(served.requests).toHaveLength(1);

    served.release();

    await vi.waitFor(() => expect(served.requests).toHaveLength(2));
    expect(served.requests[1].body).toEqual({
      field: 'title',
      value: 'new',
      version: 4,
    });
    await vi.waitFor(() => expect(recordToastMock).toHaveBeenCalledTimes(2));
    expect(node.data.version).toBe(4);
  });
});
