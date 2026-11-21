import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, onMounted } from 'vue';
import RecordMergeController from '@/actions/App/Http/Controllers/Engine/RecordMergeController';
import { destroy, duplicate, store, update } from '@/actions/App/Http/Controllers/Engine/RecordWriteController';
import { candidates } from '@/actions/App/Http/Controllers/Records/RecordCollaboratorsController';
import { clearPageBreadcrumbs, useBreadcrumbTrail } from '@/shared/composables/useBreadcrumbs';
import { hydratePreferences } from '@/shared/composables/useUserPreferences';
import {
  recordEveryRequest,
  serveEmpty,
  serveJson,
  serveNetworkError,
  serveServerError,
  serveValidationError,
} from '@/shared/testing/msw';
import type { RequestLog, ServedRoute } from '@/shared/testing/msw';
import type { FieldDefinition } from '@/shared/types/fields';
import type { PreferenceDocument } from '@/shared/types/preferences';
import { emptyObjectTypePreferences } from '@/shared/types/preferences';
import { setUrlDefaults } from '@/wayfinder';
import type {
  RecordObjectType,
  RecordPayload,
  RecordSectionContext,
} from '../../../../../shared/business-data/types/records';
import { RELATIONS_PANEL_ID } from '../../utils/recordPanels';
import Form from './Form.vue';

setUrlDefaults({ activeTeam: 'personal' });

const visit = vi.fn();
const reloadMock = vi.fn();

const { pageUrl } = vi.hoisted(() => ({
  pageUrl: { value: '/personal/records/rec-1' },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  usePage: () => ({
    scrollProps: {},
    url: pageUrl.value,
    props: { auth: { user: null, can: grantedAbilities, authority: null } },
  }),
  router: {
    on: vi.fn(() => vi.fn()),
    visit: (...args: unknown[]) => visit(...args),
    reload: (...args: unknown[]) => reloadMock(...args),
    get: vi.fn(),
  },
}));

const PEOPLE = [
  { value: 'u-1', label: 'Anna Albers' },
  { value: 'u-2', label: 'Sven Support' },
  { value: 'u-3', label: 'Rita Vertrieb' },
];

vi.mock('../../../data/utils/recordToast', () => ({ recordToast: vi.fn() }));

vi.mock('vue-sonner', () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}));

const objectType: RecordObjectType = {
  id: 'ot-1',
  key: 'deals',
  slug: 'deals',
  name: 'Deals',
  label: 'Verkaufschancen',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const fieldDefinitions: FieldDefinition[] = [
  {
    key: 'titel',
    field_type: 'text_short',
    label: 'Titel',
    is_required: true,
  },
];

const record = {
  id: '01RECORD0K5N3Q8V9WYE6M2H7X',
  objectTypeId: 'ot-1',
  recordNumber: 'REC-1',
  title: 'Angebot A',
  externalReferenceId: null,
  version: 3,
  data: { titel: 'Angebot A' },
} as unknown as RecordPayload;

const DynamicFormStub = {
  name: 'DynamicFormStub',
  props: ['fields', 'modelValue', 'errors', 'hiddenSections'],
  emits: ['update:modelValue'],
  template:
    '<div data-dynamic-form :data-errors="JSON.stringify(errors)" :data-hidden="(hiddenSections ?? []).join(\',\')" @click.self="$emit(\'update:modelValue\', { titel: \'Angebot B\' })"><slot name="actions" :fields="[\'titel\']" /></div>',
};

const stubs = {
  teleport: true,
  DynamicForm: DynamicFormStub,
  ConflictDialog: { template: '<div data-conflict-dialog />' },
  UnsavedChangesDialog: { template: '<div data-unsaved-dialog />' },
  RecordTimeline: {
    props: ['recordId', 'fields'],
    methods: { reload: () => undefined },
    template: '<div data-record-timeline :data-record-id="recordId" :data-fields="JSON.stringify(fields)" />',
  },
  RecordDetailTabs: {
    props: ['recordId', 'readonly'],
    template: '<div data-record-detail-tabs :data-record-id="recordId" :data-readonly="readonly" />',
  },
  RecordWatchersMenu: {
    props: ['recordId', 'options', 'disabled'],
    template:
      '<div data-record-watchers-menu :data-disabled="disabled" :data-options="options.map((option) => option.value).join(\',\')" />',
  },
  RecordOwnerMenu: {
    props: ['modelValue', 'options', 'disabled'],
    emits: ['update:modelValue'],
    template:
      '<button data-record-owner-menu :data-disabled="disabled" @click="$emit(\'update:modelValue\', \'u-9\')" />',
  },
  RecordCollaboratorsMenu: {
    props: ['modelValue', 'options', 'disabled'],
    emits: ['update:modelValue'],
    template:
      '<button data-record-collaborators-menu :data-disabled="disabled" :data-options="options.map((option) => option.value).join(\',\')" @click="$emit(\'update:modelValue\', [\'u-7\'])" />',
  },
  RecordActionsMenu: {
    props: ['trashed', 'canDelete', 'canMerge', 'canManagePanels'],
    emits: ['manage-panels', 'duplicate', 'merge', 'delete'],
    template:
      '<div data-record-actions-trigger :data-can-merge="canMerge ? \'\' : undefined">' +
      '<button v-if="canManagePanels" data-record-action="panels" @click="$emit(\'manage-panels\')" />' +
      '<button data-record-action="duplicate" @click="$emit(\'duplicate\')" />' +
      '<button data-record-action="merge" @click="$emit(\'merge\')" />' +
      '<button data-record-action="delete" @click="$emit(\'delete\')" />' +
      '</div>',
  },
  ConfirmDialog: {
    props: ['open', 'pending', 'title', 'description'],
    emits: ['confirm', 'cancel'],
    template:
      '<div v-if="open" data-confirm-dialog :data-confirm-title="title">' +
      '{{ description }}<slot />' +
      '<button data-confirm @click="$emit(\'confirm\')" />' +
      '<button data-confirm-cancel @click="$emit(\'cancel\')" /></div>',
  },
  RecordRelations: {
    props: ['recordId', 'readonly'],
    template: '<div data-record-relations :data-readonly="readonly ? \'\' : undefined" />',
  },
  RecordPanelSettings: {
    props: ['open', 'panels', 'objectTypeId'],
    emits: ['close'],
    template:
      '<div v-if="open" data-record-panel-settings :data-panels="panels.map((panel) => panel.id).join(\',\')" />',
  },
  Teleport: true,
};

let requestLog: RequestLog;

async function settle(): Promise<void> {
  await flushPromises();
  await requestLog.settle();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await flushPromises();
}

function updateRoute() {
  return update({ record: record.id });
}

function serveUpdate(body: Record<string, unknown> = { data: { ...record, version: 4 } }): ServedRoute {
  return serveJson(updateRoute(), body);
}

function putBodiesOf(served: ServedRoute): unknown[] {
  return served.requests.filter((request) => request.method === 'PUT').map((request) => request.body);
}

const grantedAbilities: Record<string, boolean> = {};

function preferenceDocument(panelVisibility: boolean): PreferenceDocument {
  return {
    settings: {
      appearance: 'system',
      density: 'compact',
      pageSize: 100,
      sidebarOpen: true,
      startObjectTypeId: null,
    },
    objectTypes: {},
    grids: {},
    policy: {
      columnsAndSorting: { records: true, configuration: true },
      viewMode: { records: true },
      filterAndSegment: { records: false },
      layoutAndAppearance: { global: true },
      panelState: { records: true },
      panelVisibility: { records: panelVisibility },
    },
  };
}

function mountForm(mode: 'create' | 'edit', overrides: Record<string, unknown> = {}, granted: string[] = []) {
  for (const key of Object.keys(grantedAbilities)) {
    delete grantedAbilities[key];
  }

  for (const ability of granted) {
    grantedAbilities[`${objectType.slug}.${ability}`] = true;
  }

  return mount(Form, {
    props: {
      mode,
      objectType,
      fieldDefinitions,
      record: mode === 'edit' ? record : null,
      hasRelationships: true,
      ...overrides,
    },
    global: { stubs },
  });
}

interface SeedingContext {
  seedValues: (data: Record<string, unknown>) => void;
}

const contactType: RecordObjectType = {
  id: 'ot-1',
  key: 'contacts',
  slug: 'contacts',
  name: 'Contacts',
  label: 'Kontakte',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const contactFields: FieldDefinition[] = [
  { key: 'name', field_type: 'text_short', label: 'Name', is_required: true },
  { key: 'email', field_type: 'email', label: 'E-Mail', is_required: false },
];

const contactRecord = {
  id: '01RECORD0K5N3Q8V9WYE6M2H7X',
  objectTypeId: 'ot-1',
  recordNumber: 'CON-1',
  title: 'Erika Muster',
  externalReferenceId: null,
  version: 3,
  data: { name: 'Erika Muster', email: '' },
} as unknown as RecordPayload;

const seed = { name: 'Max Mail', email: 'max@example.test' };

function seedingExtension(when: 'setup' | 'mounted' | 'click') {
  return defineComponent({
    props: {
      name: { type: String, required: true },
      context: { type: Object, required: true },
    },
    setup(props) {
      if (props.name !== 'records.form') {
        return () => null;
      }

      const context = (): SeedingContext => props.context as unknown as SeedingContext;

      if (when === 'setup') {
        context().seedValues(seed);
      }

      onMounted(() => {
        if (when === 'mounted') {
          context().seedValues(seed);
        }
      });

      return () =>
        h('button', {
          'data-seed': '',
          onClick: () => context().seedValues(seed),
        });
    },
  });
}

function mountSeededForm(
  mode: 'create' | 'edit',
  when: 'setup' | 'mounted' | 'click',
  data: Record<string, unknown> = {},
) {
  return mount(Form, {
    props: {
      mode,
      objectType: contactType,
      fieldDefinitions: contactFields,
      record: mode === 'edit' ? { ...contactRecord, data } : null,
    },
    global: {
      stubs: {
        teleport: true,
        UiExtensionPoint: seedingExtension(when),
        DynamicForm: {
          props: ['modelValue'],
          emits: ['update:modelValue'],
          template:
            '<div data-dynamic-form :data-values="JSON.stringify(modelValue)" @click.self="$emit(\'update:modelValue\', { ...modelValue, name: \'Eigene Eingabe\' })"><slot name="actions" :fields="[\'name\', \'email\']" /></div>',
        },
        RecordTimeline: { template: '<div />' },
        RecordDetailTabs: { template: '<div />' },
        RecordWatchersMenu: { template: '<div />' },
        RecordOwnerMenu: { template: '<div />' },
        RecordCollaboratorsMenu: { template: '<div />' },
        RecordActionsMenu: { template: '<div />' },
        RecordRelations: { template: '<div />' },
        ConflictDialog: { template: '<div />' },
        UnsavedChangesDialog: { template: '<div />' },
      },
    },
  });
}

function formValues(wrapper: ReturnType<typeof mountSeededForm>): unknown {
  return JSON.parse(String(wrapper.get('[data-dynamic-form]').attributes('data-values')));
}

function offersSave(wrapper: ReturnType<typeof mountSeededForm>): boolean {
  return wrapper.find('[data-form-save]').exists();
}

let people: ServedRoute;

beforeEach(() => {
  hydratePreferences(preferenceDocument(true));
  document.cookie = 'XSRF-TOKEN=test-xsrf-token';
  pageUrl.value = `/personal/records/${record.id}`;
  reloadMock.mockReset();
  requestLog = recordEveryRequest();
  people = serveJson(candidates({ record: ':record' }), { options: PEOPLE });
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.clearAllMocks();
});

describe('records/Form — extensions seed the values of a new record', () => {
  it.each(['setup', 'mounted'] as const)(
    'takes values seeded while the extension %s runs and offers saving',
    async (when) => {
      const wrapper = mountSeededForm('create', when);
      await settle();

      expect(formValues(wrapper)).toEqual(seed);
      expect(offersSave(wrapper)).toBe(true);
    },
  );

  it('takes values seeded after the page is shown and offers saving', async () => {
    const wrapper = mountSeededForm('create', 'click');
    await settle();

    expect(offersSave(wrapper)).toBe(false);

    await wrapper.get('[data-seed]').trigger('click');

    expect(formValues(wrapper)).toEqual(seed);
    expect(offersSave(wrapper)).toBe(true);
  });

  it('keeps what the user already entered and only fills the blank fields', async () => {
    const wrapper = mountSeededForm('create', 'click');
    await settle();
    await wrapper.get('[data-dynamic-form]').trigger('click');
    await wrapper.get('[data-seed]').trigger('click');

    expect(formValues(wrapper)).toEqual({
      name: 'Eigene Eingabe',
      email: 'max@example.test',
    });
  });

  it('ignores seeded values on an existing record', async () => {
    const wrapper = mountSeededForm('edit', 'mounted', contactRecord.data);
    await settle();
    await wrapper.get('[data-seed]').trigger('click');

    expect(formValues(wrapper)).toEqual(contactRecord.data);
    expect(offersSave(wrapper)).toBe(false);
  });
});

describe('records/Form — page shell', () => {
  it('names the record in the breadcrumb so the header leads back', async () => {
    clearPageBreadcrumbs();

    mountForm('edit');

    expect(useBreadcrumbTrail().items.value).toEqual([
      { title: 'Verkaufschancen', href: '/personal/records/deals' },
      { title: 'Angebot A' },
    ]);
  });

  it('announces the create intent', async () => {
    expect(mountForm('create').text()).toContain('Verkaufschancen anlegen');
  });

  it('announces the edit intent with the record title', async () => {
    expect(mountForm('edit').text()).toContain('Angebot A');
  });
});

describe('records/Form — the detail area', () => {
  it('carries the detail tabs but no history tab', async () => {
    const wrapper = mountForm('edit', {}, ['audit.view']);
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('Verlauf');
  });

  it.each<[string, boolean, string]>([
    ['leaves the detail tabs writable on a live record', false, 'false'],
    ['locks the detail tabs on a deleted record', true, 'true'],
  ])('%s', async (_name, trashed, readonly) => {
    expect(mountForm('edit', { trashed }).get('[data-record-detail-tabs]').attributes('data-readonly')).toBe(readonly);
  });

  it('locks the form and drops the save row without the update permission', async () => {
    const wrapper = mountForm('edit', { canUpdate: false });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-readonly]').exists()).toBe(true);
    expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined();
  });

  it('still lets a read-only viewer work in the tabs and read the timeline', async () => {
    const wrapper = mountForm('edit', { canUpdate: false });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.get('[data-record-detail-tabs]').attributes('data-readonly')).toBe('false');
    expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
  });

  it('locks the people menus for a viewer without the update permission', async () => {
    const wrapper = mountForm('edit', { canUpdate: false });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.get('[data-record-owner-menu]').attributes('data-disabled')).toBe('true');
    expect(wrapper.get('[data-record-collaborators-menu]').attributes('data-disabled')).toBe('true');
  });

  it('explains a deleted record and blocks its save row', async () => {
    const wrapper = mountForm('edit', { trashed: true });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-trashed]').exists()).toBe(true);
    expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
  });
});

describe('records/Form — relations box', () => {
  it('offers apps a section point in the left column below the record fields', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    const main = wrapper.get('[data-record-detail-main]');
    const html = main.html();

    expect(main.find('[data-extension-point="records.form.sections"]').exists()).toBe(true);
    expect(html.indexOf('data-dynamic-form')).toBeLessThan(html.indexOf('records.form.sections'));
  });

  it('puts the relations above the data of a saved record', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    const main = wrapper.get('[data-record-detail-main]');
    const html = main.html();

    expect(main.find('[data-record-relations]').exists()).toBe(true);
    expect(html.indexOf('data-record-relations')).toBeLessThan(html.indexOf('data-dynamic-form'));
  });

  it('keeps the box away entirely when the object type has no relationship types', async () => {
    const wrapper = mountForm('edit', { hasRelationships: false });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
  });

  it('hands the trashed state down as read-only', async () => {
    const wrapper = mountForm('edit', { trashed: true });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.get('[data-record-relations]').attributes('data-readonly')).toBe('');
  });
});

describe('records/Form — one view for creating and editing', () => {
  it('a record being created shows only the fields and the pending hint', async () => {
    const wrapper = mountForm('create');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-timeline]').exists()).toBe(false);
    expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(false);
    expect(wrapper.find('[data-record-reminders]').exists()).toBe(false);
    expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
    expect(wrapper.find('[data-record-people]').exists()).toBe(false);
    expect(wrapper.find('[data-record-actions-trigger]').exists()).toBe(false);
    expect(wrapper.find('[data-dynamic-form]').exists()).toBe(true);
    const hint = wrapper.get('[data-record-detail-pending]');

    expect(hint.attributes('data-slot')).toBe('alert');
    expect(hint.attributes('role')).toBe('status');
    expect(hint.text()).toContain('nach dem ersten Speichern');
  });

  it('keeps the hint away from a saved record', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-detail-pending]').exists()).toBe(false);
    expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
  });
});

describe('records/Form — saving', () => {
  it('shows the save row of a group only once the group changes', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-form-save]').exists()).toBe(false);

    await wrapper.find('[data-dynamic-form]').trigger('click');

    expect(wrapper.find('[data-form-save]').exists()).toBe(true);
  });

  it('puts a cancelled group back to its stored values', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');

    await wrapper.find('[data-form-cancel]').trigger('click');

    expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    expect(visit).not.toHaveBeenCalled();
  });

  it('saves only the fields of the group and stays on the record', async () => {
    const served = serveUpdate();

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/personal/engine/records/${record.id}`);
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('test-xsrf-token');
    expect(served.lastRequest()?.body).toEqual({
      data: { titel: 'Angebot B' },
      version: 3,
    });
    expect(people.lastRequest()?.url.pathname).toBe(candidates.url({ record: record.id }));
    expect(visit).not.toHaveBeenCalled();
  });

  it('keeps writing with the version the server answered with', async () => {
    const served = serveUpdate();

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    await wrapper.find('[data-record-owner-menu]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    const bodies = putBodiesOf(served).map((body) => (body as { version: number }).version);

    expect(bodies).toEqual([3, 4]);
  });

  it('creates a record through the object type endpoint', async () => {
    const served = serveJson(store({ objectType: 'deals' }), { data: record }, { status: 201 });

    const wrapper = mountForm('create');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.url.pathname).toBe('/personal/engine/records/deals');
    expect(served.lastRequest()?.method).toBe('POST');
    expect(people.requests).toHaveLength(0);
    expect(visit).toHaveBeenCalledWith(`/personal/records/${record.recordNumber}/edit`);
  });

  it('binds the server field error to the rendered field key', async () => {
    serveValidationError(updateRoute(), {
      'data.titel': ['Pflichtfeld.'],
    });

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(JSON.parse(String(wrapper.find('[data-dynamic-form]').attributes('data-errors')))).toEqual({
      titel: 'Pflichtfeld.',
    });
    expect(visit).not.toHaveBeenCalled();
  });
});

describe('records/Form — a save the server does not take', () => {
  it.each<[string, () => ServedRoute]>([
    ['answers with a server fault', () => serveServerError(updateRoute())],
    ['cannot reach the server', () => serveNetworkError(updateRoute())],
  ])('keeps the group dirty and stays on the record when the save %s', async (_case, serve) => {
    const served = serve();

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(wrapper.find('[data-form-save]').exists()).toBe(true);
    expect(visit).not.toHaveBeenCalled();
  });

  it('sends a group save once while it is still pending', async () => {
    const served = serveJson(updateRoute(), { data: { ...record, version: 4 } }, { held: true });

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.find('[data-form-save]').trigger('click');
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    await wrapper.find('[data-form-save]').trigger('click');
    served.release();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(visit).not.toHaveBeenCalled();
  });
});

describe('records/Form — the header acts on the record', () => {
  function editRecord(): RecordPayload {
    return { ...record, ownerId: 'u-1' } as unknown as RecordPayload;
  }

  it('saves a new owner right away instead of waiting for the save button', async () => {
    const served = serveUpdate();

    const wrapper = mountForm('edit', { record: editRecord() });
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.get('[data-record-owner-menu]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('PUT');
    expect(served.lastRequest()?.url.pathname).toBe(`/personal/engine/records/${record.id}`);
    expect(served.lastRequest()?.body).toEqual({
      version: record.version,
      owner_id: 'u-9',
      collaborator_ids: [],
    });
  });

  it('leaves the save row untouched when only the assignment changed', async () => {
    const served = serveUpdate();

    const wrapper = mountForm('edit', { record: editRecord() });
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.get('[data-record-collaborators-menu]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-form-save]').exists()).toBe(false);
    expect(served.lastRequest()?.body).toEqual({
      version: record.version,
      owner_id: 'u-1',
      collaborator_ids: ['u-7'],
    });
  });

  it.each<[string, string | null, string, string]>([
    ['duplicates the record and opens the copy', 'CO-0000000007', 'copy-1', '/personal/records/CO-0000000007/edit'],
    ['opens a copy without a business key by its id', null, 'copy-2', '/personal/records/copy-2/edit'],
  ])('%s', async (_name, recordNumber, id, expectedUrl) => {
    const served = serveJson(
      duplicate({ record: record.id }),
      { data: { ...record, id, recordNumber } },
      { status: 201 },
    );

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();
    await wrapper.get('[data-record-action="duplicate"]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe(duplicate.url({ record: record.id }));
    expect(visit).toHaveBeenCalledTimes(1);
    expect(visit).toHaveBeenCalledWith(expectedUrl);
  });

  it('asks before deleting and returns to the list afterwards', async () => {
    const served = serveEmpty(destroy({ record: record.id }));

    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-confirm-dialog]').exists()).toBe(false);

    await wrapper.get('[data-record-action="delete"]').trigger('click');

    expect(wrapper.find('[data-confirm-dialog]').exists()).toBe(true);
    expect(served.requests).toHaveLength(0);

    await wrapper.get('[data-confirm]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('DELETE');
    expect(visit).toHaveBeenCalledWith(expect.stringContaining(objectType.slug));
  });
});

describe('records/Form — a person holds one role at a time', () => {
  const people = [
    { value: 'u-1', label: 'Anna Albers' },
    { value: 'u-2', label: 'Sven Support' },
    { value: 'u-3', label: 'Rita Vertrieb' },
  ];

  function optionsOf(wrapper: ReturnType<typeof mountForm>, hook: string): string[] {
    const raw = wrapper.get(hook).attributes('data-options') ?? '';

    return raw === '' ? [] : raw.split(',');
  }

  function mountWithPeople(collaborators: string[] = []): ReturnType<typeof mountForm> {
    return mountForm('edit', {
      record: { ...record, ownerId: 'u-1' } as unknown as RecordPayload,
      collaborators: collaborators.map((value) => ({
        value,
        label: value,
      })),
    });
  }

  it('keeps the owner out of the collaborator list', async () => {
    const wrapper = mountWithPeople();
    await vi.dynamicImportSettled();
    await settle();

    expect(optionsOf(wrapper, '[data-record-collaborators-menu]')).not.toContain('u-1');
  });

  it('keeps owner and collaborators out of the watcher list', async () => {
    const wrapper = mountWithPeople(['u-2']);
    await vi.dynamicImportSettled();
    await settle();

    const options = optionsOf(wrapper, '[data-record-watchers-menu]');

    expect(options).not.toContain('u-1');
    expect(options).not.toContain('u-2');
    expect(options).toContain('u-3');
  });

  it('drops a collaborator who becomes the owner', async () => {
    serveUpdate();

    const wrapper = mountWithPeople(['u-9']);
    await vi.dynamicImportSettled();
    await settle();

    await wrapper.get('[data-record-owner-menu]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(optionsOf(wrapper, '[data-record-collaborators-menu]')).not.toContain('u-9');
  });

  it('offers everyone again once the record has no owner', async () => {
    const wrapper = mountForm('edit', {
      record: { ...record, ownerId: null } as unknown as RecordPayload,
    });
    await vi.dynamicImportSettled();
    await settle();
    await vi.dynamicImportSettled();
    await settle();

    expect(optionsOf(wrapper, '[data-record-collaborators-menu]')).toEqual(people.map((person) => person.value));
  });
});

describe('records/Form — the panels the user keeps', () => {
  it('opens the panel settings from the actions menu', async () => {
    const wrapper = mountForm('edit');
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-panel-settings]').exists()).toBe(false);

    await wrapper.get('[data-record-action="panels"]').trigger('click');

    expect(wrapper.get('[data-record-panel-settings]').attributes('data-panels')).toBe(
      [RELATIONS_PANEL_ID, 'group:ungrouped'].join(','),
    );
  });

  it('leaves out every panel the user switched off', async () => {
    const wrapper = mountForm('edit', {
      preference: {
        ...emptyObjectTypePreferences(),
        hiddenSections: [RELATIONS_PANEL_ID, 'group:ungrouped'],
      },
    });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-relations]').exists()).toBe(false);
    expect(wrapper.get('[data-dynamic-form]').attributes('data-hidden')).toBe(
      [RELATIONS_PANEL_ID, 'group:ungrouped'].join(','),
    );
  });

  it('never takes the aside panels away from the user', async () => {
    const wrapper = mountForm('edit', {
      preference: {
        ...emptyObjectTypePreferences(),
        hiddenSections: ['reminders', 'timeline'],
      },
    });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-detail-tabs]').exists()).toBe(true);
    expect(wrapper.find('[data-record-timeline]').exists()).toBe(true);
  });

  it.each<[string, 'create' | 'edit', boolean]>([
    ['offers no panel settings while the record is still being created', 'create', true],
    ['offers no panel settings once the tenant switched the category off', 'edit', false],
  ])('%s', async (_name, mode, allowsPanelVisibility) => {
    hydratePreferences(preferenceDocument(allowsPanelVisibility));

    const wrapper = mountForm(mode);
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-action="panels"]').exists()).toBe(false);
  });
});

describe('records/Form — merging', () => {
  it('opens the merge page from the actions menu', async () => {
    const wrapper = mountForm('edit', {}, ['update', 'merge']);
    await vi.dynamicImportSettled();
    await settle();

    await wrapper.get('[data-record-action="merge"]').trigger('click');

    expect(visit).toHaveBeenCalledTimes(1);
    expect(String(visit.mock.calls[0][0])).toContain(`/records/${record.id}/merge`);
  });

  it('tells the menu whether this user may merge at all', async () => {
    expect(
      mountForm('edit', {}, ['update', 'merge']).get('[data-record-actions-trigger]').attributes('data-can-merge'),
    ).toBeDefined();

    expect(
      mountForm('edit', {}, ['update']).get('[data-record-actions-trigger]').attributes('data-can-merge'),
    ).toBeUndefined();
  });

  it('offers to take a recent merge back and reloads once it went through', async () => {
    const undone = serveJson(RecordMergeController.undo({ record: record.id, merge: 'm-1' }), {
      data: {
        merge_id: 'm-1',
        target_id: record.id,
        source_id: 'src-1',
        restored_fields: [],
        kept_fields: [],
        restored_transfers: {},
        unrecoverable: {},
      },
    });

    const wrapper = mountForm(
      'edit',
      {
        undoableMerge: {
          id: 'm-1',
          sourceId: 'src-1',
          mergedAt: '2026-08-26T10:00:00.000Z',
          undoableUntil: '2099-01-01T00:00:00.000Z',
        },
      },
      ['update', 'merge'],
    );
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-merge-undo]').exists()).toBe(true);

    await wrapper.get('[data-record-merge-undo-button]').trigger('click');
    await vi.dynamicImportSettled();
    await settle();

    expect(reloadMock).toHaveBeenCalledTimes(1);
    expect(undone.requests).toHaveLength(1);
    expect(undone.lastRequest()?.method).toBe('POST');
  });

  it('lets the user put the undo offer away', async () => {
    const wrapper = mountForm(
      'edit',
      {
        undoableMerge: {
          id: 'm-1',
          sourceId: 'src-1',
          mergedAt: '2026-08-26T10:00:00.000Z',
          undoableUntil: '2099-01-01T00:00:00.000Z',
        },
      },
      ['update', 'merge'],
    );
    await vi.dynamicImportSettled();
    await settle();

    await wrapper.get('[data-testid="record-merge-undo-dismiss"]').trigger('click');

    expect(wrapper.find('[data-record-merge-undo]').exists()).toBe(false);
  });

  it('names the day the undo offer runs out', async () => {
    const notice = mountForm(
      'edit',
      {
        undoableMerge: {
          id: 'm-1',
          sourceId: 'src-1',
          mergedAt: '2026-08-26T10:00:00.000Z',
          undoableUntil: '2099-01-01T00:00:00.000Z',
        },
      },
      ['update', 'merge'],
    ).get('[data-record-merge-undo-deadline]');

    expect(notice.text()).toContain('2099');
  });

  it.each<[string, Record<string, unknown>]>([
    [
      'takes the undo offer away once its window has run out',
      {
        undoableMerge: {
          id: 'm-1',
          sourceId: 'src-1',
          mergedAt: '2020-01-01T10:00:00.000Z',
          undoableUntil: '2020-01-31T10:00:00.000Z',
        },
      },
    ],
    ['shows no undo offer when there is no recent merge', {}],
  ])('%s', async (_name, overrides) => {
    expect(mountForm('edit', overrides, ['update', 'merge']).find('[data-record-merge-undo]').exists()).toBe(false);
  });

  it('points a record that was merged away at its survivor', async () => {
    const wrapper = mountForm(
      'edit',
      {
        record: { ...record, mergedIntoRecordId: 'winner-1' },
        trashed: true,
      },
      ['update', 'merge'],
    );
    await vi.dynamicImportSettled();
    await settle();

    const banner = wrapper.get('[data-record-merged]');

    expect(banner.text()).toContain('zusammengeführt');
    expect(banner.get('a').attributes('href')).toContain('winner-1');
    expect(wrapper.find('[data-record-trashed]').exists()).toBe(false);
  });
});

describe('records/Form — a deleted record announces its purge deadline', () => {
  it.each<[string, string | null, string]>([
    [
      'names the deadline when the object type keeps a retention period',
      '2026-09-11',
      'Endgültige Löschung am 11.09.2026',
    ],
    ['says the record simply stays without a retention period', null, 'bis auf Weiteres'],
  ])('%s', async (_name, purgeOn, notice) => {
    const wrapper = mountForm('edit', { trashed: true, purgeOn });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-purge-notice]').text()).toContain(notice);
  });

  it('shows the recorded deletion reason', async () => {
    const wrapper = mountForm('edit', {
      trashed: true,
      deletionReason: 'Dublette',
    });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-deletion-reason]').text()).toContain('Dublette');
  });

  it('keeps the people menus away so a deleted record fires no write lookups', async () => {
    const wrapper = mountForm('edit', { trashed: true });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-record-owner-menu]').exists()).toBe(false);
    expect(wrapper.find('[data-record-collaborators-menu]').exists()).toBe(false);
    expect(wrapper.find('[data-record-watchers-menu]').exists()).toBe(false);
    expect(wrapper.find('[data-record-actions-trigger]').exists()).toBe(true);
  });

  it.each<[string, boolean]>([
    ['hides the purge button without the right', false],
    ['offers the purge button with the right', true],
  ])('%s', async (_name, canPurge) => {
    const wrapper = mountForm('edit', { trashed: true, canPurge });
    await vi.dynamicImportSettled();
    await settle();

    expect(wrapper.find('[data-testid="record-purge"]').exists()).toBe(canPurge);
  });
});

describe('records/Form — deleting asks for a reason when the type demands one', () => {
  it.each<[string, boolean]>([
    ['shows no reason field when the object type does not demand one', false],
    ['shows the reason field when the object type demands one', true],
  ])('%s', async (_name, requiresDeletionReason) => {
    const wrapper = mountForm('edit', { objectType: { ...objectType, requiresDeletionReason } }, ['delete']);
    await vi.dynamicImportSettled();
    await settle();

    await wrapper.find('[data-record-action="delete"]').trigger('click');

    expect(wrapper.find('[data-testid="deletion-reason"]').exists()).toBe(requiresDeletionReason);
  });
});

describe('records/Form — a section extension edits a saved record', () => {
  function updatingSection() {
    return defineComponent({
      props: {
        name: { type: String, required: true },
        context: { type: Object, required: true },
      },
      setup(props) {
        if (props.name !== 'records.form.sections') {
          return () => null;
        }

        const context = (): RecordSectionContext & {
          updates: Record<string, unknown>;
        } =>
          props.context as unknown as RecordSectionContext & {
            updates: Record<string, unknown>;
          };

        return () => [
          h('button', {
            'data-section-change': '',
            onClick: () => {
              context().updates.module_payload = { plan: 'gold' };
            },
          }),
          h('button', {
            'data-section-save': '',
            onClick: () =>
              context().saveSection({
                fields: [],
                updates: ['module_payload'],
              }),
          }),
        ];
      },
    });
  }

  function mountWithSection() {
    return mount(Form, {
      props: {
        mode: 'edit',
        objectType,
        fieldDefinitions,
        record,
      },
      global: {
        stubs: { ...stubs, UiExtensionPoint: updatingSection() },
      },
    });
  }

  it('leaves the field groups without a save row when only the section changed', async () => {
    const wrapper = mountWithSection();
    await settle();

    await wrapper.get('[data-section-change]').trigger('click');

    expect(wrapper.find('[data-form-save]').exists()).toBe(false);
  });

  it('sends only the section updates with the section save', async () => {
    const served = serveUpdate();

    const wrapper = mountWithSection();
    await settle();
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.get('[data-section-change]').trigger('click');
    await wrapper.get('[data-section-save]').trigger('click');
    await settle();

    expect(putBodiesOf(served)).toEqual([{ version: 3, module_payload: { plan: 'gold' } }]);
    expect(wrapper.find('[data-form-save]').exists()).toBe(true);
  });

  it('keeps the section updates out of a field group save', async () => {
    const served = serveUpdate();

    const wrapper = mountWithSection();
    await settle();
    await wrapper.get('[data-section-change]').trigger('click');
    await wrapper.find('[data-dynamic-form]').trigger('click');
    await wrapper.get('[data-form-save]').trigger('click');
    await settle();

    expect(putBodiesOf(served)).toEqual([{ data: { titel: 'Angebot B' }, version: 3 }]);
  });
});

describe('records/Form — a section extension edits a field it took over', () => {
  const cost: FieldDefinition = {
    key: 'cost',
    field_type: 'money',
    label: 'Kosten',
    is_required: false,
  };

  interface RelocatingContext extends RecordSectionContext {
    relocatedFields: FieldDefinition[];
    values: Record<string, unknown>;
    errors: Record<string, string>;
    setValue: (key: string, value: unknown) => void;
  }

  function relocatingSection() {
    return defineComponent({
      props: {
        name: { type: String, required: true },
        context: { type: Object, required: true },
      },
      setup(props) {
        if (props.name !== 'records.form.sections') {
          return () => null;
        }

        const context = (): RelocatingContext => props.context as unknown as RelocatingContext;

        return () => [
          h('button', {
            'data-relocated-change': '',
            'data-relocated-keys': context()
              .relocatedFields.map((field) => field.key)
              .join(','),
            'data-relocated-value': String(context().values.cost ?? ''),
            'data-relocated-error': context().errors.cost ?? '',
            'data-relocated-dirty': String(context().hasChanges(['cost'])),
            onClick: () => context().setValue('cost', '12.50'),
          }),
          h('button', {
            'data-relocated-save': '',
            onClick: () =>
              context().saveSection({
                fields: ['cost'],
                updates: [],
              }),
          }),
          h('button', {
            'data-relocated-cancel': '',
            onClick: () => context().cancelSection(['cost']),
          }),
        ];
      },
    });
  }

  function mountWithRelocatedField() {
    return mount(Form, {
      props: {
        mode: 'edit',
        objectType,
        fieldDefinitions,
        relocatedFields: [cost],
        record: { ...record, data: { titel: 'Angebot A', cost: '9' } },
      },
      global: {
        stubs: { ...stubs, UiExtensionPoint: relocatingSection() },
      },
    });
  }

  function relocated(wrapper: ReturnType<typeof mountWithRelocatedField>) {
    return wrapper.get('[data-relocated-change]');
  }

  it('hands the section the field and its stored value', async () => {
    const wrapper = mountWithRelocatedField();
    await settle();

    expect(relocated(wrapper).attributes('data-relocated-keys')).toBe('cost');
    expect(relocated(wrapper).attributes('data-relocated-value')).toBe('9');
  });

  it('tells the section its field changed and saves only that field', async () => {
    const served = serveUpdate({
      data: {
        ...record,
        version: 4,
        data: { titel: 'Angebot A', cost: '12.50' },
      },
    });

    const wrapper = mountWithRelocatedField();
    await settle();

    expect(relocated(wrapper).attributes('data-relocated-dirty')).toBe('false');

    await relocated(wrapper).trigger('click');

    expect(relocated(wrapper).attributes('data-relocated-dirty')).toBe('true');
    expect(wrapper.find('[data-form-save]').exists()).toBe(false);

    await wrapper.get('[data-relocated-save]').trigger('click');
    await settle();

    expect(served.requests).toHaveLength(1);
    expect((served.lastRequest()?.body as { data: unknown } | undefined)?.data).toEqual({
      cost: '12.50',
    });
    expect(relocated(wrapper).attributes('data-relocated-dirty')).toBe('false');
  });

  it('puts the field back when the section cancels', async () => {
    const wrapper = mountWithRelocatedField();
    await settle();
    await relocated(wrapper).trigger('click');

    await wrapper.get('[data-relocated-cancel]').trigger('click');

    expect(relocated(wrapper).attributes('data-relocated-value')).toBe('9');
  });

  it('shows the section the error the server bound to the field', async () => {
    serveValidationError(updateRoute(), {
      'data.cost': ['Mindestens 0.'],
    });

    const wrapper = mountWithRelocatedField();
    await settle();
    await relocated(wrapper).trigger('click');
    await wrapper.get('[data-relocated-save]').trigger('click');
    await settle();

    expect(relocated(wrapper).attributes('data-relocated-error')).toBe('Mindestens 0.');
  });
});
