<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type {
    ColDef,
    ValueFormatterParams,
    ValueGetterParams,
} from 'ag-grid-community';
import { computed, watch } from 'vue';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateButton from '@/components/CreateButton.vue';
import { actionsColumn } from '@/components/data-grid/actions';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { LinkCellRenderer } from '@/components/data-grid/LinkCellRenderer';
import ListPage from '@/components/data-grid/ListPage.vue';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/components/data-grid/selectionColumn';
import ViewStates from '@/components/engine/ViewStates.vue';
import { goalProgressColumn } from '@/components/goals/GoalProgressCellRenderer';
import { useI18n } from '@/composables/useI18n';
import { useListSelection } from '@/composables/useListSelection';
import { useRowDeletion } from '@/composables/useRowDeletion';
import { formatRelativeTime } from '@/lib/formatDate';
import type { GoalListRow } from '@/types/goals';
import {
    currentGoalPeriod,
    formatGoalValue,
    GOAL_DIRECTION_LABELS,
    GOAL_NOT_CALCULATED_LABEL,
    GOAL_PERIOD_TYPE_LABELS,
    GOAL_SCOPE_TYPE_LABELS,
    resolveGoalActionRefusal,
} from '@/types/goals';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        goals?: GoalListRow[];
    }>(),
    { goals: () => [] },
);

const goals = computed<GoalListRow[]>(() => props.goals ?? []);

const listState = computed<'empty' | 'ready'>(() =>
    goals.value.length === 0 ? 'empty' : 'ready',
);

const selection = useListSelection();

watch(goals, (rows) =>
    selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } =
    useRowDeletion<GoalListRow>({
        url: (row) => GoalsController.destroy.url({ goal: row.id }),
    });

const deleteDescription = computed<string>(() =>
    pendingDelete.value === null
        ? ''
        : t(
              'i18n.pages.goals.index.the_goal_will_be_deleted_its_previous_progress_will',
              { value1: pendingDelete.value.name },
          ),
);

function editUrl(row: GoalListRow): string {
    return GoalsController.edit.url({ goal: row.id });
}

function bulkDeleteGoals(): void {
    router.post(
        GoalsController.bulkDestroy.url(),
        { ids: selection.ids.value },
        {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
        },
    );
}

function goToCreate(): void {
    router.visit(GoalsController.create.url());
}

function goToEdit(row: GoalListRow): void {
    router.visit(editUrl(row));
}

const columnDefs = computed<ColDef<GoalListRow>[]>(() => [
    selectionColumn<GoalListRow>(selection, (row) => row.can_delete),
    {
        colId: 'name',
        field: 'name',
        headerName: t('i18n.pages.goals.index.name'),
        flex: 2,
        minWidth: 200,
        cellRenderer: LinkCellRenderer,
        cellRendererParams: {
            href: (row: GoalListRow): string | undefined =>
                row.can_update ? editUrl(row) : undefined,
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
                : (params.data.target?.label ??
                  GOAL_SCOPE_TYPE_LABELS[params.data.scope_type]),
    },
    {
        colId: 'period_type',
        field: 'period_type',
        headerName: t('i18n.pages.goals.index.period_type'),
        flex: 1,
        minWidth: 130,
        valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
            params.data === undefined
                ? ''
                : GOAL_PERIOD_TYPE_LABELS[params.data.period_type],
    },
    {
        colId: 'direction',
        field: 'direction',
        headerName: t('i18n.pages.goals.index.direction'),
        flex: 1,
        minWidth: 170,
        valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
            params.data === undefined
                ? ''
                : GOAL_DIRECTION_LABELS[params.data.direction],
    },
    {
        colId: 'target_value',
        field: 'target_value',
        headerName: t('i18n.pages.goals.index.target_value'),
        flex: 1,
        minWidth: 120,
        valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
            formatGoalValue(
                typeof params.value === 'string' ? params.value : null,
            ),
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
            params.data === undefined
                ? null
                : (currentGoalPeriod(params.data)?.calculated_at ?? null),
        valueFormatter: (params: ValueFormatterParams<GoalListRow>): string =>
            typeof params.value === 'string'
                ? formatRelativeTime(params.value)
                : GOAL_NOT_CALCULATED_LABEL,
    },
    actionsColumn<GoalListRow>([
        {
            icon: Pencil,
            label: t('i18n.pages.goals.index.edit'),
            variant: 'edit',
            testId: 'goal-edit',
            href: (row) => editUrl(row),
            isDisabled: (row) => !row.can_update,
            disabledReason: (row) =>
                resolveGoalActionRefusal(row.update_reason),
        },
        {
            icon: Trash2,
            label: t('i18n.pages.goals.index.delete'),
            variant: 'destructive',
            testId: 'goal-delete',
            onClick: (row) => (pendingDelete.value = row),
            isDisabled: (row) => !row.can_delete,
            disabledReason: (row) =>
                resolveGoalActionRefusal(row.delete_reason),
        },
    ]),
]);
</script>

<template>
    <ListPage
        :title="t('i18n.pages.goals.index.goals')"
        :description="
            t(
                'i18n.pages.goals.index.create_goals_track_their_progress_or_remove_them',
            )
        "
    >
        <template #actions>
            <CreateButton
                :href="GoalsController.create.url()"
                :label="t('i18n.pages.goals.index.create_goal')"
            />
        </template>

        <SelectionBulkBar
            :count="selection.count.value"
            :delete-label="t('i18n.pages.goals.index.delete_goals')"
            @delete="bulkDeleteGoals"
            @clear="selection.clear"
        />

        <div class="relative flex min-h-40 flex-1 flex-col">
            <ViewStates
                :state="listState"
                skeleton-variant="list"
                :empty-title="t('i18n.pages.goals.index.no_goals_yet')"
                :empty-description="
                    t(
                        'i18n.pages.goals.index.create_your_first_goal_to_track_the_progress_of_a_metric',
                    )
                "
                :create-label="t('i18n.pages.goals.index.create_goal')"
                @create="goToCreate"
            />

            <div v-if="listState === 'ready'" class="min-h-0 flex-1">
                <DataGrid
                    class="h-full"
                    :column-defs="columnDefs"
                    :row-data="goals"
                    :is-row-activatable="(row: GoalListRow) => row.can_update"
                    :aria-label="t('i18n.pages.goals.index.goals')"
                    @row-activate="goToEdit"
                />
            </div>
        </div>

        <ConfirmDialog
            v-model:open="deleteDialogOpen"
            :title="t('i18n.pages.goals.index.delete_goal')"
            :description="deleteDescription"
            :confirm-label="t('i18n.pages.goals.index.delete')"
            variant="destructive"
            :pending="deletePending"
            @confirm="confirmDelete"
        />
    </ListPage>
</template>
