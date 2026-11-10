import { flushPromises, mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ViewStates from '@/components/engine/ViewStates.vue';
import type { NotificationRuleItem } from '@/composables/useRules';
import Index from '@/pages/notificationRules/Index.vue';
import type { RowAction } from '@/types/rowAction';

const { toggleMock, pageState } = vi.hoisted(() => ({
    toggleMock: vi.fn(),
    pageState: {
        url: '/settings/notification-rules/companies',
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
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    usePage: () => pageState,
}));

vi.mock('@/composables/useRules', () => ({
    useRules: () => ({ toggleActive: toggleMock }),
}));

interface RuleRow {
    id: string;
    name: string;
    trigger: string;
    state: 'active' | 'inactive';
}

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    Dialog: passthrough,
    DialogContent: passthrough,
    DialogHeader: passthrough,
    DialogTitle: passthrough,
    RuleEditor: { template: '<div data-rule-editor />' },
};

const objectType = {
    id: 'ot-1',
    key: 'companies',
    slug: 'companies',
    name: 'Firmen',
    requiresDeletionReason: false,
    hasHierarchy: false,
};

const rule: NotificationRuleItem = {
    id: '01RULE0000000000000000001',
    name: 'Wiedervorlage fällig',
    trigger_type: 'date_based',
    is_active: true,
};

const mounted: Wrapper[] = [];

function mountIndex(
    rules: NotificationRuleItem[] = [rule],
    granted: string[] = ['companies.rules.manage'],
): Wrapper {
    pageState.props.auth.can = Object.fromEntries(
        granted.map((name) => [name, true]),
    );

    const wrapper = mount(Index, {
        props: { objectType, rules },
        global: { stubs },
    });

    mounted.push(wrapper);

    return wrapper;
}

function columns(wrapper: Wrapper): ColDef<RuleRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<RuleRow>[];
        }
    ).props('columnDefs');
}

function rows(wrapper: Wrapper): RuleRow[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'rowData'): RuleRow[];
        }
    ).props('rowData');
}

function action(wrapper: Wrapper, label: string): RowAction<RuleRow> {
    const actions = columns(wrapper).find(
        (column) => column.colId === 'actions',
    )?.cellRendererParams?.actions as RowAction<RuleRow>[];

    const found = actions.find((entry) => entry.label === label);

    if (found === undefined) {
        throw new Error(`Action "${label}" not found`);
    }

    return found;
}

beforeEach(() => {
    toggleMock.mockReset();
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
});

describe('notificationRules/Index', () => {
    it('hides the grid and offers the first rule in the empty state', () => {
        const wrapper = mountIndex([]);

        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
        expect(wrapper.findComponent(ViewStates).props('state')).toBe('empty');
        expect(wrapper.text()).toContain(
            'Es sind noch keine Regeln vorhanden.',
        );
    });

    it('lists each rule with its trigger and active state in the grid', () => {
        const [first] = rows(mountIndex());

        expect(first.name).toBe('Wiedervorlage fällig');
        expect(first.trigger).toBe('Termin');
        expect(first.state).toBe('active');
    });

    it('orders the row actions edit before the state switch', () => {
        const wrapper = mountIndex();
        const actions = columns(wrapper).find(
            (column) => column.colId === 'actions',
        )?.cellRendererParams?.actions as RowAction<RuleRow>[];

        expect(actions.map((entry) => entry.testId)).toEqual([
            'rule-edit',
            'rule-enable',
            'rule-disable',
        ]);
    });

    it('opens the editor when a row is activated', async () => {
        const wrapper = mountIndex();

        wrapper
            .findComponent({ name: 'DataGrid' })
            .vm.$emit('row-activate', rows(wrapper)[0]);
        await flushPromises();

        expect(wrapper.find('[data-rule-editor]').exists()).toBe(true);
    });

    it('deactivates an active rule and shows it as inactive', async () => {
        toggleMock.mockResolvedValue({ ...rule, is_active: false });
        const wrapper = mountIndex();
        const row = rows(wrapper)[0];

        expect(action(wrapper, 'Aktivieren').isVisible?.(row)).toBe(false);
        action(wrapper, 'Deaktivieren').onClick?.(row);
        await flushPromises();

        expect(toggleMock).toHaveBeenCalledWith(rule.id, false);
        expect(rows(wrapper)[0].state).toBe('inactive');
    });

    it('disables every row action with a reason and blocks row activation without the permission', () => {
        const wrapper = mountIndex([rule], []);
        const row = rows(wrapper)[0];
        const grid = wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'isRowActivatable'): (row: RuleRow) => boolean;
        };

        expect(action(wrapper, 'Bearbeiten').isDisabled?.(row)).toBe(true);
        expect(action(wrapper, 'Deaktivieren').disabledReason?.(row)).toBe(
            'Keine Berechtigung',
        );
        expect(grid.props('isRowActivatable')(row)).toBe(false);
        expect(wrapper.find('[data-create-button]').exists()).toBe(false);
    });
});
