import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import SkillsController from '@/actions/App/Http/Controllers/Skills/SkillsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/skills/Index.vue';
import type { RowAction } from '@/types/rowAction';

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

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
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
    pageState.props.auth.can = Object.fromEntries(
        names.map((name) => [name, true]),
    );
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
    mounted.push(wrapper);

    return wrapper;
}

function mountIndex(
    skills: SkillRow[] = [row()],
    granted: string[] = [
        'skills.view',
        'skills.create',
        'skills.update',
        'skills.delete',
    ],
): Wrapper {
    grant(...granted);

    return track(mount(Index, { props: { skills }, global: { stubs } }));
}

function columns(wrapper: Wrapper): ColDef<SkillRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<SkillRow>[];
        }
    ).props('columnDefs');
}

function action(wrapper: Wrapper, label: string): RowAction<SkillRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    const actions = actionsCol?.cellRendererParams
        ?.actions as RowAction<SkillRow>[];

    const found = actions.find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

function selectionCell(wrapper: Wrapper, data: SkillRow): Wrapper {
    return track(
        mount(columns(wrapper)[0].cellRenderer as Component, {
            props: { params: { data } },
        }),
    );
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

        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );
        expect(
            (
                wrapper.findComponent(ViewStates) as unknown as {
                    props(key: 'state'): string;
                }
            ).props('state'),
        ).toBe('empty');
        expect(wrapper.findComponent(CreateButton).exists()).toBe(true);
    });

    it('feeds every skill to the grid', () => {
        const wrapper = mountIndex([
            row(),
            row({ id: '01SKILL00000000000000002', name: 'Recht' }),
        ]);

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): SkillRow[];
                }
            ).props('rowData'),
        ).toHaveLength(2);
    });

    it('shows the number of assigned users as its own column', () => {
        const wrapper = mountIndex();

        const column = columns(wrapper).find(
            (entry) => entry.field === 'users_count',
        );

        expect(column).toBeDefined();
        expect(column?.headerName).toBe('Zugeordnete Benutzer');
    });

    it('disables the actions of a locked row and names the server reason', () => {
        const locked = row({
            can_update: false,
            can_delete: false,
            delete_reason: 'Diese Fähigkeit wird noch verwendet.',
        });
        const wrapper = mountIndex([locked]);

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(locked)).toBe(true);
        expect(action(wrapper, 'Löschen').isDisabled?.(locked)).toBe(true);
        expect(action(wrapper, 'Löschen').disabledReason?.(locked)).toBe(
            'Diese Fähigkeit wird noch verwendet.',
        );
    });

    it('opens the row on click only when the actor may edit it', () => {
        const wrapper = mountIndex([row()]);
        const isRowActivatable = (
            wrapper.findComponent(DataGrid) as unknown as {
                props(key: 'isRowActivatable'): (row: SkillRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(row({ can_update: false }))).toBe(false);
    });

    it('leads the row activation to the edit page', () => {
        const wrapper = mountIndex();

        (
            wrapper.findComponent(DataGrid) as unknown as {
                vm: { $emit: (event: string, payload: SkillRow) => void };
            }
        ).vm.$emit('row-activate', row());

        expect(visitMock).toHaveBeenCalledWith(
            SkillsController.edit.url({ skill: row().id }),
        );
    });

    it('offers the create button only with the create right', () => {
        expect(mountIndex().findComponent(CreateButton).exists()).toBe(true);
        expect(
            mountIndex([row()], ['skills.view'])
                .findComponent(CreateButton)
                .exists(),
        ).toBe(false);
    });

    it('asks before deleting a single skill', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
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

        expect(
            selectionCell(wrapper, row()).findComponent(Checkbox).exists(),
        ).toBe(true);
        expect(
            selectionCell(
                wrapper,
                row({
                    id: '01SKILL00000000000000002',
                    can_delete: false,
                    delete_reason: 'Keine Berechtigung',
                }),
            )
                .findComponent(Checkbox)
                .exists(),
        ).toBe(false);
    });

    it('sends the selected ids to the bulk delete endpoint', async () => {
        const wrapper = mountIndex();

        const cell = selectionCell(wrapper, row());

        cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            SkillsController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });
});
