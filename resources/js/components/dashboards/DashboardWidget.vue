<script setup lang="ts">
import { ArrowLeft, ArrowRight, Pencil, RefreshCw, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import MetricValue from '@/components/charts/MetricValue.vue';
import ReportChart from '@/components/charts/ReportChart.vue';
import GoalProgressBody from '@/components/goals/GoalProgressBody.vue';
import {
    Card,
    CardContent,
    CardFooter,
    CardHeader,
} from '@/components/ui/card';
import { IconActionButton } from '@/components/ui/icon-action-button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { formatRelativeTime } from '@/lib/formatDate';
import { REPORT_EXECUTION_MODE } from '@/lib/statusMaps';
import type {
    DashboardColumnSpan,
    DashboardWidgetMeta,
    DashboardWidgetTile,
} from '@/types/dashboards';
import {
    clampColumnSpan,
    COLUMN_SPAN_LABELS,
    COLUMN_SPAN_VALUES,
    widgetTitle,
} from '@/types/dashboards';
import type { GoalListRow } from '@/types/goals';
import type {
    ReportChartType,
    ReportDrillDownSelection,
    ReportRefusal,
    ReportResult,
} from '@/types/reports';
import { plottableChartType } from '@/types/reports';

const { t } = useI18n();

const SPAN_LABEL = t('i18n.components.dashboards.dashboard_widget.tile_width');

const props = withDefaults(
    defineProps<{
        widget: DashboardWidgetMeta;
        tile?: DashboardWidgetTile | null;
        canUpdate: boolean;
        updateReason?: string;
        isRefreshing?: boolean;
        isDropTarget?: boolean;
    }>(),
    { tile: null, isRefreshing: false, isDropTarget: false },
);

const emit = defineEmits<{
    refresh: [widgetId: string];
    'edit-widget': [widgetId: string];
    'remove-widget': [widgetId: string];
    'span-change': [payload: { widgetId: string; span: DashboardColumnSpan }];
    'move-by': [payload: { widgetId: string; offset: -1 | 1 }];
    'drill-down': [
        payload: { widgetId: string; selection: ReportDrillDownSelection },
    ];
}>();

const title = computed<string>(() => widgetTitle(props.widget.title));

const isLoading = computed<boolean>(() => props.tile === null);

const goal = computed<GoalListRow | null>(() => props.tile?.goal ?? null);

const chartType = computed<ReportChartType | null>(() =>
    props.widget.chart_type === null
        ? null
        : plottableChartType(props.widget.chart_type),
);

const result = computed<ReportResult | null>(() =>
    props.tile === null || props.tile.notice !== null
        ? null
        : props.tile.result,
);

const refusal = computed<ReportRefusal | null>(() =>
    props.tile === null || props.tile.notice === null
        ? null
        : { reason: props.tile.notice.reason },
);

const isDefinerBound = computed<boolean>(
    () => props.tile !== null && props.tile.execution_mode === 'definer',
);

const bucket = computed(() => props.widget.definition?.group_by_bucket ?? null);

const generatedAt = computed<string | null>(
    () => props.tile?.generated_at ?? null,
);

const generatedLabel = computed<string>(() =>
    generatedAt.value === null ? '' : formatRelativeTime(generatedAt.value),
);

const spanValue = computed<string>(() => String(props.widget.column_span));

const spanOptions = COLUMN_SPAN_VALUES.map((span) => ({
    value: String(span),
    label: COLUMN_SPAN_LABELS[span],
}));

function onSpanChange(value: unknown): void {
    emit('span-change', {
        widgetId: props.widget.id,
        span: clampColumnSpan(Number(value)),
    });
}

function onDrillDown(selection: ReportDrillDownSelection): void {
    if (props.tile === null || props.tile.object_type === null) {
        return;
    }

    emit('drill-down', { widgetId: props.widget.id, selection });
}
</script>

<template>
    <Card
        :data-dashboard-widget="props.widget.id"
        :data-widget-span="spanValue"
        :data-drop-target="props.isDropTarget ? '' : undefined"
        :class="props.isDropTarget ? 'ring-2 ring-ring' : undefined"
    >
        <CardHeader class="flex items-start justify-between gap-2">
            <div class="flex min-w-0 flex-col gap-1">
                <span class="truncate text-sm font-semibold">{{ title }}</span>
                <StatusBadge
                    v-if="isDefinerBound"
                    :map="REPORT_EXECUTION_MODE"
                    status="definer"
                    data-widget-definer
                    class="w-fit"
                />
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <Select
                    :model-value="spanValue"
                    :disabled="!props.canUpdate"
                    :title="props.updateReason"
                    data-widget-span-select
                    @update:model-value="onSpanChange"
                >
                    <SelectTrigger
                        class="w-32"
                        size="sm"
                        :aria-label="SPAN_LABEL"
                        :title="props.updateReason"
                    >
                        <SelectValue :placeholder="SPAN_LABEL" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in spanOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <IconActionButton
                    :icon="Pencil"
                    :label="
                        t(
                            'i18n.components.dashboards.dashboard_widget.edit_tile',
                        )
                    "
                    variant="edit"
                    :disabled="!props.canUpdate"
                    :title="props.updateReason"
                    data-widget-edit
                    @click="emit('edit-widget', props.widget.id)"
                />
                <IconActionButton
                    :icon="ArrowLeft"
                    :label="
                        t(
                            'i18n.components.dashboards.dashboard_widget.move_forward',
                        )
                    "
                    :disabled="!props.canUpdate"
                    :title="props.updateReason"
                    data-widget-move-up
                    @click="
                        emit('move-by', {
                            widgetId: props.widget.id,
                            offset: -1,
                        })
                    "
                />
                <IconActionButton
                    :icon="ArrowRight"
                    :label="
                        t(
                            'i18n.components.dashboards.dashboard_widget.move_backward',
                        )
                    "
                    :disabled="!props.canUpdate"
                    :title="props.updateReason"
                    data-widget-move-down
                    @click="
                        emit('move-by', {
                            widgetId: props.widget.id,
                            offset: 1,
                        })
                    "
                />
                <IconActionButton
                    :icon="Trash2"
                    :label="
                        t(
                            'i18n.components.dashboards.dashboard_widget.remove_tile',
                        )
                    "
                    variant="destructive"
                    :disabled="!props.canUpdate"
                    :title="props.updateReason"
                    data-widget-remove
                    @click="emit('remove-widget', props.widget.id)"
                />
            </div>
        </CardHeader>

        <CardContent>
            <GoalProgressBody v-if="goal !== null" :goal="goal" />
            <MetricValue
                v-else-if="props.widget.goal_id !== null"
                :result="null"
                :refusal="refusal"
            />
            <ReportChart
                v-else-if="chartType !== null"
                :title="title"
                :chart-type="chartType"
                :result="result"
                :refusal="refusal"
                :bucket="bucket"
                @drill-down="onDrillDown"
            />
            <MetricValue v-else :result="result" :refusal="refusal" />
        </CardContent>

        <CardFooter class="justify-between gap-2">
            <Skeleton v-if="isLoading" data-widget-loading class="h-4 w-24" />
            <time
                v-else-if="generatedAt !== null"
                :datetime="generatedAt"
                data-widget-generated-at
                class="text-xs text-muted-foreground"
            >
                {{ generatedLabel }}
            </time>
            <span v-else class="text-xs text-muted-foreground">—</span>

            <IconActionButton
                :icon="RefreshCw"
                :label="
                    t(
                        'i18n.components.dashboards.dashboard_widget.refresh_tile',
                    )
                "
                :disabled="props.isRefreshing"
                data-widget-refresh
                @click="emit('refresh', props.widget.id)"
            />
        </CardFooter>
    </Card>
</template>
