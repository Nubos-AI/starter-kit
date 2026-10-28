import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { nextTick } from 'vue';
import ApprovalsController from '@/actions/App/Http/Controllers/Approvals/ApprovalsController';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import Index from '@/pages/approvals/Index.vue';

const { visitMock, pageState } = vi.hoisted(() => {
    const can: Record<string, boolean> = {};

    return {
        visitMock: vi.fn(),
        pageState: {
            url: '/nubos/engine/approvals',
            props: {
                auth: {
                    user: null,
                    can,
                    authority: null,
                },
            },
        },
    };
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: visitMock,
    },
    usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

interface ApprovalWorkloadRow {
    id: string;
    record_id: string | null;
    anchor_label: string;
    anchor_kind_label: string;
    anchor_url: string | null;
    transition_label: string;
    stage_label: string;
    deadline_at: string | null;
    is_overdue: boolean;
    on_behalf_of_id: string | null;
    on_behalf_of_label: string | null;
}

interface AnchorLinkParams {
    href: (row: ApprovalWorkloadRow) => string;
}

function row(
    overrides: Partial<ApprovalWorkloadRow> = {},
): ApprovalWorkloadRow {
    return {
        id: '01APPROVALPROCESS000000001',
        record_id: '01RECORD000000000000000001',
        anchor_label: 'Rahmenvertrag 2026',
        anchor_kind_label: 'Verträge',
        anchor_url: 'https://nubos.test/anchor-target',
        transition_label: 'Offen → Genehmigt',
        stage_label: 'Stufe 1',
        deadline_at: '2026-09-05T12:00:00.000Z',
        is_overdue: false,
        on_behalf_of_id: null,
        on_behalf_of_label: null,
        ...overrides,
    };
}

const mounted: Wrapper[] = [];

function track(wrapper: Wrapper): Wrapper {
    mounted.push(wrapper);

    return wrapper;
}

function mountIndex(approvals: ApprovalWorkloadRow[] = [row()]): Wrapper {
    return track(mount(Index, { props: { approvals } }));
}

function mountedGrid(wrapper: Wrapper): object {
    const grid: unknown = wrapper.findComponent(DataGrid);

    if (typeof grid !== 'object' || grid === null) {
        throw new Error('data grid is not mounted');
    }

    return grid;
}

function gridProp(wrapper: Wrapper, key: 'columnDefs' | 'rowData'): unknown[] {
    const grid = mountedGrid(wrapper);

    if (!('props' in grid) || typeof grid.props !== 'function') {
        throw new Error('data grid exposes no props');
    }

    const value: unknown = grid.props(key);

    if (!Array.isArray(value)) {
        throw new Error(`data grid prop ${key} is not a list`);
    }

    return value;
}

function isColumn(value: unknown): value is ColDef<ApprovalWorkloadRow> {
    return typeof value === 'object' && value !== null;
}

function columns(wrapper: Wrapper): ColDef<ApprovalWorkloadRow>[] {
    return gridProp(wrapper, 'columnDefs').filter(isColumn);
}

function column(wrapper: Wrapper, colId: string): ColDef<ApprovalWorkloadRow> {
    const found = columns(wrapper).find((entry) => entry.colId === colId);

    if (found === undefined) {
        throw new Error(`column ${colId} is missing`);
    }

    return found;
}

function isComponent(value: unknown): value is Component {
    return (
        typeof value === 'object' &&
        value !== null &&
        ('setup' in value || 'render' in value)
    );
}

function renderCell(
    wrapper: Wrapper,
    colId: string,
    params: Record<string, unknown>,
): Wrapper {
    const renderer: unknown = column(wrapper, colId).cellRenderer;

    if (!isComponent(renderer)) {
        throw new Error(`${colId} column has no cell renderer`);
    }

    return track(mount(renderer, { props: { params } }));
}

async function activateRow(
    wrapper: Wrapper,
    target: ApprovalWorkloadRow,
): Promise<void> {
    const grid = mountedGrid(wrapper);
    const vm: unknown = 'vm' in grid ? grid.vm : undefined;

    if (
        typeof vm !== 'object' ||
        vm === null ||
        !('$emit' in vm) ||
        typeof vm.$emit !== 'function'
    ) {
        throw new Error('data grid cannot emit a row activation');
    }

    vm.$emit('row-activate', target);

    await nextTick();
}

function anchorLinkParams(wrapper: Wrapper): AnchorLinkParams {
    const params: unknown = column(wrapper, 'record').cellRendererParams;

    if (
        typeof params !== 'object' ||
        params === null ||
        !('href' in params) ||
        typeof params.href !== 'function'
    ) {
        throw new Error('record column has no href resolver');
    }

    const href = params.href;

    return {
        href: (target: ApprovalWorkloadRow): string => String(href(target)),
    };
}

function renderAnchorCell(
    wrapper: Wrapper,
    target: ApprovalWorkloadRow,
): Wrapper {
    return renderCell(wrapper, 'record', {
        data: target,
        value: target.anchor_label,
        href: anchorLinkParams(wrapper).href,
    });
}

beforeEach(() => {
    visitMock.mockReset();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('approvals/Index', () => {
    it('shows an explanatory empty state', () => {
        const wrapper = mountIndex([]);

        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
        expect(wrapper.text()).toContain(
            'Aktuell warten keine Genehmigungsvorgänge auf Ihre Entscheidung.',
        );
    });

    it('feeds every open approval to the grid when there is at least one', () => {
        const wrapper = mountIndex([row(), row({ id: 'second-process' })]);

        expect(gridProp(wrapper, 'rowData')).toHaveLength(2);
    });

    it('reads the anchor label and the anchor kind from the server payload', () => {
        const wrapper = mountIndex();

        expect(column(wrapper, 'record').field).toBe('anchor_label');
        expect(column(wrapper, 'record').headerName).toBe('Vorgang');
        expect(column(wrapper, 'objectType').field).toBe('anchor_kind_label');
        expect(column(wrapper, 'objectType').headerName).toBe('Art');
    });

    it('links the anchor label to the target the server provides', () => {
        const target = row({
            record_id: null,
            anchor_label: 'Promotion vom 10.09.2026 09:00 UTC',
            anchor_kind_label: 'Promotion',
            anchor_url: 'https://nubos.test/promotion-target',
        });
        const wrapper = mountIndex([target]);

        expect(anchorLinkParams(wrapper).href(target)).toBe(
            'https://nubos.test/promotion-target',
        );

        const link = renderAnchorCell(wrapper, target).get('a');

        expect(link.attributes('href')).toBe(
            'https://nubos.test/promotion-target',
        );
        expect(link.text()).toBe('Promotion vom 10.09.2026 09:00 UTC');
    });

    it('links the label of a record bound row to the record target the server provides', () => {
        const target = row({
            id: 'record-bound-process',
            record_id: '01RECORD000000000000000042',
            anchor_label: 'Rahmenvertrag 2026',
            anchor_kind_label: 'Verträge',
            anchor_url: 'https://nubos.test/record-target',
        });
        const wrapper = mountIndex([target]);

        expect(anchorLinkParams(wrapper).href(target)).toBe(target.anchor_url);

        const link = renderAnchorCell(wrapper, target).get('a');

        expect(link.attributes('href')).toBe(target.anchor_url);
        expect(link.attributes('href')).not.toBe(
            ApprovalsController.show.url({ approval: target.id }),
        );
        expect(link.text()).toBe('Rahmenvertrag 2026');
    });

    it('falls back to the decision page when the anchor has no target of its own', () => {
        const target = row({
            id: 'process-without-anchor-target',
            record_id: null,
            anchor_label: 'Promotion vom 10.09.2026 09:00 UTC',
            anchor_kind_label: 'Promotion',
            anchor_url: null,
        });
        const wrapper = mountIndex([target]);

        const href = anchorLinkParams(wrapper).href(target);

        expect(href).toBe(
            ApprovalsController.show.url({ approval: target.id }),
        );
        expect(href).toContain(target.id);
    });

    it('marks an overdue deadline with a badge carrying a data hook', () => {
        const wrapper = mountIndex([row({ is_overdue: true })]);

        const cell = renderCell(wrapper, 'deadline', {
            data: row({ is_overdue: true }),
        });

        expect(cell.find('[data-approval-overdue]').exists()).toBe(true);
    });

    it('renders a deadline that is not overdue without the overdue badge hook', () => {
        const wrapper = mountIndex([row({ is_overdue: false })]);

        const cell = renderCell(wrapper, 'deadline', {
            data: row({ is_overdue: false }),
        });

        expect(cell.find('[data-approval-overdue]').exists()).toBe(false);
    });

    it('navigates to the decision page of the activated row', async () => {
        const target = row({ id: 'process-to-open' });
        const wrapper = mountIndex([target]);

        await activateRow(wrapper, target);

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(String(visitMock.mock.calls[0][0])).toContain(target.id);
    });

    it('keeps the row activation on the decision page even when the anchor has its own target', async () => {
        const target = row({
            id: 'process-with-anchor-target',
            anchor_url: 'https://nubos.test/promotion-target',
        });
        const wrapper = mountIndex([target]);

        await activateRow(wrapper, target);

        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(String(visitMock.mock.calls[0][0])).toBe(
            ApprovalsController.show.url({ approval: target.id }),
        );
    });
});
