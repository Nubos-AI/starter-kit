import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import SegmentManagementController from '@/actions/App/Http/Controllers/Engine/SegmentManagementController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import Index from '@/pages/segments/Index.vue';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, pageState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    postMock: vi.fn(),
    pageState: {
        url: '/nubos/engine/segment-management',
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

interface SegmentRow {
    id: string;
    name: string;
    object_type: string | null;
    is_owner: boolean;
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

function row(overrides: Partial<SegmentRow> = {}): SegmentRow {
    return {
        id: '01SEGMENT000000000000001',
        name: 'Offene Aufgaben',
        object_type: 'Companies',
        is_owner: true,
        can_update: true,
        can_delete: true,
        ...overrides,
    };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
    mounted.push(wrapper);

    return wrapper;
}

function mountIndex(segments: SegmentRow[] = [row()]): Wrapper {
    return track(mount(Index, { props: { segments }, global: { stubs } }));
}

function columns(wrapper: Wrapper): ColDef<SegmentRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<SegmentRow>[];
        }
    ).props('columnDefs');
}

function action(wrapper: Wrapper, label: string): RowAction<SegmentRow> {
    const actionsCol = columns(wrapper).find(
        (column) => column.colId === 'actions',
    );

    const actions = actionsCol?.cellRendererParams
        ?.actions as RowAction<SegmentRow>[];

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

describe('segments/Index', () => {
    it('feeds every segment to the grid', () => {
        const wrapper = mountIndex();

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): SegmentRow[];
                }
            ).props('rowData'),
        ).toHaveLength(1);
    });

    it('shows no grid without any segment', () => {
        expect(mountIndex([]).findComponent(DataGrid).exists()).toBe(false);
    });

    it('disables the delete action of a shared segment', () => {
        const shared = row({ is_owner: false, can_delete: false });
        const wrapper = mountIndex([shared]);

        expect(action(wrapper, 'Löschen').isDisabled?.(shared)).toBe(true);
        expect(action(wrapper, 'Löschen').disabledReason?.(shared)).toBe(
            'Keine Berechtigung',
        );
        expect(action(wrapper, 'Bearbeiten').isDisabled?.(shared)).toBe(false);
    });

    it('asks before deleting a single segment', async () => {
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
                props(key: 'isRowActivatable'): (row: SegmentRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(row({ can_update: false }))).toBe(false);
    });

    it('keeps a segment the actor may not delete out of the selection', () => {
        const wrapper = mountIndex([row({ can_delete: false })]);

        const cell = track(
            mount(columns(wrapper)[0].cellRenderer as Component, {
                props: { params: { data: row({ can_delete: false }) } },
            }),
        );

        expect(cell.findComponent(Checkbox).exists()).toBe(false);
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
            SegmentManagementController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });
});
