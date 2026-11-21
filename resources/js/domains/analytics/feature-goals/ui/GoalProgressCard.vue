<script setup lang="ts">
import type { GoalListRow } from '@/domains/analytics/data';
import { GoalProgressBody } from '@/domains/analytics/ui-goals';
import { useI18n } from '@/shared/composables/useI18n';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';

const { t } = useI18n();

const props = defineProps<{
  goal: GoalListRow;
}>();
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ t('i18n.components.goals.goal_progress_card.progress') }}</CardTitle>
      <CardDescription>
        {{ t('i18n.components.goals.goal_progress_card.the_current_period_s_actual_value_relative_to_the') }}
      </CardDescription>
    </CardHeader>
    <CardContent>
      <GoalProgressBody :goal="props.goal" />
    </CardContent>
  </Card>
</template>
