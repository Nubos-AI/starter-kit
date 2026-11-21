<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColDef, ValueFormatterParams, ValueGetterParams } from 'ag-grid-community';
import { computed } from 'vue';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import type { GoalListRow } from '@/domains/analytics/data';
import {
  currentGoalPeriod,
  formatGoalValue,
  GOAL_DIRECTION_LABELS,
  GOAL_NOT_CALCULATED_LABEL,
  GOAL_PERIOD_TYPE_LABELS,
  GOAL_SCOPE_TYPE_LABELS,
  resolveGoalActionRefusal,
} from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { formatRelativeTime } from '@/shared/lib/formatDate';
import type { ListGridCopy, ListGridRowDelete, ListGridRowOpen } from '@/shared/types/listGrid';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { LinkCellRenderer } from '@/shared/ui/data-grid/LinkCellRenderer';
import ListGrid from '@/shared/ui/data-grid/ListGrid.vue';
import ListPage from '@/shared/ui/data-grid/ListPage.vue';
import { goalProgressColumn } from '../../ui/GoalProgressCellRenderer';

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    goals?: GoalListRow[];
  }>(),
  { goals: () => [] },
);

const goals = computed<GoalListRow[]>(() => props.goals ?? []);

function editUrl(row: GoalListRow): string {
  return GoalsController.edit.url({ goal: row.id });
}

function destroyUrl(row: GoalListRow): string {
  return GoalsController.destroy.url({ goal: row.id });
}

function bulkDestroyUrl(): string {
  return GoalsController.bulkDestroy.url();
}

function goToCreate(): void {
  router.visit(GoalsController.create.url());
}

function goToEdit(row: GoalListRow): void {
  router.visit(editUrl(row));
}

const columns = computed<ColDef<GoalListRow>[]>(() => [
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.pages.goals.index.name'),
    flex: 2,
    minWidth: 200,
    cellRenderer: LinkCellRenderer,
    cellRendererParams: {
      href: (row: GoalListRow): string | undefined => (row.can_update ? editUrl(row) : undefined),
    },
  },
  {
    colId: 'target',
    headerName: t('i18n.pages.goals.index.assignment'),
    flex: 1,
    minWidth: 160,
    valueGetter: (params: ValueGetterParams<GoalListRow>): string | null =>
      params.data === undefined
        ? null
        : (params.data.target?.label ?? t(GOAL_SCOPE_TYPE_LABELS[params.data.scope_type])),
  },
  {
    colId: 'period_type',
    field: 'period_type',
    headerName: t('i18n.pages.goals.index.period_type'),
    flex: 1,
    minWidth: 130,
    valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
      params.data === undefined ? '' : t(GOAL_PERIOD_TYPE_LABELS[params.data.period_type]),
  },
  {
    colId: 'direction',
    field: 'direction',
    headerName: t('i18n.pages.goals.index.direction'),
    flex: 1,
    minWidth: 170,
    valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
      params.data === undefined ? '' : t(GOAL_DIRECTION_LABELS[params.data.direction]),
  },
  {
    colId: 'target_value',
    field: 'target_value',
    headerName: t('i18n.pages.goals.index.target_value'),
    flex: 1,
    minWidth: 120,
    valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
      formatGoalValue(typeof params.value === 'string' ? params.value : null, t),
  },
  goalProgressColumn({
    headerName: t('i18n.pages.goals.index.actual_value'),
    flex: 2,
    minWidth: 200,
  }),
  {
    colId: 'calculated_at',
    headerName: t('i18n.pages.goals.index.last_calculated_value'),
    flex: 1,
    minWidth: 200,
    valueGetter: (params: ValueGetterParams<GoalListRow>): string | null =>
      params.data === undefined ? null : (currentGoalPeriod(params.data)?.calculated_at ?? null),
    valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
      typeof params.value === 'string' ? formatRelativeTime(params.value) : t(GOAL_NOT_CALCULATED_LABEL),
  },
]);

const rowOpen = computed<ListGridRowOpen<GoalListRow>>(() => ({
  label: t('i18n.pages.goals.index.edit'),
  testId: 'goal-edit',
  href: editUrl,
  isAllowed: (row) => row.can_update,
  reason: (row) => resolveGoalActionRefusal(row.update_reason, t),
}));

const rowDelete = computed<ListGridRowDelete<GoalListRow>>(() => ({
  label: t('i18n.pages.goals.index.delete'),
  testId: 'goal-delete',
  reason: (row) => resolveGoalActionRefusal(row.delete_reason, t),
}));

const copy = computed<ListGridCopy<GoalListRow>>(() => ({
  deleteSelected: t('i18n.pages.goals.index.delete_goals'),
  emptyTitle: t('i18n.pages.goals.index.no_goals_yet'),
  emptyDescription: t('i18n.pages.goals.index.create_your_first_goal_to_track_the_progress_of_a_metric'),
  create: t('i18n.pages.goals.index.create_goal'),
  deleteTitle: t('i18n.pages.goals.index.delete_goal'),
  deleteDescription: (row) =>
    row === null
      ? ''
      : t('i18n.pages.goals.index.the_goal_will_be_deleted_its_previous_progress_will', { value1: row.name }),
}));
</script>

<template>
  <ListPage
    :title="t('i18n.pages.goals.index.goals')"
    :description="t('i18n.pages.goals.index.create_goals_track_their_progress_or_remove_them')"
  >
    <template #actions>
      <CreateButton :href="GoalsController.create.url()" :label="t('i18n.pages.goals.index.create_goal')" />
    </template>

    <ListGrid
      :rows="goals"
      :columns="columns"
      :grid-label="t('i18n.pages.goals.index.goals')"
      :copy="copy"
      :destroy-url="destroyUrl"
      :bulk-destroy-url="bulkDestroyUrl"
      :row-open="rowOpen"
      :row-delete="rowDelete"
      @create="goToCreate"
      @open="goToEdit"
    />
  </ListPage>
</template>
