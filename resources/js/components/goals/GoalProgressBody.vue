<script setup lang="ts">
import { computed } from 'vue';
import GoalProgressBar from '@/components/charts/GoalProgressBar.vue';
import { useI18n } from '@/composables/useI18n';
import { formatRelativeTime } from '@/lib/formatDate';
import type { GoalListRow, GoalProgress } from '@/types/goals';
import {
    formatGoalValue,
    GOAL_NOT_CALCULATED_LABEL,
    resolveGoalProgress,
} from '@/types/goals';

const { t } = useI18n();

const PENDING_HINT = t(
    'i18n.components.goals.goal_progress_body.the_actual_value_for_the_current_period_is_progress',
    { value1: GOAL_NOT_CALCULATED_LABEL },
);

const props = defineProps<{
    goal: GoalListRow;
}>();

const progress = computed<GoalProgress | null>(() =>
    resolveGoalProgress(props.goal),
);
</script>

<template>
    <div class="flex flex-col gap-4" data-goal-progress-body>
        <p
            v-if="progress === null"
            data-goal-pending
            class="text-sm text-muted-foreground"
        >
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
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.goals.goal_progress_body.actual_value',
                            )
                        }}
                    </dt>
                    <dd class="text-sm">
                        {{ formatGoalValue(String(progress.current)) }}
                    </dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t(
                                'i18n.components.goals.goal_progress_body.target_value',
                            )
                        }}
                    </dt>
                    <dd class="text-sm">
                        {{ formatGoalValue(props.goal.target_value) }}
                    </dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-xs text-muted-foreground">
                        {{
                            t('i18n.components.goals.goal_progress_body.as_of')
                        }}
                    </dt>
                    <dd class="text-sm">
                        {{ formatRelativeTime(progress.calculatedAt) }}
                    </dd>
                </div>
            </dl>
        </template>
    </div>
</template>
