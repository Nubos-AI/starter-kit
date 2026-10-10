import { mount } from '@vue/test-utils';
import type { ColDef, ValueGetterFunc } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RolesController from '@/actions/App/Http/Controllers/Authorization/RolesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import Index from '@/pages/roles/Index.vue';
import type { RoleRow } from '@/types/roles';
import type { RowAction } from '@/types/rowAction';

const { visitMock, deleteMock, pageState } = vi.hoisted(() => ({
    visitMock: vi.fn(),
    deleteMock: vi.fn(),
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
    router: { visit: visitMock, delete: deleteMock },
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

function columns(wrapper: Wrapper): ColDef<RoleRow>[] {
    const grid = wrapper.findComponent(DataGrid) as unknown as {
        props(key: 'columnDefs'): ColDef<RoleRow>[];
    };

    return grid.props('columnDefs');
}

function rowActions(wrapper: Wrapper): RowAction<RoleRow>[] {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    return actionsCol?.cellRendererParams?.actions as RowAction<RoleRow>[];
}

function action(wrapper: Wrapper, label: string): RowAction<RoleRow> {
    const found = rowActions(wrapper).find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

beforeEach(() => {
    visitMock.mockReset();
    deleteMock.mockReset();
});

describe('roles/Index — grid consistency', () => {
    it('renders the standard record-grid look (no autoHeight)', () => {
        const wrapper = mountIndex([role()]);
        const grid = wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'domLayout'): string;
        };

        expect(grid.props('domLayout')).toBe('normal');
    });

    it('links the name to the edit page and shows a user-count column', () => {
        const wrapper = mountIndex([role()]);
        const cols = columns(wrapper);

        const nameColumn = cols.find((column) => column.colId === 'name');
        const href = nameColumn?.cellRendererParams?.href as (
            row: RoleRow,
        ) => string | undefined;
        expect(href(role())).toBe(RolesController.edit.url({ role: 'role-1' }));

        expect(cols.some((column) => column.field === 'user_count')).toBe(true);
    });

    it('renders the name as plain text without the update right', () => {
        const wrapper = mountIndex([role()]);
        const nameColumn = columns(wrapper).find(
            (column) => column.colId === 'name',
        );
        const href = nameColumn?.cellRendererParams?.href as (
            row: RoleRow,
        ) => string | undefined;

        expect(href({ ...role(), can_update: false })).toBeUndefined();
    });
});

describe('roles/Index — actions follow the visibility rules', () => {
    it('shows edit and delete enabled for a role the user may manage', () => {
        const wrapper = mountIndex([role()]);
        const row = role();

        const edit = action(wrapper, 'Bearbeiten');
        const remove = action(wrapper, 'Löschen');

        expect(edit.isVisible?.(row)).toBe(true);
        expect(edit.isDisabled?.(row) ?? false).toBe(false);
        expect(remove.isVisible?.(row)).toBe(true);
        expect(remove.isDisabled?.(row) ?? false).toBe(false);
    });

    it('hides edit and delete when the user is not allowed at all', () => {
        const wrapper = mountIndex([role()]);
        const row = role({ can_update: false, can_delete: false });

        expect(action(wrapper, 'Bearbeiten').isVisible?.(row)).toBe(false);
        expect(action(wrapper, 'Löschen').isVisible?.(row)).toBe(false);
    });

    it('greys out delete (still visible) when a rule blocks it — assigned users', () => {
        const wrapper = mountIndex([role()]);
        const row = role({ user_count: 2 });
        const remove = action(wrapper, 'Löschen');

        expect(remove.isVisible?.(row)).toBe(true);
        expect(remove.isDisabled?.(row)).toBe(true);
        expect(remove.disabledReason?.(row)).toContain('2');
    });
});

describe('roles/Index — delete confirmation', () => {
    it('opens the confirm dialog instead of deleting immediately', async () => {
        const wrapper = mountIndex([role()]);

        action(wrapper, 'Löschen').onClick?.(role());
        await wrapper.vm.$nextTick();

        const dialog = wrapper.findComponent(ConfirmDialog);
        expect(dialog.props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes only after the dialog is confirmed', async () => {
        const wrapper = mountIndex([role()]);

        action(wrapper, 'Löschen').onClick?.(role());
        await wrapper.vm.$nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(deleteMock).toHaveBeenCalledTimes(1);
        expect(deleteMock.mock.calls[0][0]).toBe(
            RolesController.destroy.url({ role: 'role-1' }),
        );
    });
});

describe('roles/Index — a seeded role shows a name, not its key', () => {
    it('reads the three seeded roles in German', () => {
        const column = columns(mountIndex([role()])).find(
            (entry) => entry.colId === 'name',
        );
        const read = (name: string, isSystem: boolean): string =>
            String(
                (column?.valueGetter as ValueGetterFunc<RoleRow>)({
                    data: role({ name, is_system: isSystem }),
                } as never),
            );

        expect(read('owner', true)).toBe('Inhaber');
        expect(read('admin', true)).toBe('Administrator');
        expect(read('member', true)).toBe('Mitglied');
    });

    it('leaves a role the tenant named alone', () => {
        const column = columns(mountIndex([role()])).find(
            (entry) => entry.colId === 'name',
        );

        expect(
            (column?.valueGetter as ValueGetterFunc<RoleRow>)({
                data: role({ name: 'companies-editor', is_system: false }),
            } as never),
        ).toBe('companies-editor');
    });
});
