import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { REPORT_EXECUTION_MODE } from '@/lib/statusMaps';
import Index from '@/pages/reports/Index.vue';
import type { ReportListRow } from '@/types/reports';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    postMock: vi.fn(),
    visitMock: vi.fn(),
    pageState: {
        url: '/nubos/reports',
        props: {
            auth: {
                user: null,
                can: {} as Record<string, boolean>,
                authority: null as string | null,
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

type Wrapper = VueWrapper;

type ValueGetterLike = (params: { data?: ReportListRow }) => unknown;

const ENGLISH_UPDATE_REASON = 'Only the owner of this report can change it.';

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function row(overrides: Partial<ReportListRow> = {}): ReportListRow {
    return {
        id: '01REPORT0000000000000001',
        name: 'Pipeline nach Phase',
        description: null,
        object_type_id: '01OBJECTTYPE000000000001',
        object_type: {
            id: '01OBJECTTYPE000000000001',
            slug: 'deals',
            name: 'Deals',
        },
        filter_definition: [],
        aggregation_type: 'count',
        aggregation_field_key: null,
        group_by_field_key: 'stage',
        group_by_bucket: null,
        series_field_key: null,
        chart_type: 'bar',
        execution_mode: 'viewer',
        is_owner: true,
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountIndex(reports: ReportListRow[] = [row()]): Wrapper {
    return mount(Index, { props: { reports }, global: { stubs } });
}

function columns(wrapper: Wrapper): ColDef<ReportListRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<ReportListRow>[];
        }
    ).props('columnDefs');
}

function column(wrapper: Wrapper, colId: string): ColDef<ReportListRow> {
    const found = columns(wrapper).find((entry) => entry.colId === colId);

    if (found === undefined) {
        throw new Error(`Column "${colId}" is missing`);
    }

    return found;
}

function valueGetterOf(wrapper: Wrapper, colId: string): ValueGetterLike {
    const getter = column(wrapper, colId).valueGetter;

    if (typeof getter !== 'function') {
        throw new Error(`Column "${colId}" must resolve through a valueGetter`);
    }

    return getter as unknown as ValueGetterLike;
}

function rowActions(wrapper: Wrapper): RowAction<ReportListRow>[] {
    const actions = column(wrapper, 'actions').cellRendererParams?.actions;

    if (!Array.isArray(actions)) {
        throw new Error('The actions column carries no row actions');
    }

    return actions;
}

function action(wrapper: Wrapper, label: string): RowAction<ReportListRow> {
    const found = rowActions(wrapper).find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

function selectionCell(wrapper: Wrapper, data: ReportListRow): Wrapper {
    const renderer = columns(wrapper)[0].cellRenderer;

    return mount(renderer as Component, { props: { params: { data } } });
}

beforeEach(() => {
    deleteMock.mockReset();
    postMock.mockReset();
    visitMock.mockReset();
    pageState.props.auth.authority = null;
});

describe('reports/Index — grid', () => {
    it('feeds every report to the grid', () => {
        const wrapper = mountIndex([row(), row({ id: 'second' })]);

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): ReportListRow[];
                }
            ).props('rowData'),
        ).toHaveLength(2);
    });

    it('hides the grid entirely while there is not a single report', () => {
        expect(mountIndex([]).findComponent(DataGrid).exists()).toBe(false);
    });

    it('keeps the page heading and the create button in the empty state', () => {
        const wrapper = mountIndex([]);

        expect(wrapper.find('[data-create-button]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Auswertungen');
    });

    it('reads the object type name off the nested object instead of stringifying it', () => {
        const wrapper = mountIndex();
        const getter = valueGetterOf(wrapper, 'object_type');

        expect(getter({ data: row() })).toBe('Deals');

        const empty = String(
            getter({ data: row({ object_type: null }) }) ?? '',
        );

        expect(empty).not.toContain('[object');
        expect(empty).not.toContain('Deals');
    });

    it('renders the execution mode through the shared status map', () => {
        const wrapper = mountIndex([row({ execution_mode: 'definer' })]);

        expect(
            column(wrapper, 'execution_mode').cellRendererParams?.statusMap,
        ).toBe(REPORT_EXECUTION_MODE);
    });
});

describe('reports/Index — opening a row', () => {
    it('opens the row on click at the very target the pencil points at', async () => {
        const wrapper = mountIndex();

        const pencilHref = action(wrapper, 'Bearbeiten').href?.(row());

        (
            wrapper.findComponent(DataGrid) as unknown as {
                vm: { $emit: (event: string, payload: ReportListRow) => void };
            }
        ).vm.$emit('row-activate', row());
        await wrapper.vm.$nextTick();

        expect(pencilHref).toBe(
            ReportsController.edit.url({ report: row().id }),
        );
        expect(visitMock.mock.calls[0][0]).toBe(pencilHref);
    });

    it('activates a row only while the actor may edit it', () => {
        const wrapper = mountIndex();
        const isRowActivatable = (
            wrapper.findComponent(DataGrid) as unknown as {
                props(key: 'isRowActivatable'): (row: ReportListRow) => boolean;
            }
        ).props('isRowActivatable');

        expect(isRowActivatable(row())).toBe(true);
        expect(isRowActivatable(row({ can_update: false }))).toBe(false);
    });

    it('leaves the name unlinked while the actor may not edit it', () => {
        const wrapper = mountIndex();
        const href = column(wrapper, 'name').cellRendererParams?.href;

        if (typeof href !== 'function') {
            throw new Error('The name column must render a link');
        }

        expect(href(row())).toBe(
            ReportsController.edit.url({ report: row().id }),
        );
        expect(href(row({ can_update: false }))).toBeUndefined();
    });
});

describe('reports/Index — forbidden actions', () => {
    it('lines the actions up as edit first and destructive delete last', () => {
        const wrapper = mountIndex();
        const actions = rowActions(wrapper);

        expect(actions.map((entry) => entry.label)).toEqual([
            'Bearbeiten',
            'Löschen',
        ]);
        expect(actions[0].variant).toBe('edit');
        expect(actions[1].variant).toBe('destructive');
    });

    it('disables a forbidden action with a German reason instead of hiding it', () => {
        const blocked = row({
            can_update: false,
            can_delete: false,
            update_reason: 'not_owner',
            delete_reason: 'not_owner',
            is_owner: false,
        });
        const wrapper = mountIndex([blocked]);

        const edit = action(wrapper, 'Bearbeiten');

        expect(edit.isVisible).toBeUndefined();
        expect(edit.isDisabled?.(blocked)).toBe(true);

        const reason = edit.disabledReason?.(blocked) ?? '';

        expect(reason.length).toBeGreaterThan(10);
        expect(reason).not.toBe(ENGLISH_UPDATE_REASON);
        expect(reason).not.toBe('Keine Berechtigung');
        expect(action(wrapper, 'Löschen').isDisabled?.(blocked)).toBe(true);
    });

    it('tells the two refusal reasons apart', () => {
        const notOwner = row({
            can_update: false,
            update_reason: 'not_owner',
        });
        const notPermitted = row({
            can_update: false,
            update_reason: 'object_type_not_permitted',
        });
        const wrapper = mountIndex([notOwner]);

        const edit = action(wrapper, 'Bearbeiten');

        expect(edit.disabledReason?.(notOwner)).not.toBe(
            edit.disabledReason?.(notPermitted),
        );
    });

    it('answers a reason it does not know with a fallback rather than an empty tooltip', () => {
        const unknown = row({
            can_update: false,
            update_reason: 'something_new_from_the_server',
        });
        const wrapper = mountIndex([unknown]);

        const reason = action(wrapper, 'Bearbeiten').disabledReason?.(unknown);

        expect(reason).toBeDefined();
        expect(String(reason).length).toBeGreaterThan(10);
    });

    it('carries no reason at all for a row the actor may govern', () => {
        const wrapper = mountIndex();

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(row())).toBe(false);
        expect(
            action(wrapper, 'Bearbeiten').disabledReason?.(row()),
        ).toBeUndefined();
    });
});

describe('reports/Index — selection and bulk delete', () => {
    it('keeps a report the actor may not delete out of the selection', () => {
        const blocked = row({ can_delete: false });
        const wrapper = mountIndex([blocked]);

        expect(
            selectionCell(wrapper, blocked).findComponent(Checkbox).exists(),
        ).toBe(false);
        expect(
            selectionCell(wrapper, row()).findComponent(Checkbox).exists(),
        ).toBe(true);
    });

    it('offers the bulk bar only once something is selected', async () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );

        selectionCell(wrapper, row())
            .findComponent(Checkbox)
            .vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);
    });

    it('sends the selected ids to the bulk delete endpoint', async () => {
        const wrapper = mountIndex();

        selectionCell(wrapper, row())
            .findComponent(Checkbox)
            .vm.$emit('update:modelValue', true);
        await wrapper.vm.$nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledWith(
            ReportsController.bulkDestroy.url(),
            { ids: [row().id] },
            expect.anything(),
        );
    });
});

describe('reports/Index — deleting', () => {
    it('asks before deleting and never deletes straight away', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await wrapper.vm.$nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('labels the confirmation in German and marks it as destructive', () => {
        const wrapper = mountIndex();
        const dialog = wrapper.findComponent(ConfirmDialog);

        expect(dialog.props('confirmLabel')).toBe('Löschen');
        expect(dialog.props('variant')).toBe('destructive');
    });

    it('deletes through the wayfinder route once confirmed', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await wrapper.vm.$nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await wrapper.vm.$nextTick();

        expect(deleteMock.mock.calls[0][0]).toBe(
            ReportsController.destroy.url({ report: row().id }),
        );
    });
});
