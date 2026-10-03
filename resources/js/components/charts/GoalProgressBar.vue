<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import { formatGoalNumber } from '@/types/goals';

const { t } = useI18n();

type GoalDirection = 'at_least' | 'at_most';

type GoalProgressLayout = 'stacked' | 'inline';

type GoalState =
    | 'in_progress'
    | 'reached'
    | 'within'
    | 'at_risk'
    | 'exceeded'
    | 'unknown';

const GOAL_STATES: Record<GoalState, { text: string; barClass: string }> = {
    in_progress: {
        text: t('i18n.components.charts.goal_progress_bar.in_progress'),
        barClass: 'bg-primary',
    },
    reached: {
        text: t('i18n.components.charts.goal_progress_bar.achieved'),
        barClass: 'bg-success-bold',
    },
    within: {
        text: t('i18n.components.charts.goal_progress_bar.within_limits'),
        barClass: 'bg-primary',
    },
    at_risk: {
        text: t('i18n.components.charts.goal_progress_bar.approaching_limit'),
        barClass: 'bg-warning-bold',
    },
    exceeded: {
        text: t('i18n.components.charts.goal_progress_bar.exceeded'),
        barClass: 'bg-destructive',
    },
    unknown: {
        text: t('i18n.components.charts.goal_progress_bar.no_goal_configured'),
        barClass: 'bg-muted-foreground',
    },
} as const;

const GOAL_REACHED_PERCENT = 100;

const GOAL_AT_RISK_PERCENT = 90;

const props = withDefaults(
    defineProps<{
        label: string;
        current: number;
        target: number;
        direction: GoalDirection;
        layout?: GoalProgressLayout;
    }>(),
    { layout: 'stacked' },
);

const isInline = computed<boolean>(() => props.layout === 'inline');

const reading = computed<number>(() =>
    Number.isFinite(props.current) ? props.current : 0,
);

const percent = computed<number | null>(() => {
    if (!Number.isFinite(props.target) || props.target <= 0) {
        return null;
    }

    return Math.round((reading.value / props.target) * 1000) / 10;
});

const clampedPercent = computed<number>(() =>
    percent.value === null
        ? 0
        : Math.min(Math.max(percent.value, 0), GOAL_REACHED_PERCENT),
);

const stateKey = computed<GoalState>(() => {
    if (percent.value === null) {
        return 'unknown';
    }

    if (props.direction === 'at_least') {
        return percent.value >= GOAL_REACHED_PERCENT
            ? 'reached'
            : 'in_progress';
    }

    if (percent.value > GOAL_REACHED_PERCENT) {
        return 'exceeded';
    }

    return percent.value > GOAL_AT_RISK_PERCENT ? 'at_risk' : 'within';
});

const state = computed(() => GOAL_STATES[stateKey.value]);

const valueText = computed<string>(() =>
    percent.value === null
        ? GOAL_STATES.unknown.text
        : t('i18n.components.charts.goal_progress_bar.of', {
              value1: formatGoalNumber(reading.value),
              value2: formatGoalNumber(props.target),
              value3: formatGoalNumber(percent.value),
          }),
);
</script>

<template>
    <div
        :class="
            isInline
                ? 'flex h-full w-full items-center gap-2'
                : 'flex flex-col gap-1'
        "
    >
        <span
            v-if="isInline"
            data-goal-value
            class="shrink-0 text-sm tabular-nums"
        >
            {{ valueText }}
        </span>

        <div v-else class="flex items-center justify-between gap-2">
            <span class="truncate text-sm">{{ label }}</span>
            <span class="flex shrink-0 items-center gap-2">
                <span data-goal-value class="text-xs tabular-nums">{{
                    valueText
                }}</span>
                <span data-goal-status class="text-xs text-muted-foreground">{{
                    state.text
                }}</span>
            </span>
        </div>

        <div
            data-goal-progress
            role="progressbar"
            :class="[
                'h-2 overflow-hidden rounded-full bg-muted',
                isInline ? 'min-w-8 flex-1' : 'w-full',
            ]"
            :data-goal-state="stateKey"
            :aria-label="label"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="clampedPercent"
            :aria-valuetext="valueText"
        >
            <div
                data-goal-bar
                :class="['h-full rounded-full', state.barClass]"
                :style="{ width: `${clampedPercent}%` }"
            />
        </div>

        <span
            v-if="isInline"
            data-goal-status
            class="shrink-0 text-sm text-muted-foreground"
        >
            {{ state.text }}
        </span>
    </div>
</template>
