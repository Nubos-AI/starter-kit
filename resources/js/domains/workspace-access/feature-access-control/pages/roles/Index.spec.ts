import { mount } from '@vue/test-utils';
import type { ValueGetterFunc } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import {
  columnParams,
  gridColumn,
  gridColumns,
  rowAction,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import type { RoleRow } from '@/shared/types/roles';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import Index from './Index.vue';

const { visitMock, deleteMock, postMock, pageState } = vi.hoisted(() => ({
  visitMock: vi.fn(),
  deleteMock: vi.fn(),
  postMock: vi.fn(),
  pageState: {
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
  router: { visit: visitMock, delete: deleteMock, post: postMock },
  usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

const scopes = [{ value: 'tenant', label: 'Tenant' }];
const authorities = [{ value: 'scope_admin', label: 'Scope Admin' }];

function role(overrides: Partial<RoleRow> = {}): RoleRow {
  return {
    id: 'role-1',
    name: 'Vertrieb',
    scope: 'tenant',
    authority: null,
    is_system: false,
    grants_subteam_visibility: false,
    can_update: true,
    can_delete: true,
    delete_reason: null,
    user_count: 0,
    ...overrides,
  };
}

function mountIndex(roles: RoleRow[], canCreate = true): Wrapper {
  pageState.props.auth.can = canCreate ? { 'roles.create': true } : {};

  return mount(Index, {
    props: { roles, scopes, authorities },
  });
}

beforeEach(() => {
  visitMock.mockReset();
  deleteMock.mockReset();
});

describe('roles/Index — grid consistency', () => {
  it('links the name to the edit page and shows a user-count column', () => {
    const wrapper = mountIndex([role()]);
    const cols = gridColumns(wrapper);

    const href = columnParams(wrapper, 'name').href as (row: RoleRow) => string | undefined;
    expect(href(role())).toBe(RolesController.edit.url({ role: 'role-1' }));

    expect(cols.some((column) => column.field === 'user_count')).toBe(true);
  });

  it('renders the name as plain text without the update right', () => {
    const wrapper = mountIndex([role()]);
    const href = columnParams(wrapper, 'name').href as (row: RoleRow) => string | undefined;

    expect(href({ ...role(), can_update: false })).toBeUndefined();
  });
});

describe('roles/Index — actions follow the visibility rules', () => {
  it('shows edit and delete enabled for a role the user may manage', () => {
    const wrapper = mountIndex([role()]);
    const row = role();

    const edit = rowAction<RoleRow>(wrapper, 'Bearbeiten');
    const remove = rowAction<RoleRow>(wrapper, 'Löschen');

    expect(edit.isDisabled?.(row)).toBe(false);
    expect(remove.isDisabled?.(row)).toBe(false);
  });

  it('greys out edit and delete with a reason when nothing is allowed', () => {
    const wrapper = mountIndex([role()]);
    const row = role({ can_update: false, can_delete: false });
    const edit = rowAction<RoleRow>(wrapper, 'Bearbeiten');
    const remove = rowAction<RoleRow>(wrapper, 'Löschen');

    expect(edit.isDisabled?.(row)).toBe(true);
    expect(edit.disabledReason?.(row)).toBe('Keine Berechtigung');
    expect(remove.isDisabled?.(row)).toBe(true);
    expect(remove.disabledReason?.(row)).toBe('Keine Berechtigung');
  });

  it('greys out delete (still visible) and names the reason the server gives — role still held', () => {
    const wrapper = mountIndex([role()]);
    const reason = 'Diese Rolle ist noch 2 Teams zugewiesen und kann nicht gelöscht werden.';
    const row = role({ can_delete: false, delete_reason: reason });
    const remove = rowAction<RoleRow>(wrapper, 'Löschen');

    expect(remove.isDisabled?.(row)).toBe(true);
    expect(remove.disabledReason?.(row)).toBe(reason);
  });
});

describe('roles/Index — delete confirmation', () => {
  it('opens the confirm dialog instead of deleting immediately', async () => {
    const wrapper = mountIndex([role()]);

    rowAction<RoleRow>(wrapper, 'Löschen').onClick?.(role());
    await wrapper.vm.$nextTick();

    const dialog = wrapper.findComponent(ConfirmDialog);
    expect(dialog.props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('deletes only after the dialog is confirmed', async () => {
    const wrapper = mountIndex([role()]);

    rowAction<RoleRow>(wrapper, 'Löschen').onClick?.(role());
    await wrapper.vm.$nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(deleteMock).toHaveBeenCalledTimes(1);
    expect(deleteMock.mock.calls[0][0]).toBe(RolesController.destroy.url({ role: 'role-1' }));
  });
});

describe('roles/Index — a seeded role shows a name, not its key', () => {
  it('reads the three seeded roles in German', () => {
    const column = gridColumn(mountIndex([role()]), 'name');
    const read = (name: string, isSystem: boolean): string =>
      String(
        (column.valueGetter as ValueGetterFunc<RoleRow>)({
          data: role({ name, is_system: isSystem }),
        } as never),
      );

    expect(read('owner', true)).toBe('Inhaber');
    expect(read('admin', true)).toBe('Administrator');
    expect(read('member', true)).toBe('Mitglied');
  });

  it('leaves a role the tenant named alone', () => {
    const column = gridColumn(mountIndex([role()]), 'name');

    expect(
      (column.valueGetter as ValueGetterFunc<RoleRow>)({
        data: role({ name: 'companies-editor', is_system: false }),
      } as never),
    ).toBe('companies-editor');
  });
});

describe('roles/Index — bulk delete', () => {
  it('keeps roles in use or refused by the policy out of the selection and sends the chosen ids to the bulk endpoint', async () => {
    const wrapper = mountIndex([role()]);
    expect(
      selectionCheckbox(
        wrapper,
        role({
          can_delete: false,
          delete_reason: 'Diese Rolle ist noch einem Team zugewiesen und kann nicht gelöscht werden.',
        }),
      ).exists(),
    ).toBe(false);
    expect(selectionCheckbox(wrapper, role({ can_delete: false })).exists()).toBe(false);

    await selectRow(wrapper, role());
    wrapper.findComponent(SelectionBulkBar).vm.$emit('delete');

    expect(postMock).toHaveBeenCalledWith(RolesController.bulkDestroy.url(), { ids: ['role-1'] }, expect.anything());
  });
});
