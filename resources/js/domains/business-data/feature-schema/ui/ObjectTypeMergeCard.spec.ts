import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import MergeRulesController from '@/actions/App/Http/Controllers/Engine/MergeRulesController';
import type { MergeRuleRow } from '@/domains/business-data/data/types/merge';
import { MERGE_RULE_REFUSAL_LABELS } from '@/domains/business-data/data/types/merge';
import {
  gridColumnByHeader,
  gridOf,
  rowActionByTestId,
  rowActions,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import { t } from '@/shared/testing/i18n';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import { setUrlDefaults } from '@/wayfinder';
import ObjectTypeMergeCard from './ObjectTypeMergeCard.vue';

const mergeInertia = vi.hoisted(() => ({
  post: vi.fn(),
  put: vi.fn(),
  destroy: vi.fn(),
  visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
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

function mergeCellText(column: Record<string, unknown>, row: MergeRuleRow): string {
  const params = {
    data: row,
    value: row[(column.field ?? '') as keyof MergeRuleRow],
  } as never;

  const getter = column.valueGetter;

  if (typeof getter === 'function') {
    return String(getter(params));
  }

  if (column.field !== undefined) {
    return String(row[column.field as keyof MergeRuleRow]);
  }

  throw new Error(`Column "${String(column.headerName)}" renders no text`);
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
    expect(wrapper.get('[data-create-button]').text()).toContain(MERGE_CREATE_LABEL);
  });

  it('explains the empty state instead of showing a grid', () => {
    const wrapper = mountMergeCard({ rules: [] });

    expect(wrapper.text()).toContain(MERGE_EMPTY_TITLE);
    expect(gridOf(wrapper).exists()).toBe(false);
  });

  it('offers no create path at all without the right', () => {
    expect(mountMergeCard({ canCreate: false }).find('[data-create-button]').exists()).toBe(false);
    expect(mountMergeCard({ rules: [], canCreate: false }).find('[data-create-button]').exists()).toBe(false);
  });
});

describe('ObjectTypeMergeCard — the grid', () => {
  it('reads the mode of the rule for the badge', () => {
    const wrapper = mountMergeCard();

    expect(mergeCellText(gridColumnByHeader(wrapper, 'Wirkung'), mergeRule({ mode: 'deny' }))).toBe('deny');
  });

  it('separates a rule without a condition from a narrowed one', () => {
    const wrapper = mountMergeCard();
    const column = gridColumnByHeader(wrapper, 'Gilt für');

    expect(mergeCellText(column, mergeRule({ condition: [] }))).toBe('Alle Datensätze');
    expect(mergeCellText(column, mergeRule({ condition: null }))).toBe('Alle Datensätze');
    expect(mergeCellText(column, mergeRule({ condition: { combinator: 'and' } }))).toBe('Eingeschränkt');
  });

  it('keeps delete as the outermost row action', () => {
    expect(rowActions<MergeRuleRow>(mountMergeCard()).map((entry) => entry.testId)).toEqual([
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

    const action = rowActionByTestId<MergeRuleRow>(wrapper, 'merge-rule-edit');
    const row = mergeRule({
      can_update: false,
      update_reason: 'not_permitted',
    });

    expect(action.isDisabled?.(row)).toBe(true);
    expect(action.disabledReason?.(row)).toBe(t(MERGE_RULE_REFUSAL_LABELS.not_permitted));
  });
});

describe('ObjectTypeMergeCard — writing', () => {
  it('sends the user to the create page instead of a slide-out', async () => {
    const wrapper = mountMergeCard({ rules: [] });

    await wrapper.get('[data-create-button]').trigger('click');

    expect(String(lastCall(mergeInertia.visit)[0])).toBe(MergeRulesController.create.url({ objectType: MERGE_SLUG }));
  });

  it('opens the edit page of the row that was activated', async () => {
    const rule = mergeRule();
    const wrapper = mountMergeCard({ rules: [rule] });

    rowActionByTestId<MergeRuleRow>(wrapper, 'merge-rule-edit').onClick?.(rule);
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

    rowActionByTestId<MergeRuleRow>(wrapper, 'merge-rule-delete').onClick?.(rule);
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

    await selectRow(wrapper, rule);
    await wrapper.get('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.get('[data-testid="bulk-delete-confirm"]').trigger('click');

    const [url, payload] = lastCall(mergeInertia.post);

    expect(url).toBe(MergeRulesController.bulkDestroy.url({ objectType: MERGE_SLUG }));
    expect(payload).toEqual({ ids: [rule.id] });
  });

  it('keeps a rule the server blocked from deletion out of the selection', () => {
    const blocked = mergeRule({ can_delete: false });
    const wrapper = mountMergeCard({ rules: [blocked] });
    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
    expect(selectionCheckbox(wrapper, mergeRule()).exists()).toBe(true);
  });
});
