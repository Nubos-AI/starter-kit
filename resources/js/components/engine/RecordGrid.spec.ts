import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import AgingCellRenderer from '@/components/engine/AgingCellRenderer.vue';
import RecordGrid from '@/components/engine/RecordGrid.vue';
import { AGING_COLUMN_IDS } from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';
import { toGridColumns } from '@/types/grid';
import type { GridColumn } from '@/types/grid';
import type { RecordObjectType } from '@/types/records';
import { setUrlDefaults } from '@/wayfinder';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), get: vi.fn() },
    usePage: () => ({
        url: '/nubos/records/deals',
        props: {
            auth: {
                user: null,
                can: { 'deals.update': true, 'deals.delete': true },
                authority: null,
            },
        },
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

const SELECTION_COL_ID = '__bulk_select__';

const ACTIONS_COL_ID = 'actions';

const BUSINESS_KEY_COL_ID = 'record_number';

const gridObjectType: RecordObjectType = {
    id: '01OBJECTTYPE000000000001',
    key: 'deals',
    slug: 'deals',
    name: 'Deals',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const businessFields: FieldDefinition[] = [
    {
        key: 'titel',
        field_type: 'text_short',
        label: 'Titel',
        is_required: true,
        is_sortable: true,
        is_filterable: true,
        is_default_column: true,
        list_position: 1,
    },
    {
        key: 'score',
        field_type: 'computed',
        label: 'Punkte',
        is_required: false,
        is_sortable: false,
        is_filterable: false,
        is_default_column: true,
        list_position: 2,
    },
];

const agingFields: FieldDefinition[] = [
    {
        key: AGING_COLUMN_IDS.age,
        field_type: 'number',
        label: 'Alter',
        is_required: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: false,
    },
    {
        key: AGING_COLUMN_IDS.stage,
        field_type: 'number',
        label: 'Stufe',
        is_required: false,
        is_sortable: true,
        is_filterable: true,
        is_default_column: false,
    },
];

const DataGridStub = {
    name: 'DataGridStub',
    props: ['columnDefs', 'rowModelType', 'cacheBlockSize', 'getRowId'],
    template: '<div data-grid-stub />',
};

const gridStubs = {
    DataGrid: DataGridStub,
    FormulaBackfillBanner: { template: '<div data-backfill-banner />' },
    ExportButton: { template: '<div data-export-button />' },
    ColumnMenu: { template: '<div data-column-menu />' },
    BulkActionBar: { template: '<div data-bulk-action-bar />' },
    ConflictDialog: { template: '<div data-conflict-dialog />' },
    ConfirmDialog: { template: '<div data-confirm-dialog />' },
};

async function mountGrid(
    fieldDefinitions: FieldDefinition[],
): Promise<VueWrapper> {
    const columns: GridColumn[] = toGridColumns(businessFields);

    const wrapper = mount(RecordGrid, {
        props: { objectType: gridObjectType, columns, fieldDefinitions },
        global: { stubs: gridStubs },
    });

    await nextTick();

    return wrapper;
}

function gridDefs(wrapper: VueWrapper): ColDef[] {
    const grid = wrapper.findComponent(DataGridStub);

    expect(grid.exists()).toBe(true);

    return (grid as unknown as { props(key: 'columnDefs'): ColDef[] }).props(
        'columnDefs',
    );
}

function gridDef(wrapper: VueWrapper, colId: string): ColDef {
    const found = gridDefs(wrapper).find((def) => def.colId === colId);

    if (found === undefined) {
        throw new Error(`Column "${colId}" not found`);
    }

    return found;
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
});

describe('RecordGrid — the aging column of an object type with rules', () => {
    it('shows the age column although it is no default column of the object type', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const def = gridDef(wrapper, AGING_COLUMN_IDS.age);

        expect(def.hide).toBe(false);
        expect(def.field).toBe('aging.age');
    });

    it('renders the age through the aging cell renderer instead of a bare number', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const def = gridDef(wrapper, AGING_COLUMN_IDS.age);

        expect(def.cellRenderer).toBe(AgingCellRenderer);
        expect(def.cellDataType).toBe(false);
    });

    it('keeps the age column sortable and filterable but never editable', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const def = gridDef(wrapper, AGING_COLUMN_IDS.age);

        expect(def.sortable).toBe(true);
        expect(def.filter).toBe(true);
        expect(def.editable).toBe(false);
    });

    it('carries the stage as a hidden, read-only column that filters and sorts', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const def = gridDef(wrapper, AGING_COLUMN_IDS.stage);

        expect(def.field).toBe('aging.stage');
        expect(def.hide).toBe(true);
        expect(def.editable).toBe(false);
        expect(def.sortable).toBe(true);
        expect(def.filter).toBe(true);
    });

    it('names every column exactly once so that server sort and filter stay unambiguous', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const colIds = gridDefs(wrapper).map((def) => def.colId);

        expect(colIds).toContain(AGING_COLUMN_IDS.age);
        expect(new Set(colIds).size).toBe(colIds.length);
    });
});

describe('RecordGrid — an object type without aging rules', () => {
    it('offers no aging column at all', async () => {
        const wrapper = await mountGrid(businessFields);
        const agingColIds = gridDefs(wrapper)
            .map((def) => def.colId ?? '')
            .filter((colId) => colId.startsWith('aging_'));

        expect(agingColIds).toEqual([]);
    });
});

describe('RecordGrid — the existing columns survive the aging column', () => {
    it('keeps selection, business key, business fields and the action column in place', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);
        const colIds = gridDefs(wrapper).map((def) => def.colId);

        expect(colIds).toContain(SELECTION_COL_ID);
        expect(colIds).toContain(BUSINESS_KEY_COL_ID);
        expect(colIds).toContain('titel');
        expect(colIds).toContain('score');
        expect(colIds).toContain(ACTIONS_COL_ID);
    });

    it('leaves the formula error renderer and inline editing of the business fields untouched', async () => {
        const wrapper = await mountGrid([...businessFields, ...agingFields]);

        const formula = gridDef(wrapper, 'score').cellRenderer as {
            name?: string;
        };

        expect(formula.name).toBe('FormulaErrorCellRenderer');
        expect(gridDef(wrapper, 'score').cellDataType).toBe(false);
        expect(gridDef(wrapper, 'titel').editable).toBe(true);
    });
});
