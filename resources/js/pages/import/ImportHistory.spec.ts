import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ImportHistory from '@/pages/import/ImportHistory.vue';
import type { RowAction } from '@/types/rowAction';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => ({
        url: '/nubos/engine/import/companies/history',
        props: {},
    }),
}));

interface HistoryRow {
    id: string;
    status: 'completed';
    originalFilename: string;
    format: string;
    totalRows: number;
    createdCount: number;
    updatedCount: number;
    errorCount: number;
    hasErrorReport: boolean;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string | null;
}

type Wrapper = ReturnType<typeof mount>;

const mounted: Wrapper[] = [];

function job(overrides: Partial<HistoryRow> = {}): HistoryRow {
    return {
        id: '01IMPORTJOB000000000000001',
        status: 'completed',
        originalFilename: 'kunden.csv',
        format: 'csv',
        totalRows: 4,
        createdCount: 2,
        updatedCount: 1,
        errorCount: 1,
        hasErrorReport: true,
        startedAt: null,
        finishedAt: null,
        createdAt: '2026-11-01T08:00:00.000Z',
        ...overrides,
    };
}

function mountHistory(jobs: HistoryRow[]): Wrapper {
    const wrapper = mount(ImportHistory, {
        props: { objectType: 'companies', jobs },
    });

    mounted.push(wrapper);

    return wrapper;
}

function columns(wrapper: Wrapper): ColDef<HistoryRow>[] {
    const columnDefs: unknown = wrapper
        .findComponent({ name: 'DataGrid' })
        .props('columnDefs');

    if (!Array.isArray(columnDefs)) {
        throw new Error('data grid has no columns');
    }

    return columnDefs;
}

function isActionList(value: unknown): value is RowAction<HistoryRow>[] {
    return Array.isArray(value);
}

function reportAction(wrapper: Wrapper): RowAction<HistoryRow> {
    const params: unknown = columns(wrapper).find(
        (entry) => entry.colId === 'actions',
    )?.cellRendererParams;

    if (
        typeof params !== 'object' ||
        params === null ||
        !('actions' in params) ||
        !isActionList(params.actions) ||
        params.actions[0] === undefined
    ) {
        throw new Error('actions column has no actions');
    }

    return params.actions[0];
}

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('import history', () => {
    it('reads the camel case fields of the import job resource', () => {
        const wrapper = mountHistory([job()]);

        const fields = columns(wrapper).map((column) => column.field);

        expect(fields).toContain('createdAt');
        expect(fields).toContain('originalFilename');
        expect(fields).toContain('totalRows');
    });

    it('offers the error report only for a run that wrote one', () => {
        const wrapper = mountHistory([job()]);
        const action = reportAction(wrapper);

        expect(action.isVisible?.(job())).toBe(true);
        expect(action.isVisible?.(job({ hasErrorReport: false }))).toBe(false);
    });
});
