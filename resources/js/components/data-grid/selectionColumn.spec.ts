import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { describe, expect, it } from 'vitest';
import type { Component } from 'vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import { Checkbox } from '@/components/ui/checkbox';
import { useListSelection } from '@/composables/useListSelection';

interface Row {
    id: string;
    locked?: boolean;
}

function gridApiFor(rows: Row[]) {
    return {
        forEachNode: (callback: (node: { data: Row }) => void): void => {
            for (const row of rows) {
                callback({ data: row });
            }
        },
    };
}

function mountHeader(column: ColDef<Row>, rows: Row[]) {
    return mount(column.headerComponent as Component, {
        props: { params: { api: gridApiFor(rows) } },
    });
}

async function toggleHeader(wrapper: ReturnType<typeof mountHeader>) {
    wrapper.findComponent(Checkbox).vm.$emit('update:modelValue', true);

    await wrapper.vm.$nextTick();
}

describe('selectionColumn', () => {
    it('selects every row when the header is toggled after the rows arrived', async () => {
        const selection = useListSelection();
        const rows: Row[] = [];
        const wrapper = mountHeader(selectionColumn<Row>(selection), rows);

        rows.push({ id: 'a' }, { id: 'b' });

        await toggleHeader(wrapper);

        expect(selection.ids.value).toEqual(['a', 'b']);
    });

    it('ticks the header checkbox itself once every row is selected', async () => {
        const selection = useListSelection();
        const rows: Row[] = [];
        const wrapper = mountHeader(selectionColumn<Row>(selection), rows);

        rows.push({ id: 'a' }, { id: 'b' });

        await toggleHeader(wrapper);

        expect(wrapper.findComponent(Checkbox).props('modelValue')).toBe(true);
    });

    it('clears the selection when the header is toggled again', async () => {
        const selection = useListSelection();
        const rows: Row[] = [{ id: 'a' }, { id: 'b' }];
        const wrapper = mountHeader(selectionColumn<Row>(selection), rows);

        await toggleHeader(wrapper);
        await toggleHeader(wrapper);

        expect(selection.ids.value).toEqual([]);
    });

    it('never fills the header completely while some rows cannot be selected', async () => {
        const selection = useListSelection();
        const rows: Row[] = [{ id: 'a' }, { id: 'b', locked: true }];
        const wrapper = mountHeader(
            selectionColumn<Row>(selection, (row) => row.locked !== true),
            rows,
        );

        await toggleHeader(wrapper);

        expect(selection.ids.value).toEqual(['a']);
        expect(wrapper.findComponent(Checkbox).props('modelValue')).toBe(
            'indeterminate',
        );
    });

    it('leaves rows out that are not selectable', async () => {
        const selection = useListSelection();
        const rows: Row[] = [{ id: 'a' }, { id: 'b', locked: true }];
        const column = selectionColumn<Row>(
            selection,
            (row) => row.locked !== true,
        );
        const wrapper = mountHeader(column, rows);

        await toggleHeader(wrapper);

        expect(selection.ids.value).toEqual(['a']);

        const lockedCell = mount(column.cellRenderer as Component, {
            props: { params: { data: rows[1] } },
        });

        expect(lockedCell.findComponent(Checkbox).exists()).toBe(false);
    });

    it('reflects a partial selection as indeterminate', async () => {
        const selection = useListSelection();
        const rows: Row[] = [{ id: 'a' }, { id: 'b' }];
        const wrapper = mountHeader(selectionColumn<Row>(selection), rows);

        selection.toggle('a');

        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(Checkbox).props('modelValue')).toBe(
            'indeterminate',
        );
    });
});
