import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { isRecord } from '@/shared/lib/guards';
import { activateRow, gridOf, gridRows, rowAction, rowActions, rowActivation } from '@/shared/testing/dataGrid';
import ViewStates from '@/shared/ui/ViewStates.vue';
import type { NotificationRuleItem } from '../../data/useRules';
import Index from './Index.vue';

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

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
  usePage: () => pageState,
}));

vi.mock('../../data/useRules', () => ({
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
  label: 'Firmen',
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

function mountIndex(rules: NotificationRuleItem[] = [rule], granted: string[] = ['companies.rules.manage']): Wrapper {
  pageState.props.auth.can = Object.fromEntries(granted.map((name) => [name, true]));

  const wrapper = mount(Index, {
    props: { objectType, rules },
    global: { stubs },
  });

  mounted.push(wrapper);

  return wrapper;
}

function isRuleRow(value: unknown): value is RuleRow {
  return (
    isRecord(value) &&
    typeof value.id === 'string' &&
    typeof value.name === 'string' &&
    typeof value.trigger === 'string' &&
    (value.state === 'active' || value.state === 'inactive')
  );
}

function rows(wrapper: Wrapper): RuleRow[] {
  return gridRows(wrapper).filter(isRuleRow);
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

    expect(gridOf(wrapper).exists()).toBe(false);
    expect(wrapper.findComponent(ViewStates).props('state')).toBe('empty');
    expect(wrapper.text()).toContain('Es sind noch keine Regeln vorhanden.');
  });

  it('lists each rule with its trigger and active state in the grid', () => {
    const [first] = rows(mountIndex());

    expect(first.name).toBe('Wiedervorlage fällig');
    expect(first.trigger).toBe('Termin');
    expect(first.state).toBe('active');
  });

  it('orders the row actions edit before the state switch', () => {
    const wrapper = mountIndex();
    const actions = rowActions<RuleRow>(wrapper);

    expect(actions.map((entry) => entry.testId)).toEqual(['rule-edit', 'rule-enable', 'rule-disable']);
  });

  it('opens the editor when a row is activated', async () => {
    const wrapper = mountIndex();

    activateRow(wrapper, rows(wrapper)[0]);
    await flushPromises();

    expect(wrapper.find('[data-rule-editor]').exists()).toBe(true);
  });

  it('deactivates an active rule and shows it as inactive', async () => {
    toggleMock.mockResolvedValue({ ...rule, is_active: false });
    const wrapper = mountIndex();
    const row = rows(wrapper)[0];

    expect(rowAction<RuleRow>(wrapper, 'Aktivieren').isVisible?.(row)).toBe(false);
    rowAction<RuleRow>(wrapper, 'Deaktivieren').onClick?.(row);
    await flushPromises();

    expect(toggleMock).toHaveBeenCalledWith(rule.id, false);
    expect(rows(wrapper)[0].state).toBe('inactive');
  });

  it('disables every row action with a reason and blocks row activation without the permission', () => {
    const wrapper = mountIndex([rule], []);
    const row = rows(wrapper)[0];

    expect(rowAction<RuleRow>(wrapper, 'Bearbeiten').isDisabled?.(row)).toBe(true);
    expect(rowAction<RuleRow>(wrapper, 'Deaktivieren').disabledReason?.(row)).toBe('Keine Berechtigung');
    expect(rowActivation<RuleRow>(wrapper)(row)).toBe(false);
    expect(wrapper.find('[data-create-button]').exists()).toBe(false);
  });
});
