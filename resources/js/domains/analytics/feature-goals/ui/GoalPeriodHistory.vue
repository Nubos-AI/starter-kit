<script setup lang="ts">
import { computed } from 'vue';
import type { GoalListRow, GoalPeriod } from '@/domains/analytics/data';
import { formatGoalPeriodLabel, formatGoalValue, pastGoalPeriods } from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { formatRelativeTime } from '@/shared/lib/formatDate';
import EmptyState from '@/shared/ui/EmptyState.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';

const { t } = useI18n();

const props = defineProps<{
  goal: GoalListRow;
}>();

const periods = computed<GoalPeriod[]>(() => pastGoalPeriods(props.goal));
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('i18n.components.goals.goal_period_history.completed_periods') }}</CardTitle>
      <CardDescription>
        {{
          t('i18n.components.goals.goal_period_history.the_latest_calculated_actual_value_for_each_completed_period')
        }}
      </CardDescription>
    </CardHeader>
    <CardContent>
      <EmptyState
        v-if="periods.length === 0"
        data-goal-period-empty
        variant="inline"
        :description="t('i18n.components.goals.goal_period_history.no_period_has_ended_for_this_goal_yet_the')"
      />

      <ul v-else class="flex flex-col gap-2">
        <li
          v-for="period in periods"
          :key="period.id"
          data-goal-period-row
          class="flex items-center justify-between gap-3 border-b pb-2 text-sm last:border-0 last:pb-0"
        >
          <span data-goal-period-label>
            {{ formatGoalPeriodLabel(period, goal.period_type, t) }}
          </span>
          <span class="flex items-baseline gap-3">
            <span>{{ formatGoalValue(period.current_value, t) }}</span>
            <span v-if="period.calculated_at !== null" class="text-xs text-muted-foreground">
              {{ formatRelativeTime(period.calculated_at) }}
            </span>
          </span>
        </li>
      </ul>
    </CardContent>
  </Card>
</template>
