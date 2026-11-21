import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CreatableObjectTypesController from '@/actions/App/Http/Controllers/Engine/CreatableObjectTypesController';
import { store } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { show } from '@/routes/engine/records';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import { setUrlDefaults } from '@/wayfinder';
import { useQuickCreate } from './useQuickCreate';
import type { CreatableObjectType } from './useQuickCreate';

vi.mock('@/shared/composables/useI18n', () => import('@/shared/testing/i18n'));

setUrlDefaults({ activeTeam: 'nubos' });

const toastError = vi.fn();
const recordToast = vi.fn();
const routerVisit = vi.fn();

vi.mock('vue-sonner', () => ({
  toast: { error: (...args: unknown[]) => toastError(...args) },
}));

vi.mock('@/domains/business-data/data/utils/recordToast', () => ({
  recordToast: (...args: unknown[]) => recordToast(...args),
}));

vi.mock('@inertiajs/vue3', () => ({
  router: { visit: (...args: unknown[]) => routerVisit(...args) },
}));

const objectType = {
  id: 'ot-1',
  key: 'company',
  slug: 'companies',
  name: 'Firmen',
  label: 'Firmen',
  requiresDeletionReason: false,
  hasHierarchy: false,
  fieldDefinitions: [],
} as CreatableObjectType;

const storeRoute = store({ objectType: 'companies' });

const catalogRoute = CreatableObjectTypesController();

const saveErrorMessage = 'Der Datensatz konnte nicht gespeichert werden.';

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  toastError.mockClear();
  recordToast.mockClear();
  routerVisit.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('useQuickCreate', () => {
  it('posts the entered values to the create endpoint of the chosen type', async () => {
    const served = serveJson(storeRoute, { data: { id: 'rec-1', data: {} } }, { status: 201 });

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);
    quickCreate.values.value = { title: 'Neu' };

    await quickCreate.submit();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/records/companies');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual({
      data: { title: 'Neu' },
    });
    expect(recordToast).toHaveBeenCalledWith('create', expect.any(Function));
    expect(routerVisit).toHaveBeenCalledWith(show.url({ record: 'rec-1' }));
  });

  it('stays saving until the server answers and ignores a second submit', async () => {
    const served = serveJson(storeRoute, { data: { id: 'rec-1', data: {} } }, { status: 201, held: true });

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    const submitting = quickCreate.submit();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(quickCreate.saving.value).toBe(true);
    await quickCreate.submit();

    served.release();
    await submitting;

    expect(served.requests).toHaveLength(1);
    expect(quickCreate.saving.value).toBe(false);
    expect(routerVisit).toHaveBeenCalledOnce();
  });

  it('maps the field errors of a 422 rejection onto the plain field keys', async () => {
    serveValidationError(storeRoute, {
      'data.title': ['Der Titel ist erforderlich.'],
      owner_id: ['Unbekannter Besitzer.'],
    });

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    await quickCreate.submit();

    expect(quickCreate.errors.value).toEqual({
      title: 'Der Titel ist erforderlich.',
      owner_id: 'Unbekannter Besitzer.',
    });
    expect(routerVisit).not.toHaveBeenCalled();
    expect(quickCreate.saving.value).toBe(false);
  });

  it('shows the reason the server rejected the record with', async () => {
    serveJson(
      storeRoute,
      {
        message: 'Die Eingaben sind unvollständig.',
        errors: {
          'data.title': ['Der Titel ist erforderlich.'],
        },
      },
      { status: 422 },
    );

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    await quickCreate.submit();

    expect(toastError).toHaveBeenCalledWith('Die Eingaben sind unvollständig.');
  });

  it('shows the reason of a refusal that carries no field errors', async () => {
    serveJson(storeRoute, { message: 'Sie dürfen hier nichts anlegen.' }, { status: 403 });

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    await quickCreate.submit();

    expect(toastError).toHaveBeenCalledWith('Sie dürfen hier nichts anlegen.');
    expect(quickCreate.errors.value).toEqual({});
  });

  it('shows the save error when the server fails without a reason', async () => {
    serveServerError(storeRoute, {});

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    await quickCreate.submit();

    expect(toastError).toHaveBeenCalledWith(saveErrorMessage);
    expect(routerVisit).not.toHaveBeenCalled();
    expect(quickCreate.saving.value).toBe(false);
  });

  it('shows the save error when the network drops the request', async () => {
    serveNetworkError(storeRoute);

    const quickCreate = useQuickCreate();
    quickCreate.select(objectType);

    await quickCreate.submit();

    expect(toastError).toHaveBeenCalledWith(saveErrorMessage);
    expect(routerVisit).not.toHaveBeenCalled();
    expect(quickCreate.saving.value).toBe(false);
  });

  it('sends the catalog request and keeps the returned types', async () => {
    const served = serveJson(catalogRoute, [objectType]);

    const quickCreate = useQuickCreate();

    await quickCreate.loadCatalog();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/object-types/creatable');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');
    expect(quickCreate.catalog.value).toEqual([objectType]);
  });

  it('empties the catalog when the server fails', async () => {
    serveServerError(catalogRoute);

    const quickCreate = useQuickCreate();
    quickCreate.catalog.value = [objectType];

    await quickCreate.loadCatalog();

    expect(quickCreate.catalog.value).toEqual([]);
  });
});
