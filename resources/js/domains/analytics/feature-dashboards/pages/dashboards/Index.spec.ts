import type { VueWrapper } from '@vue/test-utils';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import { resolveActionRefusal } from '@/shared/lib/actionRefusal';
import { formatDateTime } from '@/shared/lib/formatDate';
import {
  activateRow,
  columnParams,
  columnValue,
  gridColumn,
  gridOf,
  gridRows,
  rowAction,
  rowActions,
  rowActivation,
  selectRow,
  selectionCheckbox,
} from '@/shared/testing/dataGrid';
import { t } from '@/shared/testing/i18n';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import type { DashboardRow } from '../../../data/types/dashboards';
import { dashboardActionRefusals } from '../../../data/types/dashboards';
import Index from './Index.vue';

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

vi.mock('@inertiajs/vue3', async () => ({
  ...(await import('@/shared/testing/inertia')).inertiaStubs,
  router: {
    on: vi.fn(() => vi.fn()),
    visit: visitMock,
    post: postMock,
    delete: deleteMock,
  },
  usePage: () => pageState,
}));

type Wrapper = VueWrapper;

const ENGLISH_WORDS = /\b(the|this|that|you|your|only|cannot|owner|dashboard\s+is)\b/i;

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
    is_system: false,
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

beforeEach(() => {
  deleteMock.mockReset();
  postMock.mockReset();
  visitMock.mockReset();
  pageState.props.auth.authority = null;
});

describe('dashboards/Index — grid', () => {
  it('feeds every dashboard to the grid', () => {
    const wrapper = mountIndex([row(), row({ id: 'second' })]);

    expect(gridRows(wrapper)).toHaveLength(2);
  });

  it('hides the grid entirely while there is not a single dashboard', () => {
    expect(gridOf(mountIndex([])).exists()).toBe(false);
  });

  it('offers the create button as a link to the create route', () => {
    const wrapper = mountIndex();

    expect(wrapper.get('[data-create-button]').attributes('href')).toBe(DashboardsController.create.url());
  });
});

describe('dashboards/Index — the last change column', () => {
  it('renders the last change through the shared date formatter', () => {
    const wrapper = mountIndex();
    const formatter = gridColumn(wrapper, 'updated_at').valueFormatter;

    if (typeof formatter !== 'function') {
      throw new Error('The last change column must format its value');
    }

    const formatted = formatter({ value: row().updated_at } as never);

    expect(formatted).toBe(formatDateTime(row().updated_at));
    expect(formatted).not.toBe(row().updated_at);
    expect(formatter({ value: undefined } as never)).toBe(formatDateTime(null));
  });
});

describe('dashboards/Index — the tenant wide badge', () => {
  it('resolves a tenant wide dashboard and a private one to the two keys of that map', () => {
    const wrapper = mountIndex();

    expect(columnValue(wrapper, 'visibility', row({ is_tenant_wide: true }))).toBe('tenant_wide');
    expect(columnValue(wrapper, 'visibility', row({ is_tenant_wide: false }))).toBe('private');
  });
});

describe('dashboards/Index — opening a row', () => {
  it('sends the row click, the name link and the pencil to the very same detail page', async () => {
    const wrapper = mountIndex();

    const target = DashboardsController.show.url({ dashboard: row().id });
    const href = columnParams(wrapper, 'name').href;

    if (typeof href !== 'function') {
      throw new Error('The name column must render a link');
    }

    activateRow(wrapper, row());
    await nextTick();

    expect(href(row())).toBe(target);
    expect(rowAction<DashboardRow>(wrapper, 'Öffnen').href?.(row())).toBe(target);
    expect(visitMock).toHaveBeenCalledTimes(1);
    expect(visitMock.mock.calls[0][0]).toBe(target);
  });

  it('never points a listed row at the edit page, which is reached from the detail page', () => {
    const wrapper = mountIndex();
    const href = columnParams(wrapper, 'name').href;

    if (typeof href !== 'function') {
      throw new Error('The name column must render a link');
    }

    expect(href(row())).not.toBe(DashboardsController.edit.url({ dashboard: row().id }));
    expect(rowAction<DashboardRow>(wrapper, 'Öffnen').href?.(row())).not.toBe(
      DashboardsController.edit.url({ dashboard: row().id }),
    );
  });

  it('activates every listed row, because the server already filtered by visibility', () => {
    const wrapper = mountIndex();
    const activatable = rowActivation<DashboardRow>(wrapper);

    expect(activatable(row())).toBe(true);
    expect(activatable(row({ can_update: false, can_delete: false }))).toBe(true);
  });
});

describe('dashboards/Index — forbidden actions', () => {
  it('lines the actions up as open first and destructive delete last', () => {
    const wrapper = mountIndex();
    const actions = rowActions<DashboardRow>(wrapper);

    expect(actions.map((entry) => entry.label)).toEqual(['Öffnen', 'Löschen']);
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

    expect(rowAction<DashboardRow>(wrapper, 'Öffnen').isDisabled?.(reader) ?? false).toBe(false);
    expect(rowAction<DashboardRow>(wrapper, 'Öffnen').isVisible).toBeUndefined();
  });

  it('disables deleting with a German reason instead of hiding the action', () => {
    const blocked = row({
      can_delete: false,
      delete_reason: 'not_owner',
      is_owner: false,
    });
    const wrapper = mountIndex([blocked]);
    const remove = rowAction<DashboardRow>(wrapper, 'Löschen');

    expect(remove.isVisible).toBeUndefined();
    expect(remove.isDisabled?.(blocked)).toBe(true);

    const reason = remove.disabledReason?.(blocked) ?? '';

    expect(reason.length).toBeGreaterThan(10);
    expect(reason).not.toMatch(ENGLISH_WORDS);
    expect(reason).not.toContain('not_owner');
  });

  it('carries no reason at all for a row the actor may delete', () => {
    const wrapper = mountIndex();
    const remove = rowAction<DashboardRow>(wrapper, 'Löschen');

    expect(remove.isDisabled?.(row())).toBe(false);
    expect(remove.disabledReason?.(row())).toBeUndefined();
  });

  it('reads the permission off can_delete and ignores a stray can_edit flag', () => {
    const wrapper = mountIndex();
    const strayFlag = {
      ...row(),
      can_edit: false,
    } as unknown as DashboardRow;

    expect(rowAction<DashboardRow>(wrapper, 'Löschen').isDisabled?.(strayFlag)).toBe(false);
    expect(rowActivation<DashboardRow>(wrapper)(strayFlag)).toBe(true);
  });
});

describe('dashboards/Index — the shared refusal resolver', () => {
  it('stays silent while the server named no reason', () => {
    expect(resolveActionRefusal(null, dashboardActionRefusals, t)).toBeUndefined();
  });

  it('answers both reasons of the resource with a distinct German sentence', () => {
    const notOwner = resolveActionRefusal('not_owner', dashboardActionRefusals, t);
    const notVisible = resolveActionRefusal('not_visible', dashboardActionRefusals, t);

    expect(String(notOwner).length).toBeGreaterThan(10);
    expect(String(notVisible).length).toBeGreaterThan(10);
    expect(notOwner).not.toBe(notVisible);
    expect(String(notOwner)).not.toMatch(ENGLISH_WORDS);
    expect(String(notVisible)).not.toMatch(ENGLISH_WORDS);
    expect(String(notOwner)).not.toContain('_');
    expect(String(notVisible)).not.toContain('_');
  });

  it('explains in German that a shipped dashboard cannot be deleted', () => {
    const system = resolveActionRefusal('system', dashboardActionRefusals, t);

    expect(system).toBe('Mitgelieferte Dashboards können nicht gelöscht werden.');
    expect(system).not.toBe(resolveActionRefusal('brand_new', dashboardActionRefusals, t));
  });

  it('answers any unknown reason with one and the same fallback', () => {
    const first = resolveActionRefusal('brand_new_reason', dashboardActionRefusals, t);
    const second = resolveActionRefusal('another_new_reason', dashboardActionRefusals, t);

    expect(first).toBeDefined();
    expect(first).toBe(second);
    expect(first).not.toBe(resolveActionRefusal('not_owner', dashboardActionRefusals, t));
    expect(first).not.toBe(resolveActionRefusal('not_visible', dashboardActionRefusals, t));
    expect(String(first)).not.toContain('_');
  });
});

describe('dashboards/Index — selection and bulk delete', () => {
  it('keeps a dashboard the actor may not delete out of the selection', () => {
    const blocked = row({ can_delete: false, delete_reason: 'not_owner' });
    const wrapper = mountIndex([blocked]);

    expect(selectionCheckbox(wrapper, blocked).exists()).toBe(false);
    expect(selectionCheckbox(wrapper, row()).exists()).toBe(true);
  });

  it('offers the bulk bar only once something is selected', async () => {
    const wrapper = mountIndex();

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);

    await selectRow(wrapper, row());

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);
  });

  it('sends exactly the selected ids to the bulk delete endpoint', async () => {
    const second = row({ id: '01DASHBOARD00000000000002' });
    const wrapper = mountIndex([row(), second]);

    await selectRow(wrapper, second);

    await wrapper.find('[data-testid="bulk-delete"]').trigger('click');
    await wrapper.find('[data-testid="bulk-delete-confirm"]').trigger('click');

    expect(postMock).toHaveBeenCalledTimes(1);
    expect(postMock).toHaveBeenCalledWith(
      DashboardsController.bulkDestroy.url(),
      { ids: [second.id] },
      expect.anything(),
    );
  });

  it('prunes a selected row that the server no longer lists', async () => {
    const wrapper = mountIndex();

    await selectRow(wrapper, row());

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(true);

    await wrapper.setProps({
      dashboards: [row({ id: '01DASHBOARD00000000000009' })],
    });
    await nextTick();

    expect(wrapper.find('[data-testid="bulk-delete"]').exists()).toBe(false);
  });
});

describe('dashboards/Index — deleting', () => {
  it('asks before deleting and never deletes straight away', async () => {
    const wrapper = mountIndex();

    rowAction<DashboardRow>(wrapper, 'Löschen').onClick?.(row());
    await nextTick();

    expect(wrapper.findComponent(ConfirmDialog).props('open')).toBe(true);
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('deletes through the wayfinder route exactly once after the confirmation', async () => {
    const wrapper = mountIndex();

    rowAction<DashboardRow>(wrapper, 'Löschen').onClick?.(row());
    await nextTick();

    wrapper.findComponent(ConfirmDialog).vm.$emit('confirm');
    await nextTick();

    expect(deleteMock).toHaveBeenCalledTimes(1);
    expect(deleteMock.mock.calls[0][0]).toBe(DashboardsController.destroy.url({ dashboard: row().id }));
  });
});
