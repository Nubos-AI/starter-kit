import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import TeamReparentDialog from '@/components/teams/TeamReparentDialog.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/teams/Index.vue';
import type { RowAction } from '@/types/rowAction';
import type { TeamTreeNode } from '@/types/teams';

const { visitMock, deleteMock, putMock, postMock, pageState } = vi.hoisted(
    () => ({
        visitMock: vi.fn(),
        deleteMock: vi.fn(),
        putMock: vi.fn(),
        postMock: vi.fn(),
        pageState: {
            props: {
                errors: {} as Record<string, string> | undefined,
                auth: {
                    user: null,
                    can: {} as Record<string, boolean>,
                    authority: null,
                },
            },
        },
    }),
);

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

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

const headquartersId = '01TEAMHEADQUARTERS00000001';
const salesId = '01TEAMSALES00000000000002';
const northId = '01TEAMSALESNORTH0000000003';

function node(overrides: Partial<TeamTreeNode> = {}): TeamTreeNode {
    return {
        id: headquartersId,
        name: 'Headquarters',
        slug: 'headquarters',
        parentTeamId: null,
        depth: 0,
        descendantTeamIds: [],
        can_delete: true,
        delete_reason: null,
        ...overrides,
    };
}

const headquarters = node({
    can_delete: false,
    delete_reason: 'This team still has child teams and cannot be deleted.',
    descendantTeamIds: [salesId, northId],
});

const sales = node({
    id: salesId,
    name: 'Sales',
    slug: 'sales',
    parentTeamId: headquartersId,
    depth: 1,
    descendantTeamIds: [northId],
});

const north = node({
    id: northId,
    name: 'Sales North',
    slug: 'sales-north',
    parentTeamId: salesId,
    depth: 2,
});

const everyTeamPermission = [
    'teams.create',
    'teams.update',
    'teams.delete',
    'teams.reparent',
];

function grant(...names: string[]): void {
    pageState.props.auth.can = Object.fromEntries(
        names.map((name) => [name, true]),
    );
}

function mountIndex(
    nodes: TeamTreeNode[] = [headquarters, sales, north],
    granted: string[] = everyTeamPermission,
): Wrapper {
    grant(...granted);

    return mount(Index, {
        props: { nodes },
        global: { stubs },
    });
}

function grid(wrapper: Wrapper) {
    return wrapper.findComponent(DataGrid) as unknown as {
        exists(): boolean;
        props(key: 'columnDefs'): ColDef<TeamTreeNode>[];
    };
}

function columns(wrapper: Wrapper): ColDef<TeamTreeNode>[] {
    return grid(wrapper).props('columnDefs');
}

function rowActions(wrapper: Wrapper): RowAction<TeamTreeNode>[] {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    return actionsCol?.cellRendererParams?.actions as RowAction<TeamTreeNode>[];
}

function action(wrapper: Wrapper, label: string): RowAction<TeamTreeNode> {
    const found = rowActions(wrapper).find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

function dialogOf(wrapper: Wrapper) {
    return wrapper.findComponent(TeamReparentDialog);
}

async function selectRow(wrapper: Wrapper, row: TeamTreeNode): Promise<void> {
    const cell = mount(columns(wrapper)[0].cellRenderer as Component, {
        props: { params: { data: row } },
    });

    cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);

    await wrapper.vm.$nextTick();
}

async function openReparentFor(
    wrapper: Wrapper,
    subject: TeamTreeNode,
): Promise<void> {
    action(wrapper, 'Verschieben').onClick?.(subject);
    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    visitMock.mockReset();
    deleteMock.mockReset();
    putMock.mockReset();
    postMock.mockReset();
    pageState.props.errors = {};
});

describe('teams/Index — the tree renders as the standard grid', () => {
    it('feeds every node to the grid and keeps the tree order unsortable', () => {
        const wrapper = mountIndex();
        const gridComponent = wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'rowData' | 'defaultColDef'): unknown;
        };

        expect(gridComponent.props('rowData')).toEqual([
            headquarters,
            sales,
            north,
        ]);
        expect(gridComponent.props('defaultColDef')).toMatchObject({
            sortable: false,
        });
    });

    it('links the team name to its edit page like every other config list', () => {
        const wrapper = mountIndex();
        const href = columns(wrapper).find((column) => column.colId === 'name')
            ?.cellRendererParams?.href as (
            row: TeamTreeNode,
        ) => string | undefined;

        expect(href(sales)).toBe(TeamsController.edit.url({ team: salesId }));
    });

    it('renders the name as plain text without the update right', () => {
        const denied = mountIndex(
            [headquarters, sales, north],
            everyTeamPermission.filter((name) => name !== 'teams.update'),
        );
        const href = columns(denied).find((column) => column.colId === 'name')
            ?.cellRendererParams?.href as (
            row: TeamTreeNode,
        ) => string | undefined;

        expect(href(sales)).toBeUndefined();
    });

    it('hides the grid and shows the empty state when there is no team', () => {
        const wrapper = mountIndex([]);

        expect(grid(wrapper).exists()).toBe(false);
        expect(wrapper.text()).toContain('Noch keine Teams');
    });
});

describe('teams/Index — permission gating', () => {
    it('offers the create button only with the create permission', () => {
        expect(mountIndex().find('[data-create-button]').exists()).toBe(true);

        const denied = mountIndex(
            [headquarters, sales, north],
            everyTeamPermission.filter((name) => name !== 'teams.create'),
        );

        expect(denied.find('[data-create-button]').exists()).toBe(false);
    });

    it('hides each row action the permissions forbid', () => {
        const wrapper = mountIndex(
            [headquarters, sales, north],
            ['teams.update'],
        );

        expect(action(wrapper, 'Bearbeiten').isVisible?.(sales)).toBe(true);
        expect(action(wrapper, 'Verschieben').isVisible?.(sales)).toBe(false);
        expect(action(wrapper, 'Löschen').isVisible?.(sales)).toBe(false);
    });
});

describe('teams/Index — row actions', () => {
    it('visits the edit page of the clicked team', () => {
        const wrapper = mountIndex();

        action(wrapper, 'Bearbeiten').onClick?.(sales);

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock.mock.calls[0][0]).toBe(
            TeamsController.edit.url({ team: salesId }),
        );
    });

    it('marks reparent as the informational action so its icon reads blue', () => {
        expect(action(mountIndex(), 'Verschieben').variant).toBe('info');
    });

    it('greys out delete with the server reason instead of hiding it', () => {
        const wrapper = mountIndex();
        const remove = action(wrapper, 'Löschen');

        expect(remove.isVisible?.(headquarters)).toBe(true);
        expect(remove.isDisabled?.(headquarters)).toBe(true);
        expect(remove.disabledReason?.(headquarters)).toBe(
            headquarters.delete_reason,
        );
        expect(remove.isDisabled?.(north)).toBe(false);
    });
});

describe('teams/Index — multi select and bulk delete', () => {
    it('opens the grid with the shared selection column', () => {
        expect(columns(mountIndex())[0].colId).toBe('__list_select__');
    });

    it('offers no checkbox for a team that cannot be deleted', () => {
        const wrapper = mountIndex();
        const column = columns(wrapper)[0];

        const blocked = mount(column.cellRenderer as Component, {
            props: { params: { data: headquarters } },
        });
        const deletable = mount(column.cellRenderer as Component, {
            props: { params: { data: north } },
        });

        expect(blocked.findComponent(Checkbox).exists()).toBe(false);
        expect(deletable.findComponent(Checkbox).exists()).toBe(true);
    });

    it('hides the bulk bar until something is selected', async () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );

        await selectRow(wrapper, north);

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);
    });

    it('posts the selected ids to the bulk delete route and clears on success', async () => {
        const wrapper = mountIndex();

        await selectRow(wrapper, north);
        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock.mock.calls[0][0]).toBe(
            TeamsController.bulkDestroy.url(),
        );
        expect(postMock.mock.calls[0][1]).toEqual({ ids: [northId] });

        postMock.mock.calls[0][2].onSuccess();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );
    });
});

describe('teams/Index — delete confirmation', () => {
    it('opens the confirm dialog instead of deleting immediately', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(north);
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('deletes only after the dialog is confirmed', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(north);
        await wrapper.vm.$nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(deleteMock).toHaveBeenCalledTimes(1);
        expect(deleteMock.mock.calls[0][0]).toBe(
            TeamsController.destroy.url({ team: northId }),
        );
        expect(deleteMock.mock.calls[0][1]).toMatchObject({
            preserveScroll: true,
        });
    });
});

describe('teams/Index — reparent dialog', () => {
    it('opens the dialog with the clicked team and sends nothing yet', async () => {
        const wrapper = mountIndex();

        await openReparentFor(wrapper, sales);

        expect(dialogOf(wrapper).props('open')).toBe(true);
        expect(dialogOf(wrapper).props('subject')).toEqual(sales);
        expect(putMock).not.toHaveBeenCalled();
    });

    it('puts the chosen parent to the reparent route and closes on success', async () => {
        const wrapper = mountIndex();

        await openReparentFor(wrapper, sales);
        await wrapper
            .find(`[data-candidate-id="${headquartersId}"]`)
            .trigger('click');
        await wrapper.find('[data-reparent-confirm]').trigger('click');

        expect(putMock).toHaveBeenCalledTimes(1);
        expect(putMock.mock.calls[0][0]).toBe(
            TeamsController.reparent.url({ team: salesId }),
        );
        expect(putMock.mock.calls[0][1]).toEqual({
            parent_team_id: headquartersId,
        });
        expect(dialogOf(wrapper).props('processing')).toBe(true);

        putMock.mock.calls[0][2].onSuccess();
        putMock.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();

        expect(dialogOf(wrapper).props('open')).toBe(false);
        expect(dialogOf(wrapper).props('subject')).toBeNull();
        expect(dialogOf(wrapper).props('processing')).toBe(false);
    });

    it('sends a null parent when the team becomes a root team', async () => {
        const wrapper = mountIndex();

        await openReparentFor(wrapper, north);
        await wrapper.find('[data-root-option]').trigger('click');
        await wrapper.find('[data-reparent-confirm]').trigger('click');

        expect(putMock.mock.calls[0][0]).toBe(
            TeamsController.reparent.url({ team: northId }),
        );
        expect(putMock.mock.calls[0][1]).toEqual({ parent_team_id: null });
    });
});

describe('teams/Index — server errors', () => {
    it('hands the parent_team_id error to the dialog and renders the delete error', () => {
        pageState.props.errors = {
            parent_team_id: 'The selected parent team is not available.',
            team: 'This team still has child teams and cannot be deleted.',
        };

        const wrapper = mountIndex();

        expect(dialogOf(wrapper).props('errorMessage')).toBe(
            'The selected parent team is not available.',
        );
        expect(wrapper.find('[data-team-error]').text()).toContain(
            'This team still has child teams and cannot be deleted.',
        );
    });

    it('survives a page without an error bag', () => {
        pageState.props.errors = undefined;

        const wrapper = mountIndex();

        expect(dialogOf(wrapper).props('errorMessage')).toBeNull();
        expect(grid(wrapper).exists()).toBe(true);
    });
});
