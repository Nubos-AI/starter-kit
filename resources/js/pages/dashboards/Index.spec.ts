import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { nextTick } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { formatDateTime } from '@/lib/formatDate';
import { DASHBOARD_VISIBILITY } from '@/lib/statusMaps';
import Index from '@/pages/dashboards/Index.vue';
import type { DashboardRow } from '@/types/dashboards';
import { resolveDashboardActionRefusal } from '@/types/dashboards';
import type { RowAction } from '@/types/rowAction';

const { deleteMock, postMock, visitMock, pageState } = vi.hoisted(() => ({
    deleteMock: vi.fn(),
    postMock: vi.fn(),
    visitMock: vi.fn(),
    pageState: {
        url: '/nubos/dashboards',
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

type ValueGetterLike = (params: { data?: DashboardRow }) => unknown;

const ENGLISH_WORDS =
    /\b(the|this|that|you|your|only|cannot|owner|dashboard\s+is)\b/i;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogFooter: passthrough,
    DialogTitle: passthrough,
    DialogDescription: passthrough,
};

function row(overrides: Partial<DashboardRow> = {}): DashboardRow {
    return {
        id: '01DASHBOARD00000000000001',
        name: 'Vertriebsübersicht',
        description: 'Kennzahlen des laufenden Quartals',
        owner_id: '01USER00000000000000001A',
        is_owner: true,
        is_tenant_wide: false,
        is_default: false,
        can_update: true,
        can_delete: true,
        can_share: true,
        update_reason: null,
        delete_reason: null,
        share_reason: null,
        has_definer_widget: false,
        updated_at: '2026-08-10T14:23:45+02:00',
        ...overrides,
    };
}

function mountIndex(dashboards: DashboardRow[] = [row()]): Wrapper {
    return mount(Index, { props: { dashboards }, global: { stubs } });
}

function columns(wrapper: Wrapper): ColDef<DashboardRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<DashboardRow>[];
        }
    ).props('columnDefs');
}

function column(wrapper: Wrapper, colId: string): ColDef<DashboardRow> {
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

function rowActions(wrapper: Wrapper): RowAction<DashboardRow>[] {
    const actions = column(wrapper, 'actions').cellRendererParams?.actions;

    if (!Array.isArray(actions)) {
        throw new Error('The actions column carries no row actions');
    }

    return actions;
}

function action(wrapper: Wrapper, label: string): RowAction<DashboardRow> {
    const found = rowActions(wrapper).find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

function selectionCell(wrapper: Wrapper, data: DashboardRow): Wrapper {
    const renderer = columns(wrapper)[0].cellRenderer;

    return mount(renderer as Component, { props: { params: { data } } });
}

function activateRow(wrapper: Wrapper, data: DashboardRow): void {
    (
        wrapper.findComponent(DataGrid) as unknown as {
            vm: { $emit: (event: string, payload: DashboardRow) => void };
        }
    ).vm.$emit('row-activate', data);
}

function isRowActivatable(wrapper: Wrapper): (data: DashboardRow) => boolean {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'isRowActivatable'): (data: DashboardRow) => boolean;
        }
    ).props('isRowActivatable');
}

beforeEach(() => {
    deleteMock.mockReset();
    postMock.mockReset();
    visitMock.mockReset();
    pageState.props.auth.authority = null;
});

describe('dashboards/Index — grid', () => {
    it('feeds every dashboard to the grid', () => {
        const wrapper = mountIndex([row(), row({ id: 'second' })]);

        expect(
            (
                wrapper.findComponent(DataGrid) as unknown as {
                    props(key: 'rowData'): DashboardRow[];
                }
            ).props('rowData'),
        ).toHaveLength(2);
    });

    it('hides the grid entirely while there is not a single dashboard', () => {
        expect(mountIndex([]).findComponent(DataGrid).exists()).toBe(false);
    });

    it('keeps the page heading and the create button in the empty state', () => {
        const wrapper = mountIndex([]);

        expect(wrapper.find('[data-create-button]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Dashboards');
    });

    it('offers the create button as a link to the create route', () => {
        const wrapper = mountIndex();

        expect(wrapper.get('[data-create-button]').attributes('href')).toBe(
            DashboardsController.create.url(),
        );
    });
});

describe('dashboards/Index — the last change column', () => {
    it('renders the last change through the shared date formatter', () => {
        const wrapper = mountIndex();
        const formatter = column(wrapper, 'updated_at').valueFormatter;

        if (typeof formatter !== 'function') {
            throw new Error('The last change column must format its value');
        }

        const formatted = formatter({ value: row().updated_at } as never);

        expect(formatted).toBe(formatDateTime(row().updated_at));
        expect(formatted).not.toBe(row().updated_at);
        expect(formatter({ value: undefined } as never)).toBe(
            formatDateTime(null),
        );
    });

    it('carries the German header of the last change column', () => {
        expect(column(mountIndex(), 'updated_at').headerName).toBe(
            'Letzte Änderung',
        );
    });
});

describe('dashboards/Index — the tenant wide badge', () => {
    it('renders the visibility through the shared status map instead of a hard coded label', () => {
        const wrapper = mountIndex([row({ is_tenant_wide: true })]);

        expect(
            column(wrapper, 'visibility').cellRendererParams?.statusMap,
        ).toBe(DASHBOARD_VISIBILITY);
    });

    it('resolves a tenant wide dashboard and a private one to the two keys of that map', () => {
        const wrapper = mountIndex();
        const getter = valueGetterOf(wrapper, 'visibility');

        expect(getter({ data: row({ is_tenant_wide: true }) })).toBe(
            'tenant_wide',
        );
        expect(getter({ data: row({ is_tenant_wide: false }) })).toBe(
            'private',
        );
    });

    it('carries a German label for both keys the column can produce', () => {
        expect(Object.keys(DASHBOARD_VISIBILITY)).toEqual(
            expect.arrayContaining(['tenant_wide', 'private']),
        );
        expect(DASHBOARD_VISIBILITY.tenant_wide.label.length).toBeGreaterThan(
            3,
        );
        expect(DASHBOARD_VISIBILITY.private.label.length).toBeGreaterThan(3);
        expect(DASHBOARD_VISIBILITY.tenant_wide.label).not.toBe(
            DASHBOARD_VISIBILITY.private.label,
        );
        expect(DASHBOARD_VISIBILITY.tenant_wide.label).not.toMatch(
            ENGLISH_WORDS,
        );
    });
});

describe('dashboards/Index — opening a row', () => {
    it('sends the row click, the name link and the pencil to the very same detail page', async () => {
        const wrapper = mountIndex();

        const target = DashboardsController.show.url({ dashboard: row().id });
        const href = column(wrapper, 'name').cellRendererParams?.href;

        if (typeof href !== 'function') {
            throw new Error('The name column must render a link');
        }

        activateRow(wrapper, row());
        await nextTick();

        expect(href(row())).toBe(target);
        expect(action(wrapper, 'Öffnen').href?.(row())).toBe(target);
        expect(visitMock).toHaveBeenCalledTimes(1);
        expect(visitMock.mock.calls[0][0]).toBe(target);
    });

    it('never points a listed row at the edit page, which is reached from the detail page', () => {
        const wrapper = mountIndex();
        const href = column(wrapper, 'name').cellRendererParams?.href;

        if (typeof href !== 'function') {
            throw new Error('The name column must render a link');
        }

        expect(href(row())).not.toBe(
            DashboardsController.edit.url({ dashboard: row().id }),
        );
        expect(action(wrapper, 'Öffnen').href?.(row())).not.toBe(
            DashboardsController.edit.url({ dashboard: row().id }),
        );
    });

    it('activates every listed row, because the server already filtered by visibility', () => {
        const wrapper = mountIndex();
        const activatable = isRowActivatable(wrapper);

        expect(activatable(row())).toBe(true);
        expect(activatable(row({ can_update: false, can_delete: false }))).toBe(
            true,
        );
    });
});

describe('dashboards/Index — forbidden actions', () => {
    it('lines the actions up as open first and destructive delete last', () => {
        const wrapper = mountIndex();
        const actions = rowActions(wrapper);

        expect(actions.map((entry) => entry.label)).toEqual([
            'Öffnen',
            'Löschen',
        ]);
        expect(actions[0].variant).toBe('edit');
        expect(actions[1].variant).toBe('destructive');
    });

    it('leaves opening available even to a viewer who may not change anything', () => {
        const reader = row({
            can_update: false,
            update_reason: 'not_owner',
            is_owner: false,
        });
        const wrapper = mountIndex([reader]);

        expect(action(wrapper, 'Öffnen').isDisabled?.(reader) ?? false).toBe(
            false,
        );
        expect(action(wrapper, 'Öffnen').isVisible).toBeUndefined();
    });

    it('disables deleting with a German reason instead of hiding the action', () => {
        const blocked = row({
            can_delete: false,
            delete_reason: 'not_owner',
            is_owner: false,
        });
        const wrapper = mountIndex([blocked]);
        const remove = action(wrapper, 'Löschen');

        expect(remove.isVisible).toBeUndefined();
        expect(remove.isDisabled?.(blocked)).toBe(true);

        const reason = remove.disabledReason?.(blocked) ?? '';

        expect(reason.length).toBeGreaterThan(10);
        expect(reason).not.toMatch(ENGLISH_WORDS);
        expect(reason).not.toContain('not_owner');
    });

    it('tells the two refusal reasons of the resource apart', () => {
        const notOwner = row({ can_delete: false, delete_reason: 'not_owner' });
        const notVisible = row({
            can_delete: false,
            delete_reason: 'not_visible',
        });
        const wrapper = mountIndex([notOwner]);
        const remove = action(wrapper, 'Löschen');

        expect(remove.disabledReason?.(notOwner)).toBeDefined();
        expect(remove.disabledReason?.(notVisible)).toBeDefined();
        expect(remove.disabledReason?.(notOwner)).not.toBe(
            remove.disabledReason?.(notVisible),
        );
    });

    it('answers a reason it does not know with a fallback rather than an empty tooltip', () => {
        const unknown = row({
            can_delete: false,
            delete_reason: 'something_new_from_the_server',
        });
        const wrapper = mountIndex([unknown]);

        const reason = action(wrapper, 'Löschen').disabledReason?.(unknown);

        expect(reason).toBeDefined();
        expect(String(reason).length).toBeGreaterThan(10);
        expect(String(reason)).not.toContain('something_new_from_the_server');
    });

    it('carries no reason at all for a row the actor may delete', () => {
        const wrapper = mountIndex();
        const remove = action(wrapper, 'Löschen');

        expect(remove.isDisabled?.(row())).toBe(false);
        expect(remove.disabledReason?.(row())).toBeUndefined();
    });

    it('reads the permission off can_delete and ignores a stray can_edit flag', () => {
        const wrapper = mountIndex();
        const strayFlag = {
            ...row(),
            can_edit: false,
        } as unknown as DashboardRow;

        expect(action(wrapper, 'Löschen').isDisabled?.(strayFlag)).toBe(false);
        expect(isRowActivatable(wrapper)(strayFlag)).toBe(true);
    });
});

describe('dashboards/Index — the shared refusal resolver', () => {
    it('stays silent while the server named no reason', () => {
        expect(resolveDashboardActionRefusal(null)).toBeUndefined();
    });

    it('answers both reasons of the resource with a distinct German sentence', () => {
        const notOwner = resolveDashboardActionRefusal('not_owner');
        const notVisible = resolveDashboardActionRefusal('not_visible');

        expect(String(notOwner).length).toBeGreaterThan(10);
        expect(String(notVisible).length).toBeGreaterThan(10);
        expect(notOwner).not.toBe(notVisible);
        expect(String(notOwner)).not.toMatch(ENGLISH_WORDS);
        expect(String(notVisible)).not.toMatch(ENGLISH_WORDS);
        expect(String(notOwner)).not.toContain('_');
        expect(String(notVisible)).not.toContain('_');
    });

    it('answers any unknown reason with one and the same fallback', () => {
        const first = resolveDashboardActionRefusal('brand_new_reason');
        const second = resolveDashboardActionRefusal('another_new_reason');

        expect(first).toBeDefined();
        expect(first).toBe(second);
        expect(first).not.toBe(resolveDashboardActionRefusal('not_owner'));
        expect(first).not.toBe(resolveDashboardActionRefusal('not_visible'));
        expect(String(first)).not.toContain('_');
    });
});

describe('dashboards/Index — selection and bulk delete', () => {
    it('keeps a dashboard the actor may not delete out of the selection', () => {
        const blocked = row({ can_delete: false, delete_reason: 'not_owner' });
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
        await nextTick();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);
    });

    it('sends exactly the selected ids to the bulk delete endpoint', async () => {
        const second = row({ id: '01DASHBOARD00000000000002' });
        const wrapper = mountIndex([row(), second]);

        selectionCell(wrapper, second)
            .findComponent(Checkbox)
            .vm.$emit('update:modelValue', true);
        await nextTick();

        await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .find('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        expect(postMock).toHaveBeenCalledTimes(1);
        expect(postMock).toHaveBeenCalledWith(
            DashboardsController.bulkDestroy.url(),
            { ids: [second.id] },
            expect.anything(),
        );
    });

    it('prunes a selected row that the server no longer lists', async () => {
        const wrapper = mountIndex();

        selectionCell(wrapper, row())
            .findComponent(Checkbox)
            .vm.$emit('update:modelValue', true);
        await nextTick();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);

        await wrapper.setProps({
            dashboards: [row({ id: '01DASHBOARD00000000000009' })],
        });
        await nextTick();

        expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(
            false,
        );
    });
});

describe('dashboards/Index — deleting', () => {
    it('asks before deleting and never deletes straight away', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await nextTick();

        expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
        expect(deleteMock).not.toHaveBeenCalled();
    });

    it('labels the confirmation in German and marks it as destructive', () => {
        const wrapper = mountIndex();
        const dialog = wrapper.findComponent(ConfirmDialog);

        expect(dialog.props('confirmLabel')).toBe('Löschen');
        expect(dialog.props('variant')).toBe('destructive');
    });

    it('deletes through the wayfinder route exactly once after the confirmation', async () => {
        const wrapper = mountIndex();

        action(wrapper, 'Löschen').onClick?.(row());
        await nextTick();

        wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
        await nextTick();

        expect(deleteMock).toHaveBeenCalledTimes(1);
        expect(deleteMock.mock.calls[0][0]).toBe(
            DashboardsController.destroy.url({ dashboard: row().id }),
        );
    });
});
