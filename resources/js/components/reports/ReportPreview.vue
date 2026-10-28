<script setup lang="ts">
import { computed, watch } from 'vue';
import MetricTile from '@/components/charts/MetricTile.vue';
import ReportChart from '@/components/charts/ReportChart.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ReportDrillDownSource } from '@/composables/useReportDrillDown';
import { useReportDrillDown } from '@/composables/useReportDrillDown';
import { useReportPreview } from '@/composables/useReportPreview';
import type {
    ReportChartType,
    ReportDrillDownSelection,
    ReportGroupingBucket,
    ReportPresentation,
} from '@/types/reports';
import { plottableChartType } from '@/types/reports';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        title: string;
        presentation: ReportPresentation;
        bucket: ReportGroupingBucket | null;
        payload: () => Record<string, unknown>;
        drillDownSource?: ReportDrillDownSource | null;
    }>(),
    { drillDownSource: null },
);

const { state, result, refusal, run, reset } = useReportPreview();

const { open: openDrillDown } = useReportDrillDown();

const chartType = computed<ReportChartType | null>(() =>
    plottableChartType(props.presentation),
);

const definitionKey = computed<string>(() =>
    JSON.stringify({
        presentation: props.presentation,
        payload: props.payload(),
    }),
);

watch(definitionKey, () => {
    reset();
});

function refresh(): void {
    void run(props.payload());
}

function onDrillDown(selection: ReportDrillDownSelection): void {
    const source = props.drillDownSource;

    if (source === null) {
        return;
    }

    openDrillDown({ ...source, selection });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-muted-foreground" data-report-preview-scope>
                {{
                    t(
                        'i18n.components.reports.report_preview.the_preview_is_always_calculated_with_your_own_permissions',
                    )
                }}
            </p>
            <Button
                type="button"
                variant="outline"
                :disabled="state === 'loading'"
                data-report-preview-refresh
                @click="refresh"
            >
                {{
                    t('i18n.components.reports.report_preview.refresh_preview')
                }}
            </Button>
        </div>

        <p
            v-if="state === 'idle'"
            class="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground"
            data-report-preview-idle
        >
            {{
                t(
                    'i18n.components.reports.report_preview.nothing_calculated_yet_start_the_preview_to_see_this',
                )
            }}
        </p>

        <MetricTile
            v-else-if="chartType === null"
            :title="props.title"
            :result="result"
            :refusal="refusal"
        />

        <ReportChart
            v-else
            :title="props.title"
            :chart-type="chartType"
            :result="result"
            :refusal="refusal"
            :bucket="props.bucket"
            bar-mode="grouped"
            @drill-down="onDrillDown"
        />
    </div>
</template>
