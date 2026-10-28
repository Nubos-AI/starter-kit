import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import ReminderTypesController from '@/actions/App/Http/Controllers/Engine/ReminderTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/reminderTypes/Index.vue';
import type { RowAction } from '@/types/rowAction';

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

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
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
    reminderTypes: ReminderTypeRow[] = [row()],
    granted: string[] = [
        'reminder-types.create',
        'reminder-types.update',
        'reminder-types.delete',
    ],
): Wrapper {
    grant(...granted);

    return track(mount(Index, { props: { reminderTypes }, global: { stubs } }));
}

function columns(wrapper: Wrapper): ColDef<ReminderTypeRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<ReminderTypeRow>[];
        }
    ).props('columnDefs');
}

function action(wrapper: Wrapper, label: string): RowAction<ReminderTypeRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    const actions = actionsCol?.cellRendererParams
        ?.actions as RowAction<ReminderTypeRow>[];

    const found = actions.find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
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
    it('feeds every reminder type to the grid', () => {
        const wrapper = mountIndex();

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): ReminderTypeRow[];
                }
            ).props('rowData'),
        ).toHaveLength(1);
    });

    it('shows no grid without any reminder type', () => {
        expect(mountIndex([]).findComponent(DataGrid).exists()).toBe(false);
    });

    it('disables the actions of a row the actor may not change', () => {
        const denied = row({ can_update: false, can_delete: false });
        const wrapper = mountIndex([denied]);

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(denied)).toBe(true);
        expect(action(wrapper, 'Löschen').disabledReason?.(denied)).toBe(
            'Keine Berechtigung',
        );
    });

    it('asks before deleting a single reminder type', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('opens the row on click only when the actor may edit it', () => {
        const wrapper = mountIndex([row()]);
        const isRowActivatable = (
            wrapper.findComponent(DataGrid) as unknown as {
                props(
                    key: 'isRowActivatable',
                ): (row: ReminderTypeRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(row({ can_update: false }))).toBe(false);
    });

    it('offers the create button only with the create right', () => {
        expect(mountIndex().findComponent(CreateButton).exists()).toBe(true);
        expect(
            mountIndex([row()], ['reminder-types.view'])
                .findComponent(CreateButton)
                .exists(),
        ).toBe(false);
    });

    it('sends the selected ids to the bulk delete endpoint', async () => {
        const wrapper = mountIndex();

        const cell = track(
            mount(columns(wrapper)[0].cellRenderer as Component, {
                props: { params: { data: row() } },
            }),
        );

        cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            ReminderTypesController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });
});
