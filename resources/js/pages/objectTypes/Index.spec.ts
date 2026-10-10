import { mount } from '@vue/test-utils';
import type { ColDef, ValueFormatterFunc } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/objectTypes/Index.vue';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    postMock: vi.fn(),
    visitMock: vi.fn(),
    pageState: {
        url: '/nubos/engine/object-types',
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

interface ObjectTypeRow {
    id: string;
    slug: string;
    name: string;
    is_system: boolean;
    storage_strategy: string;
    field_count: number;
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

function row(overrides: Partial<ObjectTypeRow> = {}): ObjectTypeRow {
    return {
        id: '01OBJECTTYPE0000000000001',
        slug: 'companies',
        name: 'Companies',
        is_system: false,
        storage_strategy: 'jsonb',
        field_count: 4,
        can_update: true,
        can_delete: true,
        ...overrides,
    };
}

const systemRow = row({
    id: '01OBJECTTYPE0000000000002',
    slug: 'attachments',
    name: 'Attachments',
    is_system: true,
    can_update: false,
    can_delete: false,
});

function grant(...names: string[]): void {
    pageState.props.auth.can = Object.fromEntries(
        names.map((name) => [name, true]),
    );
}

function mountIndex(
    objectTypes: ObjectTypeRow[] = [row(), systemRow],
    granted: string[] = [
        'object-types.create',
        'object-types.update',
        'object-types.delete',
    ],
): Wrapper {
    grant(...granted);

    return mount(Index, { props: { objectTypes }, global: { stubs } });
}

function columns(wrapper: Wrapper): ColDef<ObjectTypeRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<ObjectTypeRow>[];
        }
    ).props('columnDefs');
}

function rowActions(wrapper: Wrapper): RowAction<ObjectTypeRow>[] {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    return actionsCol?.cellRendererParams
        ?.actions as RowAction<ObjectTypeRow>[];
}

function action(wrapper: Wrapper, label: string): RowAction<ObjectTypeRow> {
    const found = rowActions(wrapper).find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

async function selectRow(
    wrapper: Wrapper,
    subject: ObjectTypeRow,
): Promise<void> {
    const cell = mount(columns(wrapper)[0].cellRenderer as Component, {
        props: { params: { data: subject } },
    });

    cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);

    await wrapper.vm.$nextTick();
}

beforeEach(() => {
    deleteMock.mockReset();
    postMock.mockReset();
    visitMock.mockReset();
});

describe('objectTypes/Index — the list is the standard grid', () => {
    it('feeds every object type to the grid', () => {
        const wrapper = mountIndex();

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): ObjectTypeRow[];
                }
            ).props('rowData'),
        ).toHaveLength(2);
    });

    it('shows no grid without any object type', () => {
        const wrapper = mountIndex([]);

        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
    });

    it('links the name onto the edit page', () => {
        const wrapper = mountIndex();
        const nameColumn = columns(wrapper).find(
            (column) => column.colId === 'name',
        );

        expect(nameColumn?.cellRendererParams?.href(row())).toBe(
            ObjectTypesController.edit.url({ objectType: 'companies' }),
        );
    });
});

describe('objectTypes/Index — the row actions follow the rights', () => {
    it('keeps edit and delete visible but disabled without the right', () => {
        const wrapper = mountIndex([row()], ['object-types.view']);

        expect(action(wrapper, 'Bearbeiten').isVisible?.(row())).not.toBe(
            false,
        );
        expect(action(wrapper, 'Löschen').isVisible?.(row())).not.toBe(false);
    });

    it('disables both actions on a system object type and names the reason', () => {
        const wrapper = mountIndex();

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(systemRow)).toBe(
            true,
        );
        expect(action(wrapper, 'Löschen').isDisabled?.(systemRow)).toBe(true);
        expect(
            action(wrapper, 'Löschen').disabledReason?.(systemRow),
        ).toContain('System');
    });

    it('names the missing permission as the reason on a regular type', () => {
        const denied = row({ can_update: false, can_delete: false });
        const wrapper = mountIndex([denied]);

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(denied)).toBe(true);
        expect(action(wrapper, 'Löschen').disabledReason?.(denied)).toBe(
            'Keine Berechtigung',
        );
    });

    it('leaves both actions enabled on an editable type', () => {
        const wrapper = mountIndex();

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(row())).toBe(false);
        expect(action(wrapper, 'Löschen').isDisabled?.(row())).toBe(false);
    });

    it('asks before deleting a single object type', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });
});

describe('objectTypes/Index — a click on the row opens the edit page', () => {
    it('activates only a row the actor may edit', () => {
        const wrapper = mountIndex();
        const isRowActivatable = (
            wrapper.findComponent(DataGrid) as unknown as {
                props(key: 'isRowActivatable'): (row: ObjectTypeRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(systemRow)).toBe(false);
    });

    it('navigates to the edit page of the activated row', () => {
        const wrapper = mountIndex();

        (
            wrapper.findComponent(DataGrid) as unknown as {
                vm: { $emit: (event: string, payload: unknown) => void };
            }
        ).vm.$emit('row-activate', row());

        expect(visitMock).toHaveBeenCalledWith(
            ObjectTypesController.edit.url({ objectType: 'companies' }),
        );
    });
});

describe('objectTypes/Index — create and bulk delete', () => {
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

        await selectRow(wrapper, row());
        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            ObjectTypesController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });

    it('never offers a system object type for selection', () => {
        const wrapper = mountIndex();

        const selectable = mount(
            columns(wrapper)[0].cellRenderer as Component,
            {
                props: { params: { data: row() } },
            },
        );

        const system = mount(columns(wrapper)[0].cellRenderer as Component, {
            props: { params: { data: systemRow } },
        });

        expect(selectable.findComponent(Checkbox).exists()).toBe(true);
        expect(system.findComponent(Checkbox).exists()).toBe(false);
    });
});

describe('objectTypes/Index — the storage column is not a raw value', () => {
    it('reads the stored storage strategy in German', () => {
        const column = columns(mountIndex()).find(
            (entry) => entry.colId === 'storage_strategy',
        );
        const formatter =
            column?.valueFormatter as ValueFormatterFunc<ObjectTypeRow>;
        const read = (value: string): string =>
            String(formatter({ value } as never));

        expect(read('generic')).toBe('Gemeinsame Tabelle');
        expect(read('native')).toBe('Eigene Tabelle');
    });
});
