import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import type { ColDef } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { nextTick } from 'vue';
import MergeRulesController from '@/actions/App/Http/Controllers/Engine/MergeRulesController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import ObjectTypeMergeCard from '@/components/engine/objectType/ObjectTypeMergeCard.vue';
import { Checkbox } from '@/components/ui/checkbox';
import type { MergeRuleRow } from '@/types/merge';
import { MERGE_RULE_REFUSAL_LABELS } from '@/types/merge';
import type { RowAction } from '@/types/rowAction';
import { setUrlDefaults } from '@/wayfinder';

const mergeInertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    destroy: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: {
        on: vi.fn(() => vi.fn()),
        visit: mergeInertia.visit,
        post: mergeInertia.post,
        put: mergeInertia.put,
        delete: mergeInertia.destroy,
    },
    usePage: () => ({
        url: '/nubos/engine/object-types/deals/edit',
        props: { auth: { user: null, can: {}, authority: null } },
    }),
}));

const MERGE_SLUG = 'deals';

const MERGE_EMPTY_TITLE = 'Noch keine Merge-Regeln';

const MERGE_CREATE_LABEL = 'Merge-Regel anlegen';

const MERGE_TITLE = 'Merge-Regeln';

function mergeRule(overrides: Partial<MergeRuleRow> = {}): MergeRuleRow {
    return {
        id: '01MERGERULE00000000000001',
        object_type_id: '01OBJECTTYPE000000000001',
        name: 'Standard',
        mode: 'allow',
        position: 10,
        is_active: true,
        deny_reason: null,
        condition: [],
        field_strategies: {},
        transfer_policy: {},
        options: {},
        can_update: true,
        can_delete: true,
        update_reason: null,
        delete_reason: null,
        updated_at: '2026-08-26T10:00:00+00:00',
        ...overrides,
    };
}

interface MergeCardOptions {
    rules?: MergeRuleRow[];
    canCreate?: boolean;
}

function mountMergeCard(options: MergeCardOptions = {}): VueWrapper {
    return mount(ObjectTypeMergeCard, {
        props: {
            objectTypeSlug: MERGE_SLUG,
            rules: options.rules ?? [mergeRule()],
            canCreate: options.canCreate ?? true,
        },
    });
}

function mergeColumns(wrapper: VueWrapper): ColDef<MergeRuleRow>[] {
    return (
        wrapper.findComponent(DataGrid) as unknown as {
            props(key: 'columnDefs'): ColDef<MergeRuleRow>[];
        }
    ).props('columnDefs');
}

function mergeColumn(
    wrapper: VueWrapper,
    headerName: string,
): ColDef<MergeRuleRow> {
    const found = mergeColumns(wrapper).find(
        (column) => column.headerName === headerName,
    );

    if (found === undefined) {
        throw new Error(`Column "${headerName}" not found`);
    }

    return found;
}

function mergeCellText(
    column: ColDef<MergeRuleRow>,
    row: MergeRuleRow,
): string {
    const params = {
        data: row,
        value: row[(column.field ?? '') as keyof MergeRuleRow],
    } as never;

    if (typeof column.valueGetter === 'function') {
        return String(column.valueGetter(params));
    }

    if (column.field !== undefined) {
        return String(row[column.field as keyof MergeRuleRow]);
    }

    throw new Error(`Column "${column.headerName}" renders no text`);
}

function mergeAction(
    wrapper: VueWrapper,
    testId: string,
): RowAction<MergeRuleRow> {
    const actions = mergeColumns(wrapper).find(
        (column) => column.colId === 'actions',
    )?.cellRendererParams?.actions as RowAction<MergeRuleRow>[] | undefined;

    const found = actions?.find((entry) => entry.testId === testId);

    if (found === undefined) {
        throw new Error(`Row action "${testId}" not found`);
    }

    return found;
}

function mergeActionOrder(wrapper: VueWrapper): (string | undefined)[] {
    const actions = mergeColumns(wrapper).find(
        (column) => column.colId === 'actions',
    )?.cellRendererParams?.actions as RowAction<MergeRuleRow>[];

    return actions.map((entry) => entry.testId);
}

async function selectMergeRow(
    wrapper: VueWrapper,
    row: MergeRuleRow,
): Promise<void> {
    const cell = mount(mergeColumns(wrapper)[0].cellRenderer as Component, {
        props: { params: { data: row } },
    });

    cell.findComponent(Checkbox).vm.$emit('update:modelValue', true);
    await nextTick();
    cell.unmount();
}

function lastCall(spy: ReturnType<typeof vi.fn>): unknown[] {
    const calls = spy.mock.calls;

    if (calls.length === 0) {
        throw new Error('the router was never called');
    }

    return calls[calls.length - 1] as unknown[];
}

beforeEach(() => {
    setUrlDefaults({ activeTeam: 'nubos' });
    mergeInertia.post.mockReset();
    mergeInertia.put.mockReset();
    mergeInertia.destroy.mockReset();
    mergeInertia.visit.mockReset();
});

describe('ObjectTypeMergeCard — the card head', () => {
    it('carries its own title and its own create button', () => {
        const wrapper = mountMergeCard();

        expect(wrapper.text()).toContain(MERGE_TITLE);
        expect(wrapper.get('[data-create-button]').text()).toContain(
            MERGE_CREATE_LABEL,
        );
    });

    it('explains the empty state instead of showing a grid', () => {
        const wrapper = mountMergeCard({ rules: [] });

        expect(wrapper.text()).toContain(MERGE_EMPTY_TITLE);
        expect(wrapper.findComponent(DataGrid).exists()).toBe(false);
    });

    it('stretches the empty state over the whole card instead of a clipped strip', () => {
        const classes = mountMergeCard({ rules: [] })
            .get('[data-merge-empty]')
            .classes();

        expect(classes).toContain('flex-1');
        expect(classes).toContain('flex');
        expect(classes).toContain('flex-col');
    });

    it('offers no create path at all without the right', () => {
        expect(
            mountMergeCard({ canCreate: false })
                .find('[data-create-button]')
                .exists(),
        ).toBe(false);
        expect(
            mountMergeCard({ rules: [], canCreate: false })
                .find('[data-create-button]')
                .exists(),
        ).toBe(false);
    });
});

describe('ObjectTypeMergeCard — the grid', () => {
    it('shows the order the rules are checked in', () => {
        const wrapper = mountMergeCard();

        expect(
            mergeCellText(mergeColumn(wrapper, 'Reihenfolge'), mergeRule()),
        ).toBe('10');
    });

    it('reads the mode of the rule for the badge', () => {
        const wrapper = mountMergeCard();

        expect(
            mergeCellText(
                mergeColumn(wrapper, 'Wirkung'),
                mergeRule({ mode: 'deny' }),
            ),
        ).toBe('deny');
    });

    it('separates a rule without a condition from a narrowed one', () => {
        const wrapper = mountMergeCard();
        const column = mergeColumn(wrapper, 'Gilt für');

        expect(mergeCellText(column, mergeRule({ condition: [] }))).toBe(
            'Alle Datensätze',
        );
        expect(mergeCellText(column, mergeRule({ condition: null }))).toBe(
            'Alle Datensätze',
        );
        expect(
            mergeCellText(
                column,
                mergeRule({ condition: { combinator: 'and' } }),
            ),
        ).toBe('Eingeschränkt');
    });

    it('keeps delete as the outermost row action', () => {
        expect(mergeActionOrder(mountMergeCard())).toEqual([
            'merge-rule-edit',
            'merge-rule-delete',
        ]);
    });

    it('disables a forbidden action with the reason instead of hiding it', () => {
        const wrapper = mountMergeCard({
            rules: [
                mergeRule({
                    can_update: false,
                    update_reason: 'not_permitted',
                }),
            ],
        });

        const action = mergeAction(wrapper, 'merge-rule-edit');
        const row = mergeRule({
            can_update: false,
            update_reason: 'not_permitted',
        });

        expect(action.isDisabled?.(row)).toBe(true);
        expect(action.disabledReason?.(row)).toBe(
            MERGE_RULE_REFUSAL_LABELS.not_permitted,
        );
    });
});

describe('ObjectTypeMergeCard — writing', () => {
    it('sends the user to the create page instead of a slide-out', async () => {
        const wrapper = mountMergeCard({ rules: [] });

        await wrapper.get('[data-create-button]').trigger('click');

        expect(String(lastCall(mergeInertia.visit)[0])).toBe(
            MergeRulesController.create.url({ objectType: MERGE_SLUG }),
        );
    });

    it('opens the edit page of the row that was activated', async () => {
        const rule = mergeRule();
        const wrapper = mountMergeCard({ rules: [rule] });

        mergeAction(wrapper, 'merge-rule-edit').onClick?.(rule);
        await nextTick();

        expect(String(lastCall(mergeInertia.visit)[0])).toBe(
            MergeRulesController.edit.url({
                objectType: MERGE_SLUG,
                mergeRule: rule.id,
            }),
        );
    });

    it('asks before it deletes and then calls the destroy route', async () => {
        const rule = mergeRule();
        const wrapper = mountMergeCard({ rules: [rule] });

        mergeAction(wrapper, 'merge-rule-delete').onClick?.(rule);
        await nextTick();

        const dialog = wrapper.findComponent(ConfirmDialog);

        expect(dialog.props('open')).toBe(true);
        expect(dialog.props('description')).toContain(rule.name);

        dialog.vm.$emit('confirm');
        await nextTick();

        expect(String(lastCall(mergeInertia.destroy)[0])).toBe(
            MergeRulesController.destroy.url({
                objectType: MERGE_SLUG,
                mergeRule: rule.id,
            }),
        );
    });

    it('sends the selected ids to the bulk delete route', async () => {
        const rule = mergeRule();
        const wrapper = mountMergeCard({ rules: [rule] });

        await selectMergeRow(wrapper, rule);
        await wrapper.get('[data-testid="bulk-delete"]').trigger('click');
        await wrapper
            .get('[data-testid="bulk-delete-confirm"]')
            .trigger('click');

        const [url, payload] = lastCall(mergeInertia.post);

        expect(url).toBe(
            MergeRulesController.bulkDestroy.url({ objectType: MERGE_SLUG }),
        );
        expect(payload).toEqual({ ids: [rule.id] });
    });

    it('keeps a rule the server blocked from deletion out of the selection', () => {
        const blocked = mergeRule({ can_delete: false });
        const wrapper = mountMergeCard({ rules: [blocked] });
        const renderer = mergeColumns(wrapper)[0].cellRenderer as Component;

        const blockedCell = mount(renderer, {
            props: { params: { data: blocked } },
        });
        const selectableCell = mount(renderer, {
            props: { params: { data: mergeRule() } },
        });

        expect(blockedCell.findComponent(Checkbox).exists()).toBe(false);
        expect(selectableCell.findComponent(Checkbox).exists()).toBe(true);
    });

    it('fills the height it is given instead of growing with the rows', () => {
        const grid = mountMergeCard().findComponent(DataGrid) as unknown as {
            props(key: 'domLayout'): string;
            classes(): string[];
        };

        expect(grid.props('domLayout')).toBe('normal');
        expect(grid.classes()).toContain('h-full');
    });
});
