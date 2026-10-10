<script setup lang="ts">
import GoalProgressBody from '@/components/goals/GoalProgressBody.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import type { GoalListRow } from '@/types/goals';

const { t } = useI18n();

const props = defineProps<{
    goal: GoalListRow;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('i18n.components.goals.goal_progress_card.progress')
            }}</CardTitle>
            <CardDescription>
                {{
                    t(
                        'i18n.components.goals.goal_progress_card.the_current_period_s_actual_value_relative_to_the',
                    )
                }}
            </CardDescription>
        </CardHeader>
        <CardContent>
            <GoalProgressBody :goal="props.goal" />
        </CardContent>
    </Card>
</template>
