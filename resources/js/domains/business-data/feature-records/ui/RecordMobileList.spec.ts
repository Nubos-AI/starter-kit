import { fireEvent, render, screen } from '@testing-library/vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RecordGridsController from '@/actions/App/Http/Controllers/Engine/RecordGridsController';
import RecordWriteController from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import RecordMobileList from './RecordMobileList.vue';

const toastError = vi.hoisted(() => vi.fn());

vi.mock('vue-sonner', () => ({
  toast: { error: toastError, success: vi.fn() },
}));

vi.mock('@/shared/composables/usePermissions', () => ({
  usePermissions: () => ({ canForObjectType: () => true }),
}));

const objectType = {
  id: 'ot-1',
  key: 'products',
  slug: 'products',
  name: 'Produkte',
  label: 'Produkte',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const row = {
  id: 'record-1',
  objectTypeId: 'ot-1',
  ownerId: null,
  recordNumber: 'PROD-1',
  title: 'Pro-Tarif',
  externalReferenceId: null,
  version: 2,
  data: { price: '49.00', code: 'PRO' },
  suppliedKeys: ['price'],
  createdAt: null,
  updatedAt: null,
};

const columns = ['price', 'code'].map((key, position) => ({
  key,
  label: key,
  field_type: key === 'price' ? ('money' as const) : ('text_short' as const),
  is_sortable: true,
  is_filterable: true,
  is_default_column: true,
  list_position: position,
}));

const LOAD_ERROR = 'Die Datensätze konnten nicht geladen werden. Bitte versuchen Sie es erneut.';
const SAVE_ERROR = 'Die Änderung konnte nicht gespeichert werden und wurde zurückgesetzt.';

function gridRoute() {
  return RecordGridsController.grid({ objectType: 'products' });
}

function cellRoute() {
  return RecordWriteController.updateCell({ record: 'record-1' });
}

function renderList(withColumns = columns) {
  return render(RecordMobileList, {
    props: { objectType, columns: withColumns, fieldDefinitions: [] },
  });
}

function fieldButton(key: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-record-field="${key}"]`);
}

async function editCode(value: string): Promise<void> {
  await fireEvent.click(await screen.findByText('PRO'));

  const input = screen.getByLabelText('code');
  await fireEvent.update(input, value);
  await fireEvent.keyDown(input, { key: 'Enter' });
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=mobile-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('RecordMobileList', () => {
  it('declares the grid request body as JSON so the server reads the requested rows', async () => {
    const served = serveJson(gridRoute(), { rows: [], lastRow: 0 });

    const { emitted } = renderList([]);

    await vi.waitFor(() => expect(emitted('state')?.at(-1)).toEqual(['empty']));

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(gridRoute().url);
    expect(served.lastRequest()?.headers.get('Content-Type')).toBe('application/json');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('mobile-token');
    expect(served.lastRequest()?.body).toEqual({
      startRow: 0,
      endRow: 25,
      sortModel: [],
      filterModel: {},
      search: null,
      segment: null,
    });
  });

  it('reports the loading state until the first page arrives', async () => {
    const served = serveJson(gridRoute(), { rows: [row], lastRow: 1 }, { held: true });

    const { emitted } = renderList();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(emitted('state')?.at(-1)).toEqual(['loading']);
    expect(screen.queryByText('PROD-1')).toBeNull();

    served.release();

    expect(await screen.findByText('PROD-1')).toBeTruthy();
    expect(emitted('state')?.at(-1)).toEqual(['ready']);
  });

  it('shows the load error when the grid endpoint fails', async () => {
    serveServerError(gridRoute());

    const { emitted } = renderList();

    expect((await screen.findByRole('alert')).textContent).toContain(LOAD_ERROR);
    expect(emitted('state')?.at(-1)).toEqual(['error']);
  });

  it('shows the load error when the grid request cannot be sent', async () => {
    serveNetworkError(gridRoute());

    renderList();

    expect((await screen.findByRole('alert')).textContent).toContain(LOAD_ERROR);
  });

  it('opens a record instead of editing a field another app supplies for it', async () => {
    serveJson(gridRoute(), { rows: [row], lastRow: 1 });

    const { emitted } = renderList();

    await fireEvent.click(await screen.findByText('49.00'));

    expect(emitted('openRecord')).toHaveLength(1);
    expect(fieldButton('price')).not.toBeNull();

    await fireEvent.click(screen.getByText('PRO'));

    expect(emitted('openRecord')).toHaveLength(1);
    expect(fieldButton('code')).toBeNull();
  });

  it('saves an inline edit with the record version', async () => {
    serveJson(gridRoute(), { rows: [row], lastRow: 1 });
    const saved = serveJson(cellRoute(), {
      data: { ...row, version: 3, data: { ...row.data, code: 'MAX' } },
    });

    renderList();
    await editCode('MAX');

    expect(await screen.findByText('MAX')).toBeTruthy();
    expect(saved.requests).toHaveLength(1);
    expect(saved.lastRequest()?.method).toBe('PATCH');
    expect(saved.lastRequest()?.url.pathname).toBe(cellRoute().url);
    expect(saved.lastRequest()?.body).toEqual({
      field: 'code',
      value: 'MAX',
      version: 2,
    });
    expect(toastError).not.toHaveBeenCalled();
  });

  it('surfaces the field message of a rejected inline edit and keeps the old value', async () => {
    serveJson(gridRoute(), { rows: [row], lastRow: 1 });
    serveValidationError(cellRoute(), {
      'data.code': ['Der Code ist zu lang.'],
    });

    renderList();
    await editCode('VIEL-ZU-LANG');

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith('Der Code ist zu lang.'));
    expect(screen.getByText('PRO')).toBeTruthy();
  });

  it('reverts an inline edit whose request fails', async () => {
    serveJson(gridRoute(), { rows: [row], lastRow: 1 });
    serveNetworkError(cellRoute());

    renderList();
    await editCode('MAX');

    await vi.waitFor(() => expect(toastError).toHaveBeenCalledWith(SAVE_ERROR));
    expect(screen.getByText('PRO')).toBeTruthy();
  });
});
