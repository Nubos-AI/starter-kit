import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UsersController from '@/actions/App/Http/Controllers/Users/UsersController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { USER_STATUS } from '@/lib/statusMaps';
import Index from '@/pages/users/Index.vue';
import type { RowAction } from '@/types/rowAction';
import type { AssignableRole, UserRow } from '@/types/users';

const { visitMock, deleteMock, putMock, postMock, toastErrorMock, pageState } =
    vi.hoisted(() => ({
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

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
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

function columns(wrapper: Wrapper): ColDef<UserRow>[] {
    const grid = wrapper.findComponent(DataGrid) as unknown as {
        props(key: 'columnDefs'): ColDef<UserRow>[];
    };

    return grid.props('columnDefs');
}

function action(wrapper: Wrapper, label: string): RowAction<UserRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );
    const actions = actionsCol?.cellRendererParams?.actions as
        | RowAction<UserRow>[]
        | undefined;
    const found = actions?.find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

beforeEach(() => {
    visitMock.mockReset();
    deleteMock.mockReset();
    putMock.mockReset();
    postMock.mockReset();
    toastErrorMock.mockReset();
});

describe('users/Index — grid consistency', () => {
    it('renders the standard record-grid look (no autoHeight)', () => {
        const wrapper = mountIndex([user()]);
        const grid = wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'domLayout'): string;
        };

        expect(grid.props('domLayout')).toBe('normal');
    });

    it('links the name to the edit page only when editing is allowed', () => {
        const wrapper = mountIndex([user()]);
        const href = columns(wrapper).find((column) => column.colId === 'name')
            ?.cellRendererParams?.href as (row: UserRow) => string | undefined;

        expect(href(user())).toBe(UsersController.edit.url({ user: 'user-1' }));
        expect(href(user({ can_update: false }))).toBeUndefined();
    });

    it('lists the assigned role names and falls back to an em dash', () => {
        const wrapper = mountIndex([user()]);
        const rolesColumn = columns(wrapper).find(
            (column) => column.colId === 'roles',
        );
        const valueGetter = rolesColumn?.valueGetter as (params: {
            data: UserRow | undefined;
        }) => string;

        expect(valueGetter({ data: user() })).toBe('Vertrieb');
        expect(valueGetter({ data: user({ role_ids: [] }) })).toBe('—');
    });
});

describe('users/Index — actions follow the visibility rules', () => {
    it('shows edit and delete enabled for a manageable user', () => {
        const wrapper = mountIndex([user()]);
        const row = user();

        expect(action(wrapper, 'Bearbeiten').isVisible?.(row)).toBe(true);
        expect(action(wrapper, 'Löschen').isVisible?.(row)).toBe(true);
        expect(action(wrapper, 'Löschen').isDisabled?.(row)).toBe(false);
    });

    it('hides both actions when nothing is allowed', () => {
        const wrapper = mountIndex([user()]);
        const row = user({ can_update: false, can_delete: false });

        expect(action(wrapper, 'Bearbeiten').isVisible?.(row)).toBe(false);
        expect(action(wrapper, 'Löschen').isVisible?.(row)).toBe(false);
    });

    it('greys out delete with a reason for the last super admin', () => {
        const wrapper = mountIndex([user()]);
        const row = user({
            can_delete: false,
            delete_reason:
                'Last super admin — promote another user to super admin first',
            is_escalated: true,
        });
        const remove = action(wrapper, 'Löschen');

        expect(remove.isVisible?.(row)).toBe(true);
        expect(remove.isDisabled?.(row)).toBe(true);
        expect(remove.disabledReason?.(row)).toContain('super admin');
    });
});

describe('users/Index — delete confirmation', () => {
    it('opens the confirm dialog instead of deleting immediately', async () => {
        const wrapper = mountIndex([user()]);

        action(wrapper, 'Löschen').onClick?.(user());
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes only after the dialog is confirmed', async () => {
        const wrapper = mountIndex([user()]);

        action(wrapper, 'Löschen').onClick?.(user());
        await wrapper.vm.$nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(deleteMock).toHaveBeenCalledTimes(1);
        expect(deleteMock.mock.calls[0][0]).toBe(
            UsersController.destroy.url({ user: 'user-1' }),
        );
    });

    it('reports a rejected deletion as a toast', async () => {
        const wrapper = mountIndex([user()]);

        action(wrapper, 'Löschen').onClick?.(user());
        await wrapper.vm.$nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        const options = deleteMock.mock.calls[0][1] as {
            onError: (errors: Record<string, string>) => void;
        };
        options.onError({ user: 'Der letzte Super-Admin bleibt bestehen.' });

        expect(toastErrorMock).toHaveBeenCalledWith(
            'Der letzte Super-Admin bleibt bestehen.',
        );
    });
});

describe('users/Index — status and invitations', () => {
    it('renders the status column through the shared status map', () => {
        const wrapper = mountIndex([user()]);
        const statusColumn = columns(wrapper).find(
            (column) => column.colId === 'status',
        );

        expect(statusColumn?.cellRendererParams?.statusMap).toBe(USER_STATUS);
    });

    it('falls back to the email as the name of an invited user', () => {
        const wrapper = mountIndex([user()]);
        const valueGetter = columns(wrapper).find(
            (column) => column.colId === 'name',
        )?.valueGetter as (params: { data: UserRow | undefined }) => string;

        expect(
            valueGetter({ data: user({ name: '', status: 'invited' }) }),
        ).toBe('jo@example.test');
    });

    it('offers the invite button only to an inviter', () => {
        expect(mountIndex([user()]).find('[data-create-button]').exists()).toBe(
            true,
        );
        expect(
            mountIndex([user()], false).find('[data-create-button]').exists(),
        ).toBe(false);
    });

    it('resends the invitation of a pending user only', async () => {
        const wrapper = mountIndex([user()]);
        const resend = action(wrapper, 'Einladung erneut senden');

        expect(resend.isVisible?.(user())).toBe(false);
        expect(
            resend.isVisible?.(
                user({ status: 'invited', can_resend_invitation: true }),
            ),
        ).toBe(true);

        resend.onClick?.(user({ status: 'invited' }));

        expect(postMock).toHaveBeenCalledTimes(1);
    });

    it('greys out blocking with a reason instead of hiding it', () => {
        const wrapper = mountIndex([user()]);
        const row = user({
            can_block: false,
            block_reason: 'Your own account — you cannot block yourself',
        });
        const block = action(wrapper, 'Sperren');

        expect(block.isVisible?.(row)).toBe(true);
        expect(block.isDisabled?.(row)).toBe(true);
        expect(block.disabledReason?.(row)).toContain('Your own account');
    });

    it('blocks only after the dialog is confirmed', async () => {
        const wrapper = mountIndex([user()]);

        action(wrapper, 'Sperren').onClick?.(user());
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

        expect(action(wrapper, 'Entsperren').isVisible?.(blocked)).toBe(true);

        action(wrapper, 'Entsperren').onClick?.(blocked);
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
    expect(
        columns(wrapper).find((column) => column.colId === 'roles')?.hide,
    ).toBe(true);
    expect(
        columns(wrapper).find((column) => column.colId === 'authority')?.hide,
    ).toBe(true);
    expect(wrapper.text()).toContain(
        'Benutzer und Zugänge der Kunden verwalten.',
    );
});
