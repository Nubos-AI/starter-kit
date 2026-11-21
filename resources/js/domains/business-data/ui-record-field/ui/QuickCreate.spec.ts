import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { toast } from 'vue-sonner';
import CreatableObjectTypesController from '@/actions/App/Http/Controllers/Engine/CreatableObjectTypesController';
import { store } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { show } from '@/routes/engine/records';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import type { FieldDefinition } from '@/shared/types/fields';
import { CommandDialog, CommandList } from '@/shared/ui/primitives/command';
import { setUrlDefaults } from '@/wayfinder';
import QuickCreate from './QuickCreate.vue';

const { visitMock } = vi.hoisted(() => ({ visitMock: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
  router: { visit: visitMock },
  usePage: () => ({ props: {} }),
}));

vi.mock('vue-sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn() },
}));

vi.mock('@/domains/business-data/data/utils/recordToast', () => ({
  recordToast: vi.fn(),
}));

if (typeof Element.prototype.scrollIntoView !== 'function') {
  Element.prototype.scrollIntoView = vi.fn();
}

interface CreatableType extends RecordObjectType {
  fieldDefinitions: FieldDefinition[];
}

function field(key: string, label: string): FieldDefinition {
  return {
    key,
    field_type: 'text_short',
    label,
    is_required: false,
    description: null,
  };
}

const CATALOG: CreatableType[] = [
  {
    id: 'ot-company',
    key: 'company',
    slug: 'company',
    name: 'Company',
    label: 'Firma',
    requiresDeletionReason: false,
    hasHierarchy: false,
    fieldDefinitions: [field('name', 'Name'), field('domain', 'Domain')],
  },
  {
    id: 'ot-person',
    key: 'person',
    slug: 'person',
    name: 'Person',
    label: 'Kontaktperson',
    requiresDeletionReason: false,
    hasHierarchy: false,
    fieldDefinitions: [field('email', 'Email')],
  },
];

const CREATED_RECORD = {
  data: {
    id: 'rec-new',
    objectTypeId: 'ot-company',
    recordNumber: null,
    externalReferenceId: null,
    version: 1,
    data: {},
  },
};

function serveCatalog(catalog: CreatableType[] = CATALOG): ServedRoute {
  return serveJson(CreatableObjectTypesController(), catalog as unknown as Record<string, unknown>[]);
}

function companyStoreRoute() {
  return store({ objectType: 'company' });
}

async function answered(served: ServedRoute, count: number): Promise<void> {
  await vi.waitFor(() => expect(served.requests).toHaveLength(count));
  await settle();
  await settle();
}

let wrapper: ReturnType<typeof mount> | null = null;

const Host = defineComponent({
  render() {
    return h(
      CommandDialog,
      { open: true },
      {
        default: () => h(CommandList, {}, { default: () => h(QuickCreate) }),
      },
    );
  },
});

function mountQuickCreate(): ReturnType<typeof mount> {
  wrapper = mount(Host, { attachTo: document.body });

  return wrapper;
}

async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(1);
  await flushPromises();
}

function typeItems(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>('[data-quick-create-type]'));
}

function typeItem(slug: string): HTMLElement {
  const item = document.querySelector<HTMLElement>(`[data-quick-create-type][data-object-type-slug="${slug}"]`);

  if (item === null) {
    throw new Error(`quick-create type "${slug}" not found in the palette`);
  }

  return item;
}

function click(element: HTMLElement): void {
  element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
}

function formRegion(): Element | null {
  return document.querySelector('[data-quick-create-form]');
}

async function selectType(slug: string): Promise<void> {
  click(typeItem(slug));
  await settle();
}

async function save(): Promise<void> {
  const button = document.querySelector<HTMLElement>('[data-quick-create-save]');

  if (button === null) {
    throw new Error('quick-create save affordance not found');
  }

  click(button);
  await settle();
}

beforeEach(() => {
  vi.useFakeTimers();
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=quick-create-token';
  visitMock.mockReset();
});

afterEach(() => {
  if (wrapper !== null) {
    wrapper.unmount();
    wrapper = null;
  }

  document.body.replaceChildren();
  vi.useRealTimers();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
  vi.restoreAllMocks();
});

describe('QuickCreate — Erstellen group (SC-8)', () => {
  it('lists exactly the creatable types returned by the catalog fetch', async () => {
    const catalog = serveCatalog();
    mountQuickCreate();
    await answered(catalog, 1);

    expect(catalog.lastRequest()?.method).toBe('GET');
    expect(catalog.lastRequest()?.url.pathname).toBe('/nubos/engine/object-types/creatable');

    const slugs = typeItems().map((item) => item.getAttribute('data-object-type-slug'));

    expect(slugs).toEqual(['company', 'person']);
    expect(document.body.textContent).toContain('Firma');
    expect(document.body.textContent).toContain('Kontaktperson');
  });
});

describe('QuickCreate — catalog fetch failure (resilience)', () => {
  it('renders no creatable types and does not crash when the catalog GET is not ok', async () => {
    const catalog = serveServerError(CreatableObjectTypesController(), {
      message: 'boom',
    });

    mountQuickCreate();
    await answered(catalog, 1);

    expect(typeItems()).toHaveLength(0);
    expect(document.querySelector('[data-quick-create]')).not.toBeNull();
    expect(document.querySelector('[data-quick-create-catalog]')).not.toBeNull();
    expect(formRegion()).toBeNull();
  });
});

describe('QuickCreate — lazy-mounted form (D4.2 / R-13)', () => {
  it('does not render DynamicForm before a type is chosen and renders the full form after', async () => {
    const catalog = serveCatalog();
    mountQuickCreate();
    await answered(catalog, 1);

    expect(formRegion()).toBeNull();
    expect(document.querySelector('#name')).toBeNull();
    expect(document.querySelector('#domain')).toBeNull();

    await selectType('company');

    expect(formRegion()).not.toBeNull();
    expect(document.querySelector('#name')).not.toBeNull();
    expect(document.querySelector('#domain')).not.toBeNull();
  });
});

describe('QuickCreate — successful save navigates to detail (D4.3)', () => {
  it('visits the record detail route for the id returned on 201', async () => {
    const catalog = serveCatalog();
    const saved = serveJson(companyStoreRoute(), CREATED_RECORD, {
      status: 201,
    });
    mountQuickCreate();
    await answered(catalog, 1);

    await selectType('company');
    await save();
    await answered(saved, 1);

    expect(saved.lastRequest()?.method).toBe('POST');
    expect(saved.lastRequest()?.url.pathname).toBe('/nubos/engine/records/company');
    expect(saved.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('quick-create-token');
    expect(saved.lastRequest()?.body).toEqual({ data: {} });
    expect(visitMock).toHaveBeenCalledTimes(1);
    expect(visitMock).toHaveBeenCalledWith(show.url({ record: 'rec-new' }));
  });
});

describe('QuickCreate — 422 field errors (server authority)', () => {
  it('renders the server error against the bare field key, proving the data. prefix is stripped', async () => {
    const catalog = serveCatalog();
    const refused = serveValidationError(companyStoreRoute(), {
      'data.name': ['Name ist ein Pflichtfeld.'],
    });
    mountQuickCreate();
    await answered(catalog, 1);

    await selectType('company');
    await save();
    await answered(refused, 1);

    const nameError = document.querySelector('#name-error');
    expect(nameError).not.toBeNull();
    expect(nameError?.textContent).toContain('Name ist ein Pflichtfeld.');

    expect(document.querySelector('#domain-error')).toBeNull();
    expect(visitMock).not.toHaveBeenCalled();
  });
});

describe('QuickCreate — save failures and the pending save', () => {
  it('keeps the form and reports the failure when the save cannot reach the server', async () => {
    const catalog = serveCatalog();
    const failed = serveNetworkError(companyStoreRoute());
    mountQuickCreate();
    await answered(catalog, 1);

    await selectType('company');
    await save();
    await answered(failed, 1);

    expect(toast.error).toHaveBeenCalledWith('Der Datensatz konnte nicht gespeichert werden.');
    expect(formRegion()).not.toBeNull();
    expect(visitMock).not.toHaveBeenCalled();
  });

  it('disables the save while the record is being created and sends it once', async () => {
    const catalog = serveCatalog();
    const saved = serveJson(companyStoreRoute(), CREATED_RECORD, {
      status: 201,
      held: true,
    });
    mountQuickCreate();
    await answered(catalog, 1);

    await selectType('company');
    await save();
    await vi.waitFor(() => expect(saved.requests).toHaveLength(1));

    const button = document.querySelector<HTMLButtonElement>('[data-quick-create-save]');
    expect(button?.disabled).toBe(true);
    expect(button?.textContent).toContain('Wird gespeichert …');

    await save();
    saved.release();
    await answered(saved, 1);

    expect(saved.requests).toHaveLength(1);
    expect(visitMock).toHaveBeenCalledTimes(1);
  });
});
