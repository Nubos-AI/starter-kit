<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import {
  clampColumnSpan,
  COLUMN_SPAN_LABELS,
  COLUMN_SPAN_VALUES,
  WIDGET_EDITOR_CREATE_TITLE,
  WIDGET_EDITOR_EDIT_TITLE,
  WIDGET_SEGMENT_PREFILL_ERROR,
} from '@/domains/analytics/data';
import { useReportDefinition, REPORT_PRESENTATION_LABELS } from '@/domains/analytics/data';
import type { DashboardWidgetMeta } from '@/domains/analytics/data';
import type { ReportAggregation, ReportGroupingBucket, ReportPresentation } from '@/domains/analytics/data';
import { ReportDefinitionFields } from '@/domains/analytics/ui-charts';
import { useGuardedSheet } from '@/shared/composables/useGuardedSheet';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { hydratableFilterTree, normalizeFilterTree } from '@/shared/record-format/utils/filterTree';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/shared/ui/primitives/sheet';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../shared/filters/ui/FilterBuilder.vue';

const { t } = useI18n();

type WidgetSource = 'report' | 'adhoc' | 'goal' | 'module';

const SEARCHABLE_THRESHOLD = 8;

const SOURCE_OPTIONS: SelectOption[] = [
  {
    value: 'report',
    label: t('i18n.components.dashboards.widget_editor_sheet.saved_report'),
  },
  {
    value: 'adhoc',
    label: t('i18n.components.dashboards.widget_editor_sheet.custom_definition'),
  },
  {
    value: 'goal',
    label: t('i18n.components.dashboards.widget_editor_sheet.goal'),
  },
];

const GOAL_LABEL = t('i18n.components.dashboards.widget_editor_sheet.goal');

const NO_GOAL_HINT = t('i18n.components.dashboards.widget_editor_sheet.there_are_no_goals_you_can_view_yet_create');

const GOAL_HINT = t(
  'i18n.components.dashboards.widget_editor_sheet.this_tile_compares_the_current_period_s_actual_value',
);

const SHEET_DESCRIPTION = t(
  'i18n.components.dashboards.widget_editor_sheet.select_a_saved_report_or_create_a_custom_definition',
);

const MODULE_HINT = t('i18n.components.dashboards.widget_editor_sheet.an_installed_app_provides_this_tile');

const NO_REPORT_HINT = t(
  'i18n.components.dashboards.widget_editor_sheet.there_are_no_saved_reports_yet_create_a_report',
);

const SEGMENT_PREFILL_LABEL = t('i18n.components.dashboards.widget_editor_sheet.prefill_from_segment');

const SEGMENT_PREFILL_ACTION = t('i18n.components.dashboards.widget_editor_sheet.apply_filter');

const SEGMENT_PREFILL_HINT = t(
  'i18n.components.dashboards.widget_editor_sheet.the_segment_s_filter_is_copied_once_later_changes',
);

const props = defineProps<{
  dashboardId: string;
  widget: DashboardWidgetMeta | null;
  reportOptions: SelectOption[];
  goalOptions: SelectOption[];
  objectTypeOptions: SelectOption[];
  fieldsByType: Record<string, FieldDefinition[]>;
  linkedFieldsByType: Record<string, FieldDefinition[]>;
  segmentsByType: Record<string, SelectOption[]>;
  errors: Record<string, string>;
  processing: boolean;
}>();

const emit = defineEmits<{
  submit: [payload: Record<string, unknown>];
  close: [];
}>();

const definition = props.widget?.definition ?? null;

const sourceKey = props.widget?.source_key ?? null;

function initialSource(): WidgetSource {
  if (sourceKey !== null) {
    return 'module';
  }

  if ((props.widget?.goal_id ?? null) !== null) {
    return 'goal';
  }

  return definition === null ? 'report' : 'adhoc';
}

const source = ref<WidgetSource>(initialSource());
const reportId = ref<string | null>(props.widget?.report_id ?? null);
const goalId = ref<string | null>(props.widget?.goal_id ?? null);
const objectTypeId = ref<string | null>(definition?.object_type_id ?? null);
const title = ref<string>(props.widget?.title ?? '');
const columnSpan = ref<string>(String(props.widget?.column_span ?? 1));
const chartType = ref<ReportPresentation>(props.widget?.chart_type ?? 'metric');
const aggregationType = ref<ReportAggregation>(definition?.aggregation_type ?? 'count');
const aggregationFieldKey = ref<string | null>(definition?.aggregation_field_key ?? null);
const groupByFieldKey = ref<string | null>(definition?.group_by_field_key ?? null);
const groupByBucket = ref<string | null>(definition?.group_by_bucket ?? null);
const seriesFieldKey = ref<string | null>(definition?.series_field_key ?? null);
const filterSeed = ref<FilterGroupNode | undefined>(hydratableFilterTree(definition?.filter_definition ?? null));
const filterTree = ref<FilterGroupNode | null>(filterSeed.value ?? null);
const filterVersion = ref<number>(0);

const prefillSegmentId = ref<string | null>(null);

const segmentSource = useReportDefinition({
  report: null,
  objectTypeOptions: [],
});

const heading = computed<string>(() =>
  props.widget === null ? t(WIDGET_EDITOR_CREATE_TITLE) : t(WIDGET_EDITOR_EDIT_TITLE),
);

const segmentOptions = computed<SelectOption[]>(() =>
  objectTypeId.value === null ? [] : (props.segmentsByType[objectTypeId.value] ?? []),
);

const isPrefilling = computed<boolean>(() => segmentSource.prefillState.value === 'loading');

const prefillFailed = computed<boolean>(() => segmentSource.prefillState.value === 'error');

const fields = computed<FieldDefinition[]>(() =>
  objectTypeId.value === null ? [] : (props.fieldsByType[objectTypeId.value] ?? []),
);

const linkedFields = computed<FieldDefinition[]>(() =>
  objectTypeId.value === null ? [] : (props.linkedFieldsByType[objectTypeId.value] ?? []),
);

const spanOptions = COLUMN_SPAN_VALUES.map((span) => ({
  value: String(span),
  label: t(COLUMN_SPAN_LABELS[span]),
}));

const presentationOptions = Object.entries(REPORT_PRESENTATION_LABELS).map(([value, label]) => ({
  value,
  label: t(label),
}));

const definitionErrors = computed(() => ({
  aggregation_type: props.errors.aggregation_type,
  aggregation_field_key: props.errors.aggregation_field_key,
  group_by_field_key: props.errors.group_by_field_key,
  group_by_bucket: props.errors.group_by_bucket,
  series_field_key: props.errors.series_field_key,
  chart_type: props.errors.chart_type,
}));

function snapshot(): unknown {
  return {
    source: source.value,
    reportId: reportId.value,
    goalId: goalId.value,
    objectTypeId: objectTypeId.value,
    title: title.value,
    columnSpan: columnSpan.value,
    chartType: chartType.value,
    aggregationType: aggregationType.value,
    aggregationFieldKey: aggregationFieldKey.value,
    groupByFieldKey: groupByFieldKey.value,
    groupByBucket: groupByBucket.value,
    seriesFieldKey: seriesFieldKey.value,
    filterTree: filterTree.value,
  };
}

const { isDirty, promptOpen, confirmLeave, cancelLeave } = useUnsavedChanges({
  values: snapshot,
  backHref: DashboardsController.show.url({ dashboard: props.dashboardId }),
});

const { closeRequested, onCancel, onConfirmLeave, onCancelLeave, onOpenChange } = useGuardedSheet({
  isDirty,
  confirmLeave,
  cancelLeave,
  close: () => emit('close'),
});

watch(source, (next) => {
  if (next !== 'goal') {
    goalId.value = null;
  }

  if (next === 'report') {
    objectTypeId.value = null;

    return;
  }

  if (next === 'goal') {
    objectTypeId.value = null;
    reportId.value = null;

    return;
  }

  reportId.value = null;
});

watch(
  objectTypeId,
  () => {
    aggregationFieldKey.value = null;
    groupByFieldKey.value = null;
    groupByBucket.value = null;
    seriesFieldKey.value = null;
    filterSeed.value = undefined;
    filterTree.value = null;
    filterVersion.value += 1;
  },
  { flush: 'sync' },
);

function onSourceChange(value: unknown): void {
  source.value = value === 'adhoc' || value === 'goal' ? (value as WidgetSource) : 'report';
}

function onFilterChange(tree: FilterGroupNode): void {
  filterTree.value = normalizeFilterTree(tree);
}

async function applySegmentPrefill(): Promise<void> {
  const segmentId = prefillSegmentId.value;

  if (segmentId === null || segmentId === '') {
    return;
  }

  await segmentSource.applySegmentPrefill(segmentId);

  if (segmentSource.prefillState.value === 'error') {
    return;
  }

  filterSeed.value = segmentSource.filterSeed.value;
  filterTree.value = segmentSource.filterSeed.value ?? null;
  filterVersion.value += 1;
}

function buildPayload(): Record<string, unknown> {
  const base: Record<string, unknown> = {
    title: title.value.trim() === '' ? null : title.value.trim(),
    chart_type: chartType.value,
    column_span: clampColumnSpan(Number(columnSpan.value)),
  };

  if (source.value === 'goal') {
    return {
      title: base.title,
      column_span: base.column_span,
      goal_id: goalId.value,
    };
  }

  if (source.value === 'report') {
    return { ...base, report_id: reportId.value };
  }

  if (source.value === 'module') {
    return { ...base, source_key: sourceKey };
  }

  return {
    ...base,
    object_type_id: objectTypeId.value,
    filter_definition: filterTree.value,
    aggregation_type: aggregationType.value,
    aggregation_field_key: aggregationFieldKey.value,
    group_by_field_key: groupByFieldKey.value,
    group_by_bucket: groupByBucket.value as ReportGroupingBucket | null,
    series_field_key: seriesFieldKey.value,
  };
}

function onSubmit(): void {
  emit('submit', buildPayload());
}
</script>

<template>
  <div>
    <Sheet :open="true" @update:open="onOpenChange">
      <SheetContent side="right" class="w-full sm:max-w-xl">
        <div data-widget-editor class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4">
          <SheetHeader class="px-0">
            <SheetTitle>{{ heading }}</SheetTitle>
            <SheetDescription>{{ SHEET_DESCRIPTION }}</SheetDescription>
          </SheetHeader>

          <p v-if="source === 'module'" data-widget-module-hint class="text-sm text-muted-foreground">
            {{ MODULE_HINT }}
          </p>
          <FormField
            v-else
            for="widget-source"
            :label="t('i18n.components.dashboards.widget_editor_sheet.tile_source')"
          >
            <Select :model-value="source" data-widget-source-select @update:model-value="onSourceChange">
              <SelectTrigger id="widget-source" class="w-full">
                <SelectValue :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_source')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="option in SOURCE_OPTIONS" :key="option.value" :value="option.value">
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </FormField>

          <template v-if="source === 'goal'">
            <div v-if="props.goalOptions.length === 0" class="grid gap-2">
              <p data-widget-goal-empty class="text-sm text-muted-foreground">
                {{ NO_GOAL_HINT }}
              </p>
              <InputError :message="props.errors.goal_id" />
            </div>
            <FormField v-else for="widget-goal" :label="GOAL_LABEL" :error="props.errors.goal_id" :hint="GOAL_HINT">
              <Combobox
                id="widget-goal"
                v-model="goalId"
                :options="props.goalOptions"
                :searchable="props.goalOptions.length > SEARCHABLE_THRESHOLD"
                :aria-label="GOAL_LABEL"
                :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_goal')"
                :empty-label="t('i18n.components.dashboards.widget_editor_sheet.no_goals_available')"
                data-widget-goal-select
              />
            </FormField>
          </template>

          <template v-else-if="source === 'report'">
            <div v-if="props.reportOptions.length === 0" class="grid gap-2">
              <p data-widget-report-empty class="text-sm text-muted-foreground">
                {{ NO_REPORT_HINT }}
              </p>
              <InputError :message="props.errors.report_id" />
            </div>
            <FormField
              v-else
              for="widget-report"
              :label="t('i18n.components.dashboards.widget_editor_sheet.report')"
              :error="props.errors.report_id"
            >
              <Combobox
                id="widget-report"
                v-model="reportId"
                :options="props.reportOptions"
                :searchable="props.reportOptions.length > SEARCHABLE_THRESHOLD"
                :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_report')"
                :empty-label="t('i18n.components.dashboards.widget_editor_sheet.no_reports_available')"
                :aria-label="t('i18n.components.dashboards.widget_editor_sheet.report')"
                data-widget-report-select
              />
            </FormField>
          </template>

          <template v-else-if="source === 'adhoc'">
            <FormField
              for="widget-object-type"
              :label="t('i18n.components.dashboards.widget_editor_sheet.object_type')"
              :error="props.errors.object_type_id"
            >
              <Combobox
                id="widget-object-type"
                v-model="objectTypeId"
                :options="props.objectTypeOptions"
                :searchable="props.objectTypeOptions.length > SEARCHABLE_THRESHOLD"
                :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_object_type')"
                :empty-label="t('i18n.components.dashboards.widget_editor_sheet.no_object_types_available')"
                :aria-label="t('i18n.components.dashboards.widget_editor_sheet.object_type')"
                data-widget-object-type-select
              />
            </FormField>

            <FormField
              for="widget-segment-prefill"
              :label="SEGMENT_PREFILL_LABEL"
              :error="prefillFailed ? t(WIDGET_SEGMENT_PREFILL_ERROR) : undefined"
            >
              <div class="flex flex-wrap items-center gap-2">
                <Combobox
                  id="widget-segment-prefill"
                  v-model="prefillSegmentId"
                  class="sm:max-w-sm"
                  :options="segmentOptions"
                  :searchable="segmentOptions.length > SEARCHABLE_THRESHOLD"
                  :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_segment')"
                  :empty-label="t('i18n.components.dashboards.widget_editor_sheet.no_segments_available')"
                  :aria-label="t('i18n.components.dashboards.widget_editor_sheet.segment')"
                  data-widget-segment-select
                />
                <Button
                  type="button"
                  variant="outline"
                  :disabled="prefillSegmentId === null || isPrefilling"
                  data-widget-segment-apply
                  @click="applySegmentPrefill"
                >
                  {{ SEGMENT_PREFILL_ACTION }}
                </Button>
              </div>
              <p class="text-xs text-muted-foreground">
                {{ SEGMENT_PREFILL_HINT }}
              </p>
            </FormField>

            <FormField
              :label="t('i18n.components.dashboards.widget_editor_sheet.restriction')"
              :error="props.errors.filter_definition"
            >
              <FilterBuilder
                :key="filterVersion"
                :fields="fields"
                :model-value="filterSeed"
                :show-actions="false"
                @update:model-value="onFilterChange"
              />
            </FormField>

            <ReportDefinitionFields
              v-model:aggregation-type="aggregationType"
              v-model:aggregation-field-key="aggregationFieldKey"
              v-model:group-by-field-key="groupByFieldKey"
              v-model:group-by-bucket="groupByBucket"
              v-model:series-field-key="seriesFieldKey"
              v-model:chart-type="chartType"
              :fields="fields"
              :linked-fields="linkedFields"
              :errors="definitionErrors"
            />
          </template>

          <FormField
            v-if="source === 'report' || source === 'module'"
            for="widget-chart-type"
            :label="t('i18n.components.dashboards.widget_editor_sheet.display')"
            :error="props.errors.chart_type"
          >
            <Select v-model="chartType" data-widget-chart-type-select>
              <SelectTrigger id="widget-chart-type" class="w-full">
                <SelectValue :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_display')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="option in presentationOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </FormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField
              for="widget-title"
              :label="t('i18n.components.dashboards.widget_editor_sheet.tile_title')"
              :error="props.errors.title"
            >
              <Input
                id="widget-title"
                v-model="title"
                data-widget-title-input
                :placeholder="t('i18n.components.dashboards.widget_editor_sheet.optional')"
              />
            </FormField>

            <FormField
              for="widget-column-span"
              :label="t('i18n.components.dashboards.widget_editor_sheet.tile_width')"
              :error="props.errors.column_span"
            >
              <Select v-model="columnSpan" data-widget-editor-span>
                <SelectTrigger id="widget-column-span" class="w-full">
                  <SelectValue :placeholder="t('i18n.components.dashboards.widget_editor_sheet.select_width')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in spanOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>
          </div>

          <FormActions
            type="button"
            :dirty="isDirty"
            :processing="props.processing"
            @cancel="onCancel"
            @save="onSubmit"
          />
        </div>
      </SheetContent>
    </Sheet>

    <UnsavedChangesDialog :open="promptOpen || closeRequested" @confirm="onConfirmLeave" @cancel="onCancelLeave" />
  </div>
</template>
