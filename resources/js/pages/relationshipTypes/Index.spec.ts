import { mount } from '@vue/test-utils';
import type { ColDef, ValueFormatterFunc } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import RelationshipTypesController from '@/actions/App/Http/Controllers/Engine/RelationshipTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/relationshipTypes/Index.vue';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, pageState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    postMock: vi.fn(),
    pageState: {
        url: '/nubos/engine/relationship-types',
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

interface RelationshipTypeRow {
    id: string;
    name: string;
    inverse_name: string;
    from_object_type: string;
    to_object_type: string;
    cardinality: string;
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

function row(
    overrides: Partial<RelationshipTypeRow> = {},
): RelationshipTypeRow {
    return {
        id: '01RELATIONSHIP0000000001',
        name: 'Deals',
        inverse_name: 'Company',
        from_object_type: 'Companies',
        to_object_type: 'Deals',
        cardinality: 'one_to_many',
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

function mountIndex(
    relationshipTypes: RelationshipTypeRow[] = [row()],
    granted: string[] = [
        'object-types.create',
        'object-types.update',
        'object-types.delete',
    ],
): Wrapper {
    grant(...granted);

    return mount(Index, { props: { relationshipTypes }, global: { stubs } });
}

function columns(wrapper: Wrapper): ColDef<RelationshipTypeRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<RelationshipTypeRow>[];
        }
    ).props('columnDefs');
}

function action(
    wrapper: Wrapper,
    label: string,
): RowAction<RelationshipTypeRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    const actions = actionsCol?.cellRendererParams
        ?.actions as RowAction<RelationshipTypeRow>[];

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

describe('relationshipTypes/Index', () => {
    it('feeds every relationship type to the grid', () => {
        const wrapper = mountIndex();

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): RelationshipTypeRow[];
                }
            ).props('rowData'),
        ).toHaveLength(1);
    });

    it('shows no grid without any relationship type', () => {
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

    it('asks before deleting a single relationship type', async () => {
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
                ): (row: RelationshipTypeRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(row({ can_update: false }))).toBe(false);
    });

    it('offers the create button only with the create right', () => {
        expect(mountIndex().findComponent(CreateButton).exists()).toBe(true);
        expect(
            mountIndex([row()], ['object-types.view'])
                .findComponent(CreateButton)
                .exists(),
        ).toBe(false);
    });

    it('sends the selected ids to the bulk delete endpoint', async () => {
        const wrapper = mountIndex();

        const cell = mount(columns(wrapper)[0].cellRenderer as Component, {
            props: { params: { data: row() } },
        });

        cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            RelationshipTypesController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });
});

describe('relationshipTypes/Index — the cardinality column is not a raw value', () => {
    it('reads the stored cardinality in German', () => {
        const column = columns(mountIndex()).find(
            (entry) => entry.colId === 'cardinality',
        );
        const formatter =
            column?.valueFormatter as ValueFormatterFunc<RelationshipTypeRow>;
        const read = (value: string): string =>
            String(formatter({ value } as never));

        expect(read('one_to_many')).toBe('Eins zu viele');
        expect(read('many_to_many')).toBe('Viele zu viele');
    });
});
