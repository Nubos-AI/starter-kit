import type { ColDef, ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import GoalProgressBar from '@/components/charts/GoalProgressBar.vue';
import type { GoalListRow } from '@/types/goals';
import { GOAL_NOT_CALCULATED_LABEL, resolveGoalProgress } from '@/types/goals';

export const GoalProgressCellRenderer = defineComponent({
    name: 'GoalProgressCellRenderer',
    props: {
        params: {
            type: Object as PropType<ICellRendererParams<GoalListRow>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data ?? null;
            const progress = row === null ? null : resolveGoalProgress(row);

            if (row === null || progress === null) {
                return h(
                    'span',
                    { class: 'text-muted-foreground' },
                    GOAL_NOT_CALCULATED_LABEL,
                );
            }

            return h(GoalProgressBar, {
                label: row.name,
                layout: 'inline',
                current: progress.current,
                target: progress.target,
                direction: row.direction,
            });
        };
    },
});

export function goalProgressColumn(
    options?: Partial<ColDef<GoalListRow>>,
): ColDef<GoalListRow> {
    return {
        colId: 'current_value',
        cellRenderer: GoalProgressCellRenderer,
        sortable: false,
        filter: false,
        ...options,
    };
}
