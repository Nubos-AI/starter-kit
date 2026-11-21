<script setup lang="ts">
import { computed } from 'vue';
import type { GoalListRow, GoalProgress } from '@/domains/analytics/data';
import { formatGoalValue, GOAL_NOT_CALCULATED_LABEL, resolveGoalProgress } from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import { formatRelativeTime } from '@/shared/lib/formatDate';
import DetailItem from '@/shared/ui/DetailItem.vue';
import GoalProgressBar from './GoalProgressBar.vue';

const { t } = useI18n();

const PENDING_HINT = t('i18n.components.goals.goal_progress_body.the_actual_value_for_the_current_period_is_progress', {
  value1: t(GOAL_NOT_CALCULATED_LABEL),
});

const props = defineProps<{
  goal: GoalListRow;
}>();

const progress = computed<GoalProgress | null>(() => resolveGoalProgress(props.goal));
</script>

<template>
  <div class="flex flex-col gap-4" data-goal-progress-body>
    <p v-if="progress === null" data-goal-pending class="text-sm text-muted-foreground">
      {{ PENDING_HINT }}
    </p>

    <template v-else>
      <GoalProgressBar
        :label="props.goal.name"
        :current="progress.current"
        :target="progress.target"
        :direction="props.goal.direction"
      />

      <dl class="grid gap-3 sm:grid-cols-3">
        <DetailItem :label="t('i18n.components.goals.goal_progress_body.actual_value')">
          {{ formatGoalValue(String(progress.current), t) }}
        </DetailItem>
        <DetailItem :label="t('i18n.components.goals.goal_progress_body.target_value')">
          {{ formatGoalValue(props.goal.target_value, t) }}
        </DetailItem>
        <DetailItem :label="t('i18n.components.goals.goal_progress_body.as_of')">
          {{ formatRelativeTime(progress.calculatedAt) }}
        </DetailItem>
      </dl>
    </template>
  </div>
</template>
