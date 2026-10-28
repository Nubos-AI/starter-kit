<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { toast } from 'vue-sonner';
import ChartCanvas from '@/components/charts/ChartCanvas.vue';
import ChartDownloadButton from '@/components/charts/ChartDownloadButton.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import {
    countReportCategories,
    useReportChartOptions,
} from '@/composables/useReportChartOptions';
import type {
    ReportBarMode,
    ReportChartType,
    ReportDrillDownSelection,
    ReportGroupingBucket,
    ReportRefusal,
    ReportResult,
} from '@/types/reports';
import {
    hasPlottableRows,
    REPORT_AGGREGATION_LABELS,
    REPORT_EMPTY_MESSAGE,
    REPORT_SUPPRESSED_MESSAGE,
    reportDiscardedMessage,
    reportImageFileName,
    resolveRefusalMessage,
    resolveReportState,
} from '@/types/reports';

const { t } = useI18n();

const UNPLOTTABLE_MESSAGE = t(
    'i18n.components.charts.report_chart.there_are_no_displayable_values_for_this_report',
);

const OTHER_NOTICE_MESSAGE = t(
    'i18n.components.charts.report_chart.small_and_excess_groups_are_combined_under_other',
);

const DOWNLOAD_ERROR_MESSAGE = t(
    'i18n.components.charts.report_chart.the_chart_could_not_be_saved_as_an_image',
);

const PLACEHOLDER_RESULT: ReportResult = {
    aggregation: 'count',
    rows: [],
    total: null,
    record_count: 0,
    discarded_value_count: 0,
    is_suppressed: false,
    generated_at: '',
    execution_mode: 'viewer',
};

const props = withDefaults(
    defineProps<{
        title: string;
        chartType: ReportChartType;
        result?: ReportResult | null;
        refusal?: ReportRefusal | null;
        barMode?: ReportBarMode;
        bucket?: ReportGroupingBucket | null;
    }>(),
    { result: null, refusal: null, barMode: 'grouped', bucket: null },
);

const emit = defineEmits<{
    'drill-down': [selection: ReportDrillDownSelection];
}>();

const canvasRef = useTemplateRef('canvasRef');
const isDownloading = ref<boolean>(false);

const state = computed(() => resolveReportState(props.result, props.refusal));

const isPlottable = computed(
    () => props.result !== null && hasPlottableRows(props.result),
);

const refusalMessage = computed(() =>
    resolveRefusalMessage(props.refusal?.reason ?? ''),
);

const hasOtherEntry = computed(() =>
    (props.result?.rows ?? []).some(
        (row) => row.is_other_group || row.is_other_series,
    ),
);

const discardedCount = computed(() => props.result?.discarded_value_count ?? 0);

const diagnosticMessage = computed(() =>
    reportDiscardedMessage(discardedCount.value),
);

const summaryMessage = computed(() => {
    const result = props.result;

    if (result === null) {
        return '';
    }

    const aggregation = REPORT_AGGREGATION_LABELS[result.aggregation];

    return t(
        'i18n.components.charts.report_chart.chart_with_the_metric_across_categories',
        {
            value1: props.title,
            value2: aggregation,
            value3: countReportCategories(result),
        },
    );
});

const chartOptions = useReportChartOptions(() => ({
    result: props.result ?? PLACEHOLDER_RESULT,
    chartType: props.chartType,
    barMode: props.barMode,
    bucket: props.bucket,
    onSeriesClick: (selection: ReportDrillDownSelection) =>
        emit('drill-down', selection),
}));

async function downloadImage(): Promise<void> {
    const result = props.result;

    if (result === null || isDownloading.value) {
        return;
    }

    isDownloading.value = true;

    try {
        await canvasRef.value?.downloadImage({
            fileName: reportImageFileName(props.title, result.generated_at),
            fileFormat: 'png',
        });
    } catch {
        toast.error(DOWNLOAD_ERROR_MESSAGE);
    } finally {
        isDownloading.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <Skeleton
            v-if="state === 'loading'"
            data-chart-loading
            class="h-64 w-full"
        />
        <p
            v-else-if="state === 'refused'"
            data-chart-refusal
            class="text-sm text-muted-foreground"
        >
            {{ refusalMessage }}
        </p>
        <p
            v-else-if="state === 'suppressed'"
            data-chart-suppressed
            class="text-sm text-muted-foreground"
        >
            {{ REPORT_SUPPRESSED_MESSAGE }}
        </p>
        <p
            v-else-if="state === 'empty'"
            data-chart-empty
            class="text-sm text-muted-foreground"
        >
            {{ REPORT_EMPTY_MESSAGE }}
        </p>
        <p
            v-else-if="!isPlottable"
            data-chart-unplottable
            class="text-sm text-muted-foreground"
        >
            {{ UNPLOTTABLE_MESSAGE }}
        </p>
        <template v-else>
            <div class="flex justify-end">
                <ChartDownloadButton
                    :is-pending="isDownloading"
                    @download="downloadImage"
                />
            </div>
            <div class="h-full min-h-64 w-full">
                <ChartCanvas ref="canvasRef" :options="chartOptions" />
            </div>
            <p data-chart-summary class="sr-only">{{ summaryMessage }}</p>
        </template>
        <p
            v-if="state === 'ready' && hasOtherEntry"
            data-chart-other-notice
            class="text-xs text-muted-foreground"
        >
            {{ OTHER_NOTICE_MESSAGE }}
        </p>
        <p
            v-if="state === 'ready' && discardedCount > 0"
            data-chart-diagnostic
            class="text-xs text-muted-foreground"
        >
            {{ diagnosticMessage }}
        </p>
    </div>
</template>
