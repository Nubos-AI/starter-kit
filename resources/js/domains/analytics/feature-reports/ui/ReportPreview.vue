<script setup lang="ts">
import { computed, watch } from 'vue';
import type { ReportChartType, ReportGroupingBucket, ReportPresentation } from '@/domains/analytics/data';
import { plottableChartType } from '@/domains/analytics/data';
import { ReportChart } from '@/domains/analytics/ui-charts';
import { useReportDrillDown } from '@/shared/business-data/data/useReportDrillDown';
import type { ReportDrillDownSource } from '@/shared/business-data/data/useReportDrillDown';
import type { ReportDrillDownSelection } from '@/shared/business-data/types/drillDown';
import { useI18n } from '@/shared/composables/useI18n';
import { Button } from '@/shared/ui/primitives/button';
import { useReportPreview } from '../data/useReportPreview';
import MetricTile from './MetricTile.vue';

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

const chartType = computed<ReportChartType | null>(() => plottableChartType(props.presentation));

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
        {{ t('i18n.components.reports.report_preview.the_preview_is_always_calculated_with_your_own_permissions') }}
      </p>
      <Button
        type="button"
        variant="outline"
        :disabled="state === 'loading'"
        data-report-preview-refresh
        @click="refresh"
      >
        {{ t('i18n.components.reports.report_preview.refresh_preview') }}
      </Button>
    </div>

    <p
      v-if="state === 'idle'"
      class="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground"
      data-report-preview-idle
    >
      {{ t('i18n.components.reports.report_preview.nothing_calculated_yet_start_the_preview_to_see_this') }}
    </p>

    <MetricTile v-else-if="chartType === null" :title="props.title" :result="result" :refusal="refusal" />

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
