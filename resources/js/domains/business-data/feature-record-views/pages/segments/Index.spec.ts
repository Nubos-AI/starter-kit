import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
import { gridOf, rowAction, rowActivation, selectRow, selectionCheckbox } from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import Index from './Index.vue';

const { deleteMock, postMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  pageState: {
    url: '/nubos/engine/segment-management',
    props: {
      auth: {
        user: null,
        can: {} as Record<string, boolean>,
        authority: null,
      },
    },
  },
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: vi.fn(),
    post: postMock,
    delete: deleteMock,
  },
  usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

interface SegmentRow {
  id: string;
  name: string;
  object_type: string | null;
  is_owner: boolean;
  can_update: boolean;
  can_delete: boolean;
}

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
  Dialog: passthrough,
  DialogContent: passthrough,
  DialogHeader: passthrough,
  DialogFooter: passthrough,
  DialogTitle: passthrough,
  DialogDescription: passthrough,
};

function row(overrides: Partial<SegmentRow> = {}): SegmentRow {
  return {
    id: '01SEGMENT000000000000001',
    name: 'Offene Aufgaben',
    object_type: 'Companies',
    is_owner: true,
    can_update: true,
    can_delete: true,
    ...overrides,
  };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
  mounted.push(wrapper);

  return wrapper;
}

function mountIndex(segments: SegmentRow[] = [row()]): Wrapper {
  return track(mount(Index, { props: { segments }, global: { stubs } }));
}

beforeEach(() => {
  deleteMock.mockReset();
  postMock.mockReset();
});

afterEach(() => {
  while (mounted.length > 0) {
    mounted.pop()?.unmount();
  }
});

describe('segments/Index', () => {
  it('shows no grid without any segment', () => {
    expect(gridOf(mountIndex([])).exists()).toBe(false);
  });

  it('disables the delete action of a shared segment', () => {
    const shared = row({ is_owner: false, can_delete: false });
    const wrapper = mountIndex([shared]);

    expect(rowAction<SegmentRow>(wrapper, 'Löschen').isDisabled?.(shared)).toBe(true);
    expect(rowAction<SegmentRow>(wrapper, 'Löschen').disabledReason?.(shared)).toBe('Keine Berechtigung');
    expect(rowAction<SegmentRow>(wrapper, 'Bearbeiten').isDisabled?.(shared)).toBe(false);
  });

  it('asks before deleting a single segment', async () => {
    const wrapper = mountIndex();

    rowAction<SegmentRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('opens the row on click only when the actor may edit it', () => {
    const wrapper = mountIndex([row()]);
    const isRowActivatable = rowActivation<SegmentRow>(wrapper);

    expect(isRowActivatable(row())).toBe(true);
    expect(isRowActivatable(row({ can_update: false }))).toBe(false);
  });

  it('keeps a segment the actor may not delete out of the selection', () => {
    const wrapper = mountIndex([row({ can_delete: false })]);

    expect(selectionCheckbox(wrapper, row({ can_delete: false })).exists()).toBe(false);
  });

  it('sends the selected ids to the bulk delete endpoint', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(
      SegmentManagementController.bulkDestroy.url(),
      { ids: [row().id] },
      expect.anything(),
    );
  });
});
