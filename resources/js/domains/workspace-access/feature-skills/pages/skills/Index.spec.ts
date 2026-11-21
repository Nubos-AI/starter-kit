import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
import { activateRow, gridOf, rowAction, rowActivation, selectRow, selectionCheckbox } from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import CreateButton from '@/shared/ui/CreateButton.vue';
import ViewStates from '@/shared/ui/ViewStates.vue';
import Index from './Index.vue';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  visitMock: vi.fn(),
  pageState: {
    url: '/nubos/engine/skills',
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
    visit: visitMock,
    post: postMock,
    delete: deleteMock,
  },
  usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

interface SkillRow {
  id: string;
  name: string;
  users_count: number;
  can_update: boolean;
  can_delete: boolean;
  delete_reason: string | null;
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

function row(overrides: Partial<SkillRow> = {}): SkillRow {
  return {
    id: '01SKILL00000000000000001',
    name: 'Buchhaltung',
    users_count: 2,
    can_update: true,
    can_delete: true,
    delete_reason: null,
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
  skills: SkillRow[] = [row()],
  granted: string[] = ['skills.view', 'skills.create', 'skills.update', 'skills.delete'],
): Wrapper {
  grant(...granted);

  return track(mount(Index, { props: { skills }, global: { stubs } }));
}

beforeEach(() => {
  deleteMock.mockReset();
  postMock.mockReset();
  visitMock.mockReset();
});

afterEach(() => {
  while (mounted.length > 0) {
    mounted.pop()?.unmount();
  }
});

describe('skills/Index', () => {
  it('hides the grid when there are no skills', () => {
    const wrapper = mountIndex([]);

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);
    expect(
      (
        wrapper.findComponent(ViewStates) as unknown as {
          props(key: 'state'): string;
        }
      ).props('state'),
    ).toBe('empty');
    expect(wrapper.findComponent(CreateButton).exists()).toBe(true);
  });

  it('disables the actions of a locked row and names the server reason', () => {
    const locked = row({
      can_update: false,
      can_delete: false,
      delete_reason: 'Diese Fähigkeit wird noch verwendet.',
    });
    const wrapper = mountIndex([locked]);

    expect(rowAction<SkillRow>(wrapper, 'Bearbeiten').isDisabled?.(locked)).toBe(true);
    expect(rowAction<SkillRow>(wrapper, 'Löschen').isDisabled?.(locked)).toBe(true);
    expect(rowAction<SkillRow>(wrapper, 'Löschen').disabledReason?.(locked)).toBe(
      'Diese Fähigkeit wird noch verwendet.',
    );
  });

  it('opens the row on click only when the actor may edit it', () => {
    const wrapper = mountIndex([row()]);
    const isRowActivatable = rowActivation<SkillRow>(wrapper);

    expect(isRowActivatable(row())).toBe(true);
    expect(isRowActivatable(row({ can_update: false }))).toBe(false);
  });

  it('leads the row activation to the edit page', () => {
    const wrapper = mountIndex();

    activateRow(wrapper, row());

    expect(visitMock).toHaveBeenCalledWith(SkillsController.edit.url({ skill: row().id }));
  });

  it('offers the create button only with the create right', () => {
    expect(mountIndex().findComponent(CreateButton).exists()).toBe(true);
    expect(mountIndex([row()], ['skills.view']).findComponent(CreateButton).exists()).toBe(false);
  });

  it('asks before deleting a single skill', async () => {
    const wrapper = mountIndex();

    rowAction<SkillRow>(wrapper, 'Löschen').onClick?.(row());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('leaves a locked row unselectable', () => {
    const wrapper = mountIndex([
      row(),
      row({
        id: '01SKILL00000000000000002',
        can_delete: false,
        delete_reason: 'Keine Berechtigung',
      }),
    ]);

    expect(selectionCheckbox(wrapper, row()).exists()).toBe(true);
    expect(
      selectionCheckbox(
        wrapper,
        row({
          id: '01SKILL00000000000000002',
          can_delete: false,
          delete_reason: 'Keine Berechtigung',
        }),
      ).exists(),
    ).toBe(false);
  });

  it('sends the selected ids to the bulk delete endpoint', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledWith(SkillsController.bulkDestroy.url(), { ids: [row().id] }, expect.anything());
  });
});
