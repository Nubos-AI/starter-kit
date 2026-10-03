import { mount } from '@vue/test-utils';
import type { CellClickedEvent } from 'ag-grid-community';
import { describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { defineComponent, h } from 'vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { agGridLocaleDe } from '@/components/data-grid/localeText';

vi.mock('ag-grid-vue3', () => ({
    AgGridVue: defineComponent({
        name: 'AgGridVueStub',
        props: [
            'isFullWidthRow',
            'fullWidthCellRenderer',
            'rowData',
            'localeText',
            'rowHeight',
            'headerHeight',
            'defaultColDef',
        ],
        emits: ['cellClicked', 'gridReady', 'sortChanged'],
        setup: () => () => h('div', { 'data-grid-stub': true }),
    }),
}));

vi.mock('@/lib/agGrid', () => ({ registerAgGridModules: vi.fn() }));

interface Row {
    id: string;
    name: string;
    canEdit: boolean;
}

const editable: Row = { id: 'row-1', name: 'Companies', canEdit: true };
const locked: Row = { id: 'row-2', name: 'Attachments', canEdit: false };

type Wrapper = ReturnType<typeof mount>;

function mountGrid(props: Record<string, unknown> = {}): Wrapper {
    return mount(DataGrid as unknown as Component, {
        props: {
            columnDefs: [{ colId: 'name', field: 'name' }],
            rowData: [editable, locked],
            isRowActivatable: (row: Row) => row.canEdit,
            ...props,
        },
    });
}

function clickCell(
    wrapper: Wrapper,
    colId: string,
    data: Row,
    target: EventTarget | null = null,
): void {
    wrapper.findComponent({ name: 'AgGridVueStub' }).vm.$emit('cellClicked', {
        data,
        column: { getColId: () => colId },
        event: { target },
    } as unknown as CellClickedEvent<Row>);
}

function activations(wrapper: Wrapper): unknown[][] {
    return wrapper.emitted('row-activate') ?? [];
}

describe('DataGrid — a click on the row opens it', () => {
    it('gives data columns the same filtering and resizing controls by default', () => {
        const grid = mountGrid().findComponent({ name: 'AgGridVueStub' });

        expect(grid.props('defaultColDef')).toMatchObject({
            sortable: true,
            resizable: true,
            filter: true,
        });
    });
    it('does not allow a page to replace the shared theme or row geometry', () => {
        const wrapper = mount(DataGrid as unknown as Component, {
            props: { columnDefs: [], rowData: [] },
            attrs: {
                theme: 'legacy',
                rowHeight: 70,
                'header-height': 80,
                rowStyle: { background: 'red' },
            },
        });
        const grid = wrapper.findComponent({ name: 'AgGridVueStub' });

        expect(grid.props('rowHeight')).toBe(32);
        expect(grid.props('headerHeight')).toBe(34);
        expect(grid.attributes('theme')).not.toBe('legacy');
        expect(grid.attributes('rowstyle')).toBeUndefined();
    });
    it('supplies row and header heights even when CSS cannot be measured in a hidden view', () => {
        const wrapper = mountGrid();
        const grid = wrapper.findComponent({ name: 'AgGridVueStub' });

        expect(grid.props('rowHeight')).toBe(32);
        expect(grid.props('headerHeight')).toBe(34);
    });
    it('activates the row of a plain data cell', () => {
        const wrapper = mountGrid();

        clickCell(wrapper, 'name', editable);

        expect(activations(wrapper)).toEqual([[editable]]);
    });

    it('never activates a row the caller marks as locked', () => {
        const wrapper = mountGrid();

        clickCell(wrapper, 'name', locked);

        expect(activations(wrapper)).toHaveLength(0);
    });

    it('leaves the selection checkbox and the action buttons alone', () => {
        const wrapper = mountGrid();

        clickCell(wrapper, '__list_select__', editable);
        clickCell(wrapper, 'actions', editable);

        expect(activations(wrapper)).toHaveLength(0);
    });

    it('lets a link or button inside a cell handle its own click', () => {
        const wrapper = mountGrid();
        const link = document.createElement('a');
        const button = document.createElement('button');
        const inside = document.createElement('span');
        button.appendChild(inside);

        clickCell(wrapper, 'name', editable, link);
        clickCell(wrapper, 'name', editable, inside);

        expect(activations(wrapper)).toHaveLength(0);
    });

    it('stays inert when the caller offers no activation', () => {
        const wrapper = mountGrid({ isRowActivatable: undefined });

        clickCell(wrapper, 'name', editable);

        expect(activations(wrapper)).toHaveLength(0);
    });
});

describe('DataGrid — full-width rows', () => {
    it('passes neither a predicate nor a renderer when the caller wants none', () => {
        const grid = mountGrid().findComponent({ name: 'AgGridVueStub' });

        expect(grid.props('isFullWidthRow')).toBeUndefined();
        expect(grid.props('fullWidthCellRenderer')).toBeUndefined();
    });

    it('hands the predicate and the renderer to the grid untouched', () => {
        const isFullWidthRow = vi.fn(() => true);
        const fullWidthCellRenderer = defineComponent({
            name: 'FullWidthStub',
            setup: () => () => h('div'),
        });

        const grid = mountGrid({
            isFullWidthRow,
            fullWidthCellRenderer,
        }).findComponent({ name: 'AgGridVueStub' });

        expect(grid.props('isFullWidthRow')).toBe(isFullWidthRow);
        expect(grid.props('fullWidthCellRenderer')).toStrictEqual(
            fullWidthCellRenderer,
        );
    });
});

describe('DataGrid — listeners the parent passes through', () => {
    it('still calls the parent grid-ready handler although the grid binds its own', async () => {
        const onGridReady = vi.fn();
        const wrapper = mountGrid({ onGridReady });

        await wrapper
            .findComponent({ name: 'AgGridVueStub' })
            .vm.$emit('gridReady', { api: { applyColumnState: vi.fn() } });

        expect(onGridReady).toHaveBeenCalledTimes(1);
    });

    it('still calls the parent sort handler although the grid binds its own', async () => {
        const onSortChanged = vi.fn();
        const wrapper = mountGrid({ onSortChanged });

        await wrapper
            .findComponent({ name: 'AgGridVueStub' })
            .vm.$emit('sortChanged', { api: {}, source: 'ui' });

        expect(onSortChanged).toHaveBeenCalledTimes(1);
    });
});

describe('DataGrid — the grid speaks German', () => {
    it('hands AG Grid the German locale so the column filter is not English', () => {
        const grid = mountGrid().findComponent({ name: 'AgGridVueStub' });
        const localeText = grid.props('localeText') as Record<string, string>;

        expect(localeText).toBe(agGridLocaleDe);
        expect(localeText.contains).toBe('Enthält');
        expect(localeText.andCondition).toBe('UND');
    });
});
