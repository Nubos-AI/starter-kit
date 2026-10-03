<script setup lang="ts">
import { computed } from 'vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { formatRelativeTime } from '@/lib/formatDate';
import type { GoalListRow, GoalPeriod } from '@/types/goals';
import {
    formatGoalPeriodLabel,
    formatGoalValue,
    pastGoalPeriods,
} from '@/types/goals';

const { t } = useI18n();

const props = defineProps<{
    goal: GoalListRow;
}>();

const periods = computed<GoalPeriod[]>(() => pastGoalPeriods(props.goal));
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.goals.goal_period_history.completed_periods')
            }}</CardTitle>
            <CardDescription>
                {{
                    t(
                        'i18n.components.goals.goal_period_history.the_latest_calculated_actual_value_for_each_completed_period',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <p
                v-if="periods.length === 0"
                data-goal-period-empty
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.goals.goal_period_history.no_period_has_ended_for_this_goal_yet_the',
                    )
                }}
            </p>

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="period in periods"
                    :key="period.id"
                    data-goal-period-row
                    class="flex items-center justify-between gap-3 border-b pb-2 text-sm last:border-0 last:pb-0"
                >
                    <span data-goal-period-label>
                        {{ formatGoalPeriodLabel(period, goal.period_type) }}
                    </span>
                    <span class="flex items-baseline gap-3">
                        <span>{{ formatGoalValue(period.current_value) }}</span>
                        <span
                            v-if="period.calculated_at !== null"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatRelativeTime(period.calculated_at) }}
                        </span>
                    </span>
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
