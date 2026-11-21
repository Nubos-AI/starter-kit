import { flushPromises, mount, renderToString } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentsController from '@/actions/App/Http/Controllers/Segments/SegmentsController';
import SegmentShareOptionsController from '@/actions/App/Http/Controllers/Segments/SegmentShareOptionsController';
import SegmentSharesController from '@/actions/App/Http/Controllers/Segments/SegmentSharesController';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import { setUrlDefaults } from '@/wayfinder';
import type { SegmentSummary } from '../../business-data/data/useSegments';
import SegmentBar from './SegmentBar.vue';
import SegmentSaveDialog from './SegmentSaveDialog.vue';
import SegmentShareDialog from './SegmentShareDialog.vue';

const passthrough = { template: '<div><slot /></div>' };

const ButtonStub = {
  props: ['disabled'],
  emits: ['click'],
  template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const InputStub = {
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
};

const FilterBuilderStub = {
  name: 'FilterBuilder',
  props: ['fields', 'modelValue', 'showActions'],
  emits: ['apply', 'reset', 'update:modelValue'],
  template: '<div data-filterbuilder-stub></div>',
};

const stubs = {
  Select: { name: 'Select', ...passthrough },
  SelectContent: passthrough,
  SelectGroup: passthrough,
  SelectItem: passthrough,
  SelectItemText: passthrough,
  SelectLabel: passthrough,
  SelectTrigger: passthrough,
  SelectValue: passthrough,
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
  DialogClose: passthrough,
  ToggleGroup: passthrough,
  ToggleGroupItem: passthrough,
  Label: passthrough,
  Skeleton: passthrough,
  Badge: passthrough,
  Table: passthrough,
  TableHeader: passthrough,
  TableBody: passthrough,
  TableRow: passthrough,
  TableHead: passthrough,
  TableCell: passthrough,
  Button: ButtonStub,
  Input: InputStub,
  FilterBuilder: FilterBuilderStub,
};

const objectType: RecordObjectType = {
  id: 'ot-company',
  key: 'company',
  slug: 'companies',
  name: 'Companies',
  label: 'Firmen',
  requiresDeletionReason: false,
  hasHierarchy: false,
};

const fields: FieldDefinition[] = [
  {
    key: 'name',
    field_type: 'text_short',
    label: 'Name',
    is_required: false,
    is_filterable: true,
  },
  {
    key: 'amount',
    field_type: 'number',
    label: 'Amount',
    is_required: false,
    is_filterable: true,
  },
];

function summary(overrides: Partial<SegmentSummary>): SegmentSummary {
  return {
    id: 'seg-x',
    name: 'Segment X',
    object_type_id: 'ot-company',
    is_system: false,
    is_default: false,
    is_owner: false,
    ...overrides,
  };
}

function segmentIndexRoute() {
  return SegmentsController.index();
}

function serveSegments(segments: SegmentSummary[]): ServedRoute {
  return serveJson(segmentIndexRoute(), { data: segments });
}

async function settled(wrapper: VueWrapper): Promise<void> {
  await vi.waitFor(() => expect(wrapper.get('[data-segment-picker]').attributes('aria-busy')).toBe('false'));
  await flushPromises();
}

async function mountBar(segments: SegmentSummary[]) {
  const served = serveSegments(segments);

  const wrapper = mount(SegmentBar, {
    props: { objectType, fields },
    global: { stubs },
  });

  await settled(wrapper);

  return { wrapper, served };
}

async function mountActiveBar(
  segments: SegmentSummary[],
  activeSegmentId: string,
  extraProps: Record<string, unknown> = {},
): Promise<VueWrapper> {
  serveSegments(segments);

  const wrapper = mount(SegmentBar, {
    props: { objectType, fields, activeSegmentId, ...extraProps },
    global: { stubs },
  });

  await settled(wrapper);

  return wrapper;
}

beforeEach(() => {
  setUrlDefaults({ activeTeam: 'nubos' });
  document.cookie = 'XSRF-TOKEN=segment-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

function findGroup(wrapper: VueWrapper, bucket: string) {
  return wrapper.find(`[data-segment-group="${bucket}"]`);
}

function entryIdsIn(wrapper: VueWrapper, bucket: string): (string | undefined)[] {
  const group = findGroup(wrapper, bucket);

  if (!group.exists()) {
    return [];
  }

  return group.findAll('[data-segment-entry]').map((entry) => entry.attributes('data-segment-id'));
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('SegmentBar — grouped segment list (SC-2, toolbar grouping)', () => {
  it('labels its section for assistive technology without spending width on a visible caption (D1.4)', async () => {
    const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

    expect(wrapper.get('section').attributes('aria-label')).toBe('Segmente');
    expect(wrapper.text()).not.toContain('Segmente');
  });
});

describe('SegmentBar — default/system/own/shared four-bucket split (SC-12)', () => {
  function mixedPayload(): SegmentSummary[] {
    return [
      summary({
        id: 'seg-admin-default',
        name: 'Firmen-Standard',
        is_default: true,
      }),
      summary({
        id: 'seg-system',
        name: 'Recently updated',
        is_system: true,
      }),
      summary({
        id: 'seg-own',
        name: 'Mein Segment',
        is_owner: true,
      }),
      summary({
        id: 'seg-shared',
        name: 'Geteiltes Segment',
        is_owner: false,
        is_default: false,
        is_system: false,
      }),
    ];
  }

  it('renders four distinct groups default/system/own/shared, each with exactly its one entry', async () => {
    const { wrapper } = await mountBar(mixedPayload());

    for (const bucket of ['default', 'system', 'own', 'shared']) {
      expect(findGroup(wrapper, bucket).exists()).toBe(true);
    }

    expect(entryIdsIn(wrapper, 'default')).toEqual(['seg-admin-default']);
    expect(entryIdsIn(wrapper, 'system')).toEqual(['seg-system']);
    expect(entryIdsIn(wrapper, 'own')).toEqual(['seg-own']);
    expect(entryIdsIn(wrapper, 'shared')).toEqual(['seg-shared']);

    const allIds = wrapper.findAll('[data-segment-entry]').map((e) => e.attributes('data-segment-id'));
    expect(allIds).toHaveLength(4);
    expect(new Set(allIds).size).toBe(4);
  });

  it('resolves a row that is both default and owner to default, not own (precedence tie-break)', async () => {
    const { wrapper } = await mountBar([
      summary({
        id: 'seg-default-owner',
        name: 'Standard & Eigen',
        is_default: true,
        is_owner: true,
      }),
    ]);

    expect(entryIdsIn(wrapper, 'default')).toEqual(['seg-default-owner']);
    expect(findGroup(wrapper, 'own').exists()).toBe(false);
  });

  it('emits no apply and does not throw when a system-descriptor row without a real id is clicked', async () => {
    const { wrapper } = await mountBar([summary({ id: '', name: 'Mine', is_system: true })]);

    const entry = wrapper.find('[data-segment-group="system"] [data-segment-entry]');
    expect(entry.exists()).toBe(true);

    await entry.trigger('click');

    expect(wrapper.emitted('apply')).toBeUndefined();
  });
});

describe('SegmentBar — apply is emit-only (no grid/datasource access)', () => {
  it('emits exactly one apply event carrying the selected segment on entry click', async () => {
    const seg = summary({ id: 'seg-own', name: 'Mein Segment' });
    const { wrapper, served } = await mountBar([seg]);

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('GET');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/segments');
    expect(served.lastRequest()?.url.searchParams.get('object_type')).toBe('companies');
    expect(served.lastRequest()?.headers.get('Accept')).toBe('application/json');

    await wrapper.find('[data-segment-entry]').trigger('click');

    const applied = wrapper.emitted('apply');
    expect(applied).toHaveLength(1);
    expect(applied![0][0]).toEqual({ segment: seg });

    expect(served.requests).toHaveLength(1);
  });
});

describe('SegmentBar — empty and error states', () => {
  it('server-renders the same compact loading picker without an empty panel or request', async () => {
    const served = serveSegments([]);

    const html = await renderToString(SegmentBar, {
      props: { objectType, fields },
      global: { stubs },
    });

    expect(html).toContain('data-segment-picker');
    expect(html).toContain('aria-busy="true"');
    expect(html).not.toContain('Noch keine Segmente');
    expect(served.requests).toHaveLength(0);
  });

  it('keeps the same picker mounted while a deferred request resolves', async () => {
    const served = serveJson(segmentIndexRoute(), { data: [summary({ id: 'seg-own' })] }, { held: true });
    const wrapper = mount(SegmentBar, {
      props: { objectType, fields },
      global: { stubs },
    });
    const picker = wrapper.get('[data-segment-picker]').element;

    expect(wrapper.get('[data-segment-picker]').attributes('aria-busy')).toBe('true');

    await vi.waitFor(() => expect(served.requests).toHaveLength(1));
    served.release();
    await settled(wrapper);

    expect(wrapper.get('[data-segment-picker]').element).toBe(picker);
    expect(wrapper.get('[data-segment-picker]').attributes('aria-busy')).toBe('false');
    expect(wrapper.find('[data-segment-entry]').exists()).toBe(true);
  });

  it('retries a failed request from the picker and clears its compact error', async () => {
    const recovered = serveSegments([]);
    const failed = serveNetworkError(segmentIndexRoute(), { once: true });
    const wrapper = mount(SegmentBar, {
      props: { objectType, fields },
      global: { stubs },
    });
    await vi.waitFor(() => expect(wrapper.find('[data-segment-error]').exists()).toBe(true));
    const picker = wrapper.get('[data-segment-picker]').element;

    expect(wrapper.get('[data-segment-error]').attributes('role')).toBe('alert');
    expect(wrapper.get('[data-segment-retry]').text()).toContain('Erneut versuchen');
    wrapper.findComponent({ name: 'Select' }).vm.$emit('update:modelValue', 'retry-segments');
    await settled(wrapper);

    expect(failed.requests).toHaveLength(1);
    expect(recovered.requests).toHaveLength(1);
    expect(wrapper.get('[data-segment-picker]').element).toBe(picker);
    expect(wrapper.find('[data-segment-error]').exists()).toBe(false);
    expect(wrapper.find('[data-segment-create]').exists()).toBe(true);
  });

  it('keeps the picker and offers creation inside it when the index returns no segments', async () => {
    const { wrapper } = await mountBar([]);

    expect(wrapper.find('[data-segment-picker]').exists()).toBe(true);
    expect(wrapper.findAll('[data-segment-group]')).toHaveLength(0);
    expect(wrapper.findAll('[data-segment-entry]')).toHaveLength(0);
    await wrapper.get('[data-segment-create]').trigger('click');
    expect(wrapper.findComponent(SegmentSaveDialog).props('open')).toBe(true);
  });

  it('surfaces a visible error when the index responds non-OK', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const served = serveServerError(segmentIndexRoute(), {});

    const wrapper = mount(SegmentBar, {
      props: { objectType, fields },
      global: { stubs },
    });

    await vi.waitFor(() => expect(wrapper.find('[data-segment-error]').exists()).toBe(true));

    expect(served.requests).toHaveLength(1);
    expect(wrapper.get('[data-segment-error]').text()).toContain(
      'Die Segmente konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
    );
  });
});

async function mountDialog() {
  const wrapper = mount(SegmentSaveDialog, {
    props: { open: true, objectType, fields },
    global: { stubs },
  });
  await flushPromises();

  return wrapper;
}

describe('SegmentSaveDialog — embeds the FilterBuilder (SC-2)', () => {
  it('offers no FilterBuilder when the object type has no filterable fields', async () => {
    const wrapper = mount(SegmentSaveDialog, {
      props: {
        open: true,
        objectType,
        fields: [
          {
            key: 'name',
            field_type: 'text_short',
            label: 'Name',
            is_required: false,
            is_filterable: false,
          },
        ],
      },
      global: { stubs },
    });
    await flushPromises();

    expect(wrapper.findComponent(FilterBuilderStub).exists()).toBe(false);
  });
});

describe('SegmentSaveDialog — stale-tree contract', () => {
  it('blocks save until FilterBuilder has emitted a tree, then posts that exact tree', async () => {
    const saved: SegmentSummary = summary({
      id: 'seg-new',
      name: 'Neu',
    });
    const served = serveJson(SegmentsController.store(), { data: saved }, { status: 201 });

    const wrapper = await mountDialog();

    await wrapper.find('[data-segment-name]').setValue('Neu');

    const saveButton = wrapper.find('[data-form-save]');
    expect(saveButton.attributes('disabled')).toBeDefined();

    await saveButton.trigger('click');
    await flushPromises();
    expect(served.requests).toHaveLength(0);

    const tree: FilterGroupNode = {
      combinator: 'and',
      conditions: [{ field: 'name', operator: 'equals', value: 'Acme' }],
    };
    await wrapper.findComponent(FilterBuilderStub).vm.$emit('update:modelValue', tree);
    await flushPromises();

    expect(wrapper.find('[data-form-save]').attributes('disabled')).toBeUndefined();

    await wrapper.find('[data-form-save]').trigger('click');
    await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1));

    expect(served.requests).toHaveLength(1);
    expect(served.lastRequest()?.method).toBe('POST');
    expect(served.lastRequest()?.url.pathname).toBe('/nubos/engine/segments');
    expect(served.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('segment-token');
    expect(served.lastRequest()?.body).toEqual({
      name: 'Neu',
      object_type_id: 'ot-company',
      filter_definition: tree,
    });

    const savedEvent = wrapper.emitted('saved');
    expect(savedEvent).toHaveLength(1);
    expect(savedEvent![0][0]).toEqual(saved);
  });
});

describe('SegmentSaveDialog — a refused save', () => {
  const tree: FilterGroupNode = {
    combinator: 'and',
    conditions: [{ field: 'name', operator: 'equals', value: 'Acme' }],
  };

  async function submitSegment(): Promise<VueWrapper> {
    const wrapper = await mountDialog();

    await wrapper.find('[data-segment-name]').setValue('Neu');
    await wrapper.findComponent(FilterBuilderStub).vm.$emit('update:modelValue', tree);
    await flushPromises();
    await wrapper.find('[data-form-save]').trigger('click');

    return wrapper;
  }

  it('shows the reason the server gave and keeps the dialog open', async () => {
    serveValidationError(SegmentsController.store(), {
      name: ['Dieser Name ist bereits vergeben.'],
    });

    const wrapper = await submitSegment();

    await vi.waitFor(() => expect(wrapper.text()).toContain('Dieser Name ist bereits vergeben.'));
    expect(wrapper.emitted('saved')).toBeUndefined();
    expect(wrapper.emitted('update:open')).toBeUndefined();
    expect((wrapper.find('[data-segment-name]').element as HTMLInputElement).value).toBe('Neu');
  });

  it('falls back to the German sentence when the request cannot be sent', async () => {
    serveNetworkError(SegmentsController.store());

    const wrapper = await submitSegment();

    await vi.waitFor(() =>
      expect(wrapper.text()).toContain('Das Segment konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.'),
    );
    expect(wrapper.emitted('saved')).toBeUndefined();
  });

  it('blocks a second save while the first one is pending', async () => {
    const served = serveJson(
      SegmentsController.store(),
      { data: summary({ id: 'seg-new', name: 'Neu' }) },
      { status: 201, held: true },
    );

    const wrapper = await submitSegment();
    await vi.waitFor(() => expect(served.requests).toHaveLength(1));

    expect(wrapper.find('[data-form-save]').attributes('disabled')).toBeDefined();

    await wrapper.find('[data-form-save]').trigger('click');
    served.release();

    await vi.waitFor(() => expect(wrapper.emitted('saved')).toHaveLength(1));
    expect(served.requests).toHaveLength(1);
  });
});

describe('SegmentSaveDialog — form reset on close (stale-tree guard)', () => {
  const tree: FilterGroupNode = {
    combinator: 'and',
    conditions: [{ field: 'name', operator: 'equals', value: 'Acme' }],
  };

  it('resets name and pending tree when the dialog is closed and reopened', async () => {
    const wrapper = await mountDialog();

    await wrapper.find('[data-segment-name]').setValue('Neu');
    await wrapper.findComponent(FilterBuilderStub).vm.$emit('update:modelValue', tree);
    await flushPromises();
    expect(wrapper.find('[data-form-save]').attributes('disabled')).toBeUndefined();

    const cancel = wrapper.findAll('button').find((button) => button.text().includes('Abbrechen'));
    expect(cancel).toBeDefined();
    await cancel!.trigger('click');
    await flushPromises();

    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false]);

    await wrapper.setProps({ open: false });
    await wrapper.setProps({ open: true });
    await flushPromises();

    expect((wrapper.find('[data-segment-name]').element as HTMLInputElement).value).toBe('');
    expect(wrapper.find('[data-form-save]').attributes('disabled')).toBeDefined();
  });
});

describe('SegmentBar action row', () => {
  it('offers creating a segment as the first entry of the dropdown', async () => {
    const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);
    const entries = wrapper.findAll('[data-segment-create], [data-segment-entry]');

    expect(entries[0].attributes('data-segment-create')).toBeDefined();
    expect(entries[0].text()).toContain('Neues Segment anlegen');
  });

  it('opens the save dialog from that entry', async () => {
    const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);
    const dialog = wrapper.findComponent(SegmentSaveDialog);

    expect(dialog.props('open')).toBe(false);

    await wrapper.get('[data-segment-create]').trigger('click');

    expect(dialog.props('open')).toBe(true);
  });

  it('hides the clear action while no segment is active', async () => {
    const { wrapper } = await mountBar([summary({ id: 'seg-own' })]);

    expect(wrapper.find('[data-testid="segment-clear"]').exists()).toBe(false);
  });

  it('renders the clear action as an icon button once a segment is active', async () => {
    const wrapper = await mountActiveBar([summary({ id: 'seg-own' })], 'seg-own');

    const clear = wrapper.get('[data-testid="segment-clear"]');

    expect(clear.text().trim()).toBe('');
    expect(clear.attributes('aria-label')).toBe('Segment schließen');
    expect(clear.attributes('title')).toBe('Segment schließen');
  });

  it('emits clear when the clear action is used', async () => {
    const wrapper = await mountActiveBar([summary({ id: 'seg-own' })], 'seg-own');

    await wrapper.get('[data-testid="segment-clear"]').trigger('click');

    expect(wrapper.emitted('clear')).toHaveLength(1);
  });

  it('hides the share action while no segment is active', async () => {
    const { wrapper } = await mountBar([summary({ id: 'seg-own', is_owner: true })]);

    expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(false);
  });

  it('hides the share action for a segment the user does not own', async () => {
    const wrapper = await mountActiveBar([summary({ id: 'seg-foreign', is_owner: false })], 'seg-foreign');

    expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(false);
  });

  it('hides the share action where the caller opted out of it', async () => {
    const wrapper = await mountActiveBar([summary({ id: 'seg-own', is_owner: true })], 'seg-own', {
      allowShare: false,
    });

    expect(wrapper.find('[data-testid="segment-share"]').exists()).toBe(false);
    expect(wrapper.findComponent(SegmentShareDialog).exists()).toBe(false);
  });

  it('offers the share action for an own active segment and loads its recipients on click', async () => {
    const options = serveJson(SegmentShareOptionsController({ segment: 'seg-own' }), {
      options: {
        user: [{ value: 'u1', label: 'Olivia Baumann' }],
        team: [{ value: 't1', label: 'Vertrieb' }],
        role: [{ value: 'r1', label: 'Administrator' }],
      },
    });
    const grants = serveJson(SegmentSharesController.index({ segment: 'seg-own' }), { data: [] });

    const wrapper = await mountActiveBar([summary({ id: 'seg-own', is_owner: true })], 'seg-own');

    expect(options.requests).toHaveLength(0);

    const share = wrapper.get('[data-testid="segment-share"]');

    expect(share.attributes('aria-label')).toBe('Segment teilen');

    await share.trigger('click');
    await vi.waitFor(() => expect(options.requests).toHaveLength(1));
    await flushPromises();

    expect(options.lastRequest()?.method).toBe('GET');
    expect(options.lastRequest()?.url.pathname).toBe('/nubos/engine/segments/seg-own/share-options');
    await vi.waitFor(() => expect(grants.requests).toHaveLength(1));

    expect(wrapper.findComponent(SegmentShareDialog).exists()).toBe(true);
    expect(wrapper.findComponent(SegmentShareDialog).props('open')).toBe(true);
  });
});
