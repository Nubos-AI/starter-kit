import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentSharesController from '@/actions/App/Http/Controllers/Segments/SegmentSharesController';
import { serveEmpty, serveJson, serveNetworkError, serveServerError, serveValidationError } from '@/shared/testing/msw';
import type { ServedRoute } from '@/shared/testing/msw';
import Combobox from '@/shared/ui/primitives/combobox/Combobox.vue';
import { setUrlDefaults } from '@/wayfinder';
import type { SegmentSummary } from '../../business-data/data/useSegments';
import type { GranteeOption, GranteeType, SegmentShare } from '../../business-data/data/useSegmentShares';
import SegmentShareDialog from './SegmentShareDialog.vue';

setUrlDefaults({ activeTeam: 'nubos' });

const passthrough = { template: '<div><slot /></div>' };

const ButtonStub = {
  props: ['disabled'],
  emits: ['click'],
  template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
};

const CheckboxStub = {
  props: ['modelValue'],
  emits: ['update:modelValue'],
  template: '<button type="button" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
};

const stubs = {
  Select: passthrough,
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
  Label: passthrough,
  Badge: passthrough,
  Skeleton: passthrough,
  Button: ButtonStub,
  Checkbox: CheckboxStub,
};

const granteeOptions: Record<GranteeType, GranteeOption[]> = {
  team: [
    { id: 'team-1', label: 'Vertrieb' },
    { id: 'team-2', label: 'Support' },
  ],
  role: [{ id: 'role-1', label: 'Administrator' }],
  user: [
    { id: 'user-1', label: 'Alice' },
    { id: 'user-2', label: 'Bob' },
    { id: 'user-3', label: 'Carol' },
  ],
};

const SEGMENT_ID = 'seg-owned';

interface WireShare {
  id: string;
  grantee_type: string;
  grantee_id: string;
  can_edit: boolean;
}

const GRANTEE_TYPE_FQCN: Record<GranteeType, string> = {
  team: 'App\\Models\\Team',
  role: 'App\\Models\\Role',
  user: 'App\\Models\\User',
};

const RAW_MODEL_NAMESPACE = 'App\\Models';

function segment(overrides: Partial<SegmentSummary> = {}): SegmentSummary {
  return {
    id: SEGMENT_ID,
    name: 'Segment X',
    object_type_id: 'ot-company',
    is_system: false,
    is_default: false,
    is_owner: true,
    ...overrides,
  };
}

function share(overrides: Partial<SegmentShare> = {}): WireShare {
  const merged: SegmentShare = {
    id: 'share-1',
    grantee_type: 'team',
    grantee_id: 'team-1',
    can_edit: false,
    ...overrides,
  };

  return {
    id: merged.id,
    grantee_type: GRANTEE_TYPE_FQCN[merged.grantee_type],
    grantee_id: merged.grantee_id,
    can_edit: merged.can_edit,
  };
}

interface ShareServerOptions {
  grants?: WireShare[];
  created?: WireShare;
  held?: boolean;
}

interface ShareServer {
  list: ServedRoute;
  created: ServedRoute;
  revoked: (shareId: string) => ServedRoute;
}

const createdShare: WireShare = share({
  id: 'share-new',
});

function sharesRoute() {
  return SegmentSharesController.index({ segment: SEGMENT_ID });
}

function storeRoute() {
  return SegmentSharesController.store({ segment: SEGMENT_ID });
}

function revokeRoute(shareId: string) {
  return SegmentSharesController.destroy({
    segment: SEGMENT_ID,
    share: shareId,
  });
}

function serveShares(options: ShareServerOptions = {}): ShareServer {
  const list = serveJson(sharesRoute(), { data: options.grants ?? [] });
  const created = serveJson(
    storeRoute(),
    { data: options.created ?? createdShare },
    { status: 201, held: options.held },
  );

  return {
    list,
    created,
    revoked: (shareId) => serveEmpty(revokeRoute(shareId)),
  };
}

function postedBodies(served: ServedRoute): Record<string, unknown>[] {
  return served.requests.map((request) => request.body as Record<string, unknown>);
}

function hasSettledLoad(wrapper: ReturnType<typeof mount>): boolean {
  return (
    wrapper.find('[data-grant-entry]').exists() ||
    wrapper.find('[data-share-error]').exists() ||
    wrapper.text().includes('Dieses Segment ist noch nicht geteilt.')
  );
}

async function mountDialog(
  segmentOverrides: Partial<SegmentSummary> = {},
  granteeChoices: Record<GranteeType, GranteeOption[]> = granteeOptions,
) {
  const wrapper = mount(SegmentShareDialog, {
    props: {
      open: true,
      segment: segment(segmentOverrides),
      granteeOptions: granteeChoices,
    },
    global: { stubs },
  });

  if (segment(segmentOverrides).is_owner) {
    await vi.waitFor(() => expect(hasSettledLoad(wrapper)).toBe(true));
  }

  await flushPromises();

  return wrapper;
}

async function createShare(
  wrapper: ReturnType<typeof mount>,
  served: ServedRoute,
  expectedRequests: number,
): Promise<void> {
  await wrapper.find('[data-share-create]').trigger('click');
  await vi.waitFor(() => expect(served.requests).toHaveLength(expectedRequests));
  await flushPromises();
}

function granteeSelectText(wrapper: ReturnType<typeof mount>): string {
  const combobox = wrapper.findComponent(Combobox);
  expect(combobox.exists()).toBe(true);

  return (combobox.props('options') as { label: string }[]).map((option) => option.label).join(' ');
}

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=share-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  vi.restoreAllMocks();
});

describe('SegmentShareDialog — grantee-type switch (SC-4)', () => {
  it('shows exactly the options for the selected grantee type as team→role→user', async () => {
    serveShares();
    const wrapper = await mountDialog();

    expect(granteeSelectText(wrapper)).toContain('Vertrieb');
    expect(granteeSelectText(wrapper)).toContain('Support');
    expect(granteeSelectText(wrapper)).not.toContain('Administrator');
    expect(granteeSelectText(wrapper)).not.toContain('Alice');

    await wrapper.find('[data-grantee-type-option="role"]').trigger('click');
    await flushPromises();

    expect(granteeSelectText(wrapper)).toContain('Administrator');
    expect(granteeSelectText(wrapper)).not.toContain('Vertrieb');
    expect(granteeSelectText(wrapper)).not.toContain('Alice');

    await wrapper.find('[data-grantee-type-option="user"]').trigger('click');
    await flushPromises();

    expect(granteeSelectText(wrapper)).toContain('Alice');
    expect(granteeSelectText(wrapper)).toContain('Bob');
    expect(granteeSelectText(wrapper)).toContain('Carol');
    expect(granteeSelectText(wrapper)).not.toContain('Administrator');
    expect(granteeSelectText(wrapper)).not.toContain('Vertrieb');
  });
});

describe('SegmentShareDialog — can_edit toggle (view vs edit, least privilege)', () => {
  it('posts can_edit:true after the edit toggle is switched on', async () => {
    const served = serveShares();
    const wrapper = await mountDialog();

    await wrapper.find('[data-can-edit-toggle]').trigger('click');
    await flushPromises();

    await createShare(wrapper, served.created, 1);

    expect(postedBodies(served.created)[0].can_edit).toBe(true);
  });
});

describe('SegmentShareDialog — create grant (correct POST)', () => {
  it('sends exactly one POST to the shares endpoint with the selected grantee', async () => {
    const served = serveShares();
    const wrapper = await mountDialog();

    expect(served.list.requests).toHaveLength(1);
    expect(served.list.lastRequest()?.method).toBe('GET');
    expect(served.list.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares`);

    await wrapper.find('[data-grantee-type-option="user"]').trigger('click');
    await flushPromises();

    await createShare(wrapper, served.created, 1);

    expect(served.created.lastRequest()?.method).toBe('POST');
    expect(served.created.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares`);
    expect(served.created.lastRequest()?.headers.get('X-XSRF-TOKEN')).toBe('share-token');
    expect(served.created.lastRequest()?.headers.get('Content-Type')).toBe('application/json');

    const body = postedBodies(served.created)[0];
    expect(Object.keys(body).sort()).toEqual(['can_edit', 'grantee_id', 'grantee_type']);
    expect(body.grantee_type).toBe('user');
    expect(body.grantee_id).toBe('user-1');
    expect(body.can_edit).toBe(false);
  });

  it('keeps the request body grantee_type as the SHORT key, never the FQCN (no normalization bleed into the write path)', async () => {
    const served = serveShares();
    const wrapper = await mountDialog();

    await createShare(wrapper, served.created, 1);

    await wrapper.find('[data-grantee-type-option="user"]').trigger('click');
    await flushPromises();

    await createShare(wrapper, served.created, 2);

    const sentGranteeTypes = postedBodies(served.created).map((body) => body.grantee_type);

    expect(sentGranteeTypes).toEqual(['team', 'user']);

    for (const value of sentGranteeTypes) {
      expect(value).not.toContain(RAW_MODEL_NAMESPACE);
    }
  });
});

describe('SegmentShareDialog — FQCN grantee_type normalization (real wire shape)', () => {
  it.each([
    ['team', 'team-1', 'Vertrieb'],
    ['role', 'role-1', 'Administrator'],
    ['user', 'user-1', 'Alice'],
  ] as ReadonlyArray<readonly [GranteeType, string, string]>)(
    'resolves the local label for a loaded %s grant delivered with an FQCN grantee_type',
    async (type, granteeId, label) => {
      const grants = [
        share({
          id: 'share-1',
          grantee_type: type,
          grantee_id: granteeId,
        }),
      ];

      serveShares({ grants });
      const wrapper = await mountDialog();

      const entry = wrapper.find('[data-grant-entry]');
      expect(entry.exists()).toBe(true);

      const text = entry.text();
      expect(text).toContain(label);
      expect(text).not.toContain(granteeId);
      expect(text).not.toContain(RAW_MODEL_NAMESPACE);
    },
  );

  it('normalizes the FQCN grantee_type on the POST response so the appended row shows the resolved label', async () => {
    const created = share({
      id: 'share-created',
      grantee_type: 'user',
      grantee_id: 'user-1',
    });

    const served = serveShares({ created });
    const wrapper = await mountDialog();

    await wrapper.find('[data-grantee-type-option="user"]').trigger('click');
    await flushPromises();

    await createShare(wrapper, served.created, 1);

    const entry = wrapper
      .findAll('[data-grant-entry]')
      .find((node) => node.attributes('data-grant-id') === 'share-created');

    expect(entry).toBeDefined();

    const text = entry!.text();
    expect(text).toContain('Alice');
    expect(text).not.toContain('user-1');
    expect(text).not.toContain(RAW_MODEL_NAMESPACE);
  });
});

describe('SegmentShareDialog — grants list + revoke (SC-4)', () => {
  it('renders one entry per loaded grant with view vs edit visually distinguished', async () => {
    const grants = [
      share({ id: 'share-1', can_edit: false }),
      share({
        id: 'share-2',
        grantee_type: 'user',
        grantee_id: 'user-1',
        can_edit: true,
      }),
    ];

    serveShares({ grants });
    const wrapper = await mountDialog();

    const entries = wrapper.findAll('[data-grant-entry]');
    expect(entries).toHaveLength(2);

    const byId = (id: string) => entries.find((entry) => entry.attributes('data-grant-id') === id)!;

    expect(byId('share-1').exists()).toBe(true);
    expect(byId('share-1').attributes('data-grant-can-edit')).toBe('false');
    expect(byId('share-1').text()).toContain('Vertrieb');

    expect(byId('share-2').attributes('data-grant-can-edit')).toBe('true');
    expect(byId('share-2').text()).toContain('Alice');
  });

  it('sends a DELETE to the specific grant endpoint when revoke is clicked', async () => {
    const grants = [share({ id: 'share-1' }), share({ id: 'share-2', grantee_id: 'team-2' })];

    const served = serveShares({ grants });
    const revoked = served.revoked('share-2');
    const wrapper = await mountDialog();

    const entry = wrapper.findAll('[data-grant-entry]').find((node) => node.attributes('data-grant-id') === 'share-2')!;

    await entry.find('[data-grant-revoke]').trigger('click');
    await vi.waitFor(() => expect(wrapper.findAll('[data-grant-entry]')).toHaveLength(1));

    expect(revoked.requests).toHaveLength(1);
    expect(revoked.lastRequest()?.method).toBe('DELETE');
    expect(revoked.lastRequest()?.url.pathname).toBe(`/nubos/engine/segments/${SEGMENT_ID}/shares/share-2`);
    expect(wrapper.get('[data-grant-entry]').attributes('data-grant-id')).toBe('share-1');
  });

  it('keeps the grant and explains why when revoking fails', async () => {
    const grants = [share({ id: 'share-1' })];
    serveShares({ grants });
    serveServerError(revokeRoute('share-1'), {});
    const wrapper = await mountDialog();

    await wrapper.find('[data-grant-revoke]').trigger('click');
    await vi.waitFor(() => expect(wrapper.find('[data-share-error]').exists()).toBe(true));

    expect(wrapper.get('[data-share-error]').text()).toContain(
      'Die Freigabe konnte nicht widerrufen werden. Bitte versuchen Sie es erneut.',
    );
    expect(wrapper.findAll('[data-grant-entry]')).toHaveLength(1);
  });
});

describe('SegmentShareDialog — creator-only gate (defense in depth)', () => {
  it('does not render share controls when the viewer is not the segment owner', async () => {
    const served = serveShares();
    const wrapper = await mountDialog({ is_owner: false });

    expect(served.list.requests).toHaveLength(0);
    expect(wrapper.findAll('[data-grantee-type-option]')).toHaveLength(0);
    expect(wrapper.find('[data-grantee-select]').exists()).toBe(false);
    expect(wrapper.find('[data-share-create]').exists()).toBe(false);
    expect(wrapper.find('[data-can-edit-toggle]').exists()).toBe(false);
  });
});

describe('SegmentShareDialog — error surfacing (no silent catch)', () => {
  it('shows a visible error when creating a grant responds non-OK', async () => {
    serveJson(sharesRoute(), { data: [] });
    const failed = serveServerError(storeRoute(), {});
    const wrapper = await mountDialog();

    await createShare(wrapper, failed, 1);

    await vi.waitFor(() => expect(wrapper.find('[data-share-error]').exists()).toBe(true));
    expect(wrapper.get('[data-share-error]').text()).toContain(
      'Die Freigabe konnte nicht angelegt werden. Bitte versuchen Sie es erneut.',
    );
    expect(wrapper.findAll('[data-grant-entry]')).toHaveLength(0);
  });

  it('shows the reason the server gave when it refuses a grant', async () => {
    serveJson(sharesRoute(), { data: [] });
    const refused = serveValidationError(storeRoute(), {
      grantee_id: ['Dieses Team hat bereits Zugriff.'],
    });
    const wrapper = await mountDialog();

    await createShare(wrapper, refused, 1);

    await vi.waitFor(() => expect(wrapper.find('[data-share-error]').exists()).toBe(true));
    expect(wrapper.get('[data-share-error]').text()).toContain('Dieses Team hat bereits Zugriff.');
    expect(wrapper.findAll('[data-grant-entry]')).toHaveLength(0);
  });

  it('blocks a second grant while the first one is still being created', async () => {
    const served = serveShares({ held: true });
    const wrapper = await mountDialog();

    await createShare(wrapper, served.created, 1);

    expect(wrapper.get('[data-share-create]').attributes('disabled')).toBeDefined();

    await wrapper.find('[data-share-create]').trigger('click');
    served.created.release();

    await vi.waitFor(() => expect(wrapper.findAll('[data-grant-entry]')).toHaveLength(1));
    expect(served.created.requests).toHaveLength(1);
  });

  it('shows a visible error when loading grants fails', async () => {
    const failed = serveNetworkError(sharesRoute());
    const wrapper = await mountDialog();

    await vi.waitFor(() => expect(wrapper.find('[data-share-error]').exists()).toBe(true));
    expect(failed.requests).toHaveLength(1);
    expect(wrapper.get('[data-share-error]').text()).toContain(
      'Die Freigaben konnten nicht geladen werden. Bitte versuchen Sie es erneut.',
    );
  });
});

describe('SegmentShareDialog — local label resolution (no backend label)', () => {
  it('falls back to the grantee_id when no matching option exists', async () => {
    const grants = [share({ id: 'share-1', grantee_id: 'team-deleted' })];

    serveShares({ grants });
    const wrapper = await mountDialog();

    const entry = wrapper.find('[data-grant-entry]');
    expect(entry.exists()).toBe(true);
    expect(entry.text()).toContain('team-deleted');
  });
});

describe('SegmentShareDialog — empty grantee options (sane empty state)', () => {
  it('keeps the create action disabled and does not crash when the selected grantee type has no options', async () => {
    serveShares();

    const wrapper = await mountDialog(
      {},
      {
        team: [],
        role: [{ id: 'role-1', label: 'Administrator' }],
        user: [],
      },
    );

    const createButton = wrapper.find('[data-share-create]');
    expect(createButton.exists()).toBe(true);
    expect(createButton.attributes('disabled')).toBeDefined();
    expect(granteeSelectText(wrapper).trim()).toBe('');
  });
});
