import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserInvitationsController from '@/actions/App/Http/Controllers/Users/UserInvitationsController';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import { USER_STATUS } from '@/shared/lib/statusMaps';
import { columnParams, gridColumn, rowAction, selectRow, selectionCheckbox } from '@/shared/testing/dataGrid';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import type { AssignableRole, UserRow } from '../../../data/types/users';
import Index from './Index.vue';

const { visitMock, deleteMock, putMock, postMock, toastErrorMock, pageState } = vi.hoisted(() => ({
  visitMock: vi.fn(),
  deleteMock: vi.fn(),
  putMock: vi.fn(),
  postMock: vi.fn(),
  toastErrorMock: vi.fn(),
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
  router: {
    visit: visitMock,
    delete: deleteMock,
    put: putMock,
    post: postMock,
  },
  usePage: () => pageState,
}));

vi.mock('vue-sonner', () => ({
  toast: { error: toastErrorMock },
}));

type Wrapper = ReturnType<typeof mount>;

const roles: AssignableRole[] = [
  {
    id: 'role-1',
    name: 'Vertrieb',
    scope: 'tenant',
    authority: null,
    is_system: false,
    grants_subteam_visibility: false,
    can_assign: true,
  },
];

function user(overrides: Partial<UserRow> = {}): UserRow {
  return {
    id: 'user-1',
    name: 'Jo Brandt',
    email: 'jo@example.test',
    status: 'accepted',
    role_ids: ['role-1'],
    is_escalated: false,
    can_update: true,
    can_delete: true,
    delete_reason: null,
    can_block: true,
    block_reason: null,
    can_resend_invitation: false,
    ...overrides,
  };
}

function mountIndex(users: UserRow[], canInvite = true): Wrapper {
  pageState.props.auth.can = canInvite ? { 'members.invite': true } : {};

  return mount(Index, { props: { users, roles } });
}

beforeEach(() => {
  visitMock.mockReset();
  deleteMock.mockReset();
  putMock.mockReset();
  postMock.mockReset();
  toastErrorMock.mockReset();
});

describe('users/Index — grid consistency', () => {
  it('links the name to the edit page only when editing is allowed', () => {
    const wrapper = mountIndex([user()]);
    const href = columnParams(wrapper, 'name').href as (row: UserRow) => string | undefined;

    expect(href(user())).toBe(UsersController.edit.url({ user: 'user-1' }));
    expect(href(user({ can_update: false }))).toBeUndefined();
  });

  it('lists the assigned role names and falls back to an em dash', () => {
    const wrapper = mountIndex([user()]);
    const rolesColumn = gridColumn(wrapper, 'roles');
    const valueGetter = rolesColumn.valueGetter as (params: { data: UserRow | undefined }) => string;

    expect(valueGetter({ data: user() })).toBe('Vertrieb');
    expect(valueGetter({ data: user({ role_ids: [] }) })).toBe('—');
  });
});

describe('users/Index — actions follow the visibility rules', () => {
  it('shows edit and delete enabled for a manageable user', () => {
    const wrapper = mountIndex([user()]);
    const row = user();

    expect(rowAction<UserRow>(wrapper, 'Bearbeiten').isDisabled?.(row)).toBe(false);
    expect(rowAction<UserRow>(wrapper, 'Löschen').isDisabled?.(row)).toBe(false);
  });

  it('greys out both actions with a reason when nothing is allowed', () => {
    const wrapper = mountIndex([user()]);
    const row = user({ can_update: false, can_delete: false });
    const edit = rowAction<UserRow>(wrapper, 'Bearbeiten');
    const remove = rowAction<UserRow>(wrapper, 'Löschen');

    expect(edit.isDisabled?.(row)).toBe(true);
    expect(edit.disabledReason?.(row)).toBe('Keine Berechtigung');
    expect(remove.isDisabled?.(row)).toBe(true);
    expect(remove.disabledReason?.(row)).toBe('Keine Berechtigung');
  });

  it('greys out delete with a reason for the last super admin', () => {
    const wrapper = mountIndex([user()]);
    const row = user({
      can_delete: false,
      delete_reason: 'Last super admin — promote another user to super admin first',
      is_escalated: true,
    });
    const remove = rowAction<UserRow>(wrapper, 'Löschen');

    expect(remove.isDisabled?.(row)).toBe(true);
    expect(remove.disabledReason?.(row)).toContain('super admin');
  });
});

describe('users/Index — delete confirmation', () => {
  it('opens the confirm dialog instead of deleting immediately', async () => {
    const wrapper = mountIndex([user()]);

    rowAction<UserRow>(wrapper, 'Löschen').onClick?.(user());
    await wrapper.vm.$nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('deletes only after the dialog is confirmed', async () => {
    const wrapper = mountIndex([user()]);

    rowAction<UserRow>(wrapper, 'Löschen').onClick?.(user());
    await wrapper.vm.$nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(deleteMock).toHaveBeenCalledTimes(1);
    expect(deleteMock.mock.calls[0][0]).toBe(UsersController.destroy.url({ user: 'user-1' }));
  });

  it('reports a rejected deletion as a toast', async () => {
    const wrapper = mountIndex([user()]);

    rowAction<UserRow>(wrapper, 'Löschen').onClick?.(user());
    await wrapper.vm.$nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    const options = deleteMock.mock.calls[0][1] as {
      onError: (errors: Record<string, string>) => void;
    };
    options.onError({ user: 'Der letzte Super-Admin bleibt bestehen.' });

    expect(toastErrorMock).toHaveBeenCalledWith('Der letzte Super-Admin bleibt bestehen.');
  });
});

describe('users/Index — status and invitations', () => {
  it('renders the status column through the shared status map', () => {
    const wrapper = mountIndex([user()]);
    expect(columnParams(wrapper, 'status').statusMap).toBe(USER_STATUS);
  });

  it('falls back to the email as the name of an invited user', () => {
    const wrapper = mountIndex([user()]);
    const valueGetter = gridColumn(wrapper, 'name').valueGetter as (params: { data: UserRow | undefined }) => string;

    expect(valueGetter({ data: user({ name: '', status: 'invited' }) })).toBe('jo@example.test');
  });

  it('offers the invite button only to an inviter', () => {
    expect(mountIndex([user()]).find('[data-create-button]').exists()).toBe(true);
    expect(mountIndex([user()], false).find('[data-create-button]').exists()).toBe(false);
  });

  it('resends the invitation of a pending user only', async () => {
    const wrapper = mountIndex([user()]);
    const resend = rowAction<UserRow>(wrapper, 'Einladung erneut senden');

    expect(resend.isVisible?.(user())).toBe(false);
    expect(resend.isVisible?.(user({ status: 'invited', can_resend_invitation: true }))).toBe(true);

    resend.onClick?.(user({ id: 'user-7', status: 'invited' }));

    expect(postMock).toHaveBeenCalledTimes(1);
    expect(postMock).toHaveBeenCalledWith(
      UserInvitationsController.resend.url({ user: 'user-7' }),
      {},
      { preserveScroll: true },
    );
  });

  it('greys out blocking with a reason instead of hiding it', () => {
    const wrapper = mountIndex([user()]);
    const row = user({
      can_block: false,
      block_reason: 'Your own account — you cannot block yourself',
    });
    const block = rowAction<UserRow>(wrapper, 'Sperren');

    expect(block.isVisible?.(row)).toBe(true);
    expect(block.isDisabled?.(row)).toBe(true);
    expect(block.disabledReason?.(row)).toContain('Your own account');
  });

  it('blocks only after the dialog is confirmed', async () => {
    const wrapper = mountIndex([user()]);

    rowAction<UserRow>(wrapper, 'Sperren').onClick?.(user());
    await wrapper.vm.$nextTick();

    expect(putMock).not.toHaveBeenCalled();

    wrapper.findAllComponents(ConfirmDialog)[1].vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(putMock).toHaveBeenCalledTimes(1);
    expect(putMock.mock.calls[0][1]).toEqual({ status: 'blocked' });
  });

  it('unblocks a blocked user', async () => {
    const blocked = user({ status: 'blocked' });
    const wrapper = mountIndex([blocked]);

    expect(rowAction<UserRow>(wrapper, 'Entsperren').isVisible?.(blocked)).toBe(true);

    rowAction<UserRow>(wrapper, 'Entsperren').onClick?.(blocked);
    await wrapper.vm.$nextTick();

    wrapper.findAllComponents(ConfirmDialog)[1].vm.$emit('confirm');
    await wrapper.vm.$nextTick();

    expect(putMock.mock.calls[0][1]).toEqual({ status: 'accepted' });
  });
});

it('hides tenant-specific authority and roles in customer management', () => {
  const wrapper = mount(Index, {
    props: {
      users: [user()],
      roles: [],
      management: { inviteUrl: '/admin/users/invite' },
    },
  });
  expect(gridColumn(wrapper, 'roles').hide).toBe(true);
  expect(gridColumn(wrapper, 'authority').hide).toBe(true);
  expect(wrapper.text()).toContain('Benutzer und Zugänge der Kunden verwalten.');
});

describe('users/Index — bulk delete', () => {
  it('offers only deletable users for selection and sends the chosen ids to the bulk endpoint', async () => {
    const wrapper = mountIndex([user()]);
    expect(selectionCheckbox(wrapper, user({ can_delete: false })).exists()).toBe(false);

    await selectRow(wrapper, user());
    wrapper.findComponent(SelectionBulkBar).vm.$emit('delete');

    expect(postMock).toHaveBeenCalledWith(UsersController.bulkDestroy.url(), { ids: ['user-1'] }, expect.anything());
  });
});
