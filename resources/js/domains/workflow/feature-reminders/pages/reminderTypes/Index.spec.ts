import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ReminderTypesController from '@/actions/App/Http/Controllers/Engine/ReminderTypesController';
import { gridOf, rowAction, rowActivation, selectRow } from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import CreateButton from '@/shared/ui/CreateButton.vue';
import Index from './Index.vue';

const { deleteMock, postMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  pageState: {
    url: '/nubos/engine/reminder-types',
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

interface ReminderTypeRow {
  id: string;
  name: string;
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

function row(overrides: Partial<ReminderTypeRow> = {}): ReminderTypeRow {
  return {
    id: '01REMINDERTYPE0000000001',
    name: 'Anruf',
    can_update: true,
    can_delete: true,
    ...overrides,
  };
}

function grant(...names: string[]): void {
  pageState.props.auth.can = Object.fromEntries(names.map((name) => [name, true]));
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
  mounted.push(wrapper);

  return wrapper;
}

function mountIndex(
  reminderTypes: ReminderTypeRow[] = [row()],
  granted: string[] = ['reminder-types.create', 'reminder-types.update', 'reminder-types.delete'],
): Wrapper {
  grant(...granted);

  return track(mount(Index, { props: { reminderTypes }, global: { stubs } }));
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

describe('reminderTypes/Index', () => {
  it('shows no grid without any reminder type', () => {
    expect(gridOf(mountIndex([])).exists()).toBe(false);
  });

  it('disables the actions of a row the actor may not change', () => {
    const denied = row({ can_update: false, can_delete: false });
    const wrapper = mountIndex([denied]);

    expect(rowAction<ReminderTypeRow>(wrapper, 'Bearbeiten').isDisabled?.(denied)).toBe(true);
    expect(rowAction<ReminderTypeRow>(wrapper, 'Löschen').disabledReason?.(denied)).toBe('Keine Berechtigung');
  });

  it('asks before deleting a single reminder type', async () => {
    const wrapper = mountIndex();

    rowAction<ReminderTypeRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('opens the row on click only when the actor may edit it', () => {
    const wrapper = mountIndex([row()]);
    const isRowActivatable = rowActivation<ReminderTypeRow>(wrapper);

    expect(isRowActivatable(row())).toBe(true);
    expect(isRowActivatable(row({ can_update: false }))).toBe(false);
  });

  it('offers the create button only with the create right', () => {
    expect(mountIndex().findComponent(CreateButton).exists()).toBe(true);
    expect(mountIndex([row()], ['reminder-types.view']).findComponent(CreateButton).exists()).toBe(false);
  });

  it('sends the selected ids to the bulk delete endpoint', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(
      ReminderTypesController.bulkDestroy.url(),
      { ids: [row().id] },
      expect.anything(),
    );
  });
});
