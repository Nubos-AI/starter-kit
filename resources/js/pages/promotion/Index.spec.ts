import { mount } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Index from '@/pages/promotion/Index.vue';
import type { PromotionRunRow } from '@/types/promotion';
import type { RowAction } from '@/types/rowAction';

const { pageState } = vi.hoisted(() => ({
    pageState: {
        url: '/nubos/engine/promotions',
        props: { errors: {} as Record<string, string> },
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn(), post: vi.fn() },
    usePage: () => pageState,
}));

type Wrapper = ReturnType<typeof mount>;

const passthrough = { template: '<div><slot /></div>' };

const stubs = {
    TooltipProvider: passthrough,
    Tooltip: passthrough,
    TooltipTrigger: passthrough,
    TooltipContent: {
        template: '<div data-reasoned-button-reason><slot /></div>',
    },
};

const mounted: Wrapper[] = [];

function run(overrides: Partial<PromotionRunRow> = {}): PromotionRunRow {
    return {
        id: '01PROMOTIONRUN000000000001',
        direction: 'push',
        direction_label: 'Quelle → Live',
        counterpart_label: 'Sandbox',
        status: 'applied',
        artifact_count: 3,
        triggered_by_name: 'Ada',
        created_at: '2026-09-05T12:00:00.000Z',
        can_view: true,
        can_update: false,
        update_reason: null,
        can_rollback: true,
        rollback_reason: null,
        ...overrides,
    };
}

function mountIndex(props: Record<string, unknown> = {}): Wrapper {
    const wrapper = mount(Index, {
        props: {
            runs: [run()],
            can_create: true,
            create_reason: null,
            ...props,
        },
        global: { stubs },
    });

    mounted.push(wrapper);

    return wrapper;
}

function isActionList(value: unknown): value is RowAction<PromotionRunRow>[] {
    return Array.isArray(value);
}

function rowActions(wrapper: Wrapper): RowAction<PromotionRunRow>[] {
    const columnDefs: unknown = wrapper
        .findComponent({ name: 'DataGrid' })
        .props('columnDefs');

    if (!Array.isArray(columnDefs)) {
        throw new Error('data grid has no columns');
    }

    const actionColumn = columnDefs.find(
        (entry: ColDef<PromotionRunRow>) => entry.colId === 'actions',
    );
    const params: unknown = actionColumn?.cellRendererParams;

    if (
        typeof params !== 'object' ||
        params === null ||
        !('actions' in params) ||
        !isActionList(params.actions)
    ) {
        throw new Error('actions column has no actions');
    }

    return params.actions;
}

beforeEach(() => {
    pageState.props.errors = {};
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('promotion index', () => {
    it('shows the create button disabled with the server reason when creation is refused', () => {
        const wrapper = mountIndex({
            can_create: false,
            create_reason: 'Keine Gegenstelle verbunden.',
        });

        const button = wrapper.find('[data-promotion-create-reason]');

        expect(button.exists()).toBe(true);
        expect(button.attributes('disabled')).toBeDefined();
        expect(wrapper.find('[data-reasoned-button-reason]').text()).toBe(
            'Keine Gegenstelle verbunden.',
        );
    });

    it('renders the create link when creation is allowed', () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-promotion-create-reason]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-create-button]').exists()).toBe(true);
    });

    it('marks the rollback action as a warning, not as destructive', () => {
        const wrapper = mountIndex();

        const rollback = rowActions(wrapper).find(
            (action) => action.testId === 'promotion-rollback',
        );

        expect(rollback?.variant).toBe('warning');
    });

    it('surfaces a failed rollback as a destructive alert', () => {
        pageState.props.errors = { rollback: 'Sicherung fehlt.' };

        const wrapper = mountIndex();

        const alert = wrapper.find('[data-promotion-rollback-error]');

        expect(alert.exists()).toBe(true);
        expect(alert.text()).toContain('Sicherung fehlt.');
    });

    it('shows no rollback alert without an error', () => {
        const wrapper = mountIndex();

        expect(wrapper.find('[data-promotion-rollback-error]').exists()).toBe(
            false,
        );
    });
});
