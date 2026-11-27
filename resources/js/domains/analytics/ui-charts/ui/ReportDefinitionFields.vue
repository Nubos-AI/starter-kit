<script setup lang="ts">
import { computed, watch } from 'vue';
import type { ReportAggregation, ReportPresentation } from '@/domains/analytics/data';
import {
  aggregationAllowsFieldType,
  isQualifiedFieldKey,
  linkedAggregationAllowed,
  REPORT_AGGREGATION_LABELS,
  REPORT_BUCKET_LABELS,
  REPORT_PRESENTATION_LABELS,
  reportFieldDisabledReason,
} from '@/domains/analytics/data';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import type { SelectOption } from '@/shared/types/ui';
import FormField from '@/shared/ui/FormField.vue';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';

const { t } = useI18n();

interface ReportDefinitionErrors {
  aggregation_type?: string;
  aggregation_field_key?: string;
  group_by_field_key?: string;
  group_by_bucket?: string;
  series_field_key?: string;
  chart_type?: string;
}

const SEARCHABLE_THRESHOLD = 8;

function labelOptions(labels: Record<string, string>): SelectOption[] {
  return Object.entries(labels).map(([value, label]) => ({
    value,
    label: t(label),
  }));
}

const props = defineProps<{
  fields: FieldDefinition[];
  linkedFields: FieldDefinition[];
  errors: ReportDefinitionErrors;
}>();

const aggregationType = defineModel<ReportAggregation>('aggregationType', {
  required: true,
});
const aggregationFieldKey = defineModel<string | null>('aggregationFieldKey', {
  required: true,
});
const groupByFieldKey = defineModel<string | null>('groupByFieldKey', {
  required: true,
});
const groupByBucket = defineModel<string | null>('groupByBucket', {
  required: true,
});
const seriesFieldKey = defineModel<string | null>('seriesFieldKey', {
  required: true,
});
const chartType = defineModel<ReportPresentation>('chartType', {
  required: true,
});

const groupableFields = computed<FieldDefinition[]>(() => [...props.fields, ...props.linkedFields]);

const aggregationOptions = computed<SelectOption[]>(() => labelOptions(REPORT_AGGREGATION_LABELS));

const bucketOptions = computed<SelectOption[]>(() => labelOptions(REPORT_BUCKET_LABELS));

const hasGrouping = computed<boolean>(() => groupByFieldKey.value !== null && groupByFieldKey.value !== '');

const presentationOptions = computed<SelectOption[]>(() =>
  labelOptions(REPORT_PRESENTATION_LABELS).map((option) => ({
    ...option,
    disabled: !hasGrouping.value && option.value !== 'metric',
  })),
);

const aggregationFieldOptions = computed<SelectOption[]>(() =>
  groupableFields.value.map((field) => {
    const qualified = isQualifiedFieldKey(field.key);
    const disabled =
      !aggregationAllowsFieldType(aggregationType.value, field.field_type) ||
      (qualified && !linkedAggregationAllowed(aggregationType.value));

    return {
      value: field.key,
      label: field.label,
      disabled,
      disabledReason: disabled ? reportFieldDisabledReason(aggregationType.value, qualified, t) : undefined,
    };
  }),
);

const groupByFieldOptions = computed<SelectOption[]>(() =>
  groupableFields.value.map((field) => ({
    value: field.key,
    label: field.label,
  })),
);

const seriesFieldOptions = computed<SelectOption[]>(() =>
  props.fields.map((field) => ({ value: field.key, label: field.label })),
);

const groupingField = computed<FieldDefinition | undefined>(() =>
  groupableFields.value.find((field) => field.key === groupByFieldKey.value),
);

const showBucket = computed<boolean>(
  () => groupingField.value?.field_type === 'date' || groupingField.value?.field_type === 'datetime',
);

watch(showBucket, (visible) => {
  if (!visible && groupByBucket.value !== null) {
    groupByBucket.value = null;
  }
});

watch(
  hasGrouping,
  (grouped) => {
    if (!grouped && chartType.value !== 'metric') {
      chartType.value = 'metric';
    }
  },
  { immediate: true },
);
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <FormField
      data-report-field="aggregation-type"
      for="report-aggregation-type"
      :label="t('i18n.components.reports.report_definition_fields.calculation')"
      :error="props.errors.aggregation_type"
    >
      <Select v-model="aggregationType">
        <SelectTrigger id="report-aggregation-type" class="w-full">
          <SelectValue :placeholder="t('i18n.components.reports.report_definition_fields.select_calculation')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in aggregationOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField
      data-report-field="aggregation-field"
      for="report-aggregation-field"
      :label="t('i18n.components.reports.report_definition_fields.calculation_field')"
      :error="props.errors.aggregation_field_key"
    >
      <Combobox
        id="report-aggregation-field"
        v-model="aggregationFieldKey"
        :options="aggregationFieldOptions"
        :searchable="aggregationFieldOptions.length > SEARCHABLE_THRESHOLD"
        :placeholder="t('i18n.components.reports.report_definition_fields.select_field')"
        :empty-label="t('i18n.components.reports.report_definition_fields.no_fields_available')"
      />
    </FormField>

    <FormField
      data-report-field="group-by-field"
      for="report-group-by-field"
      :label="t('i18n.components.reports.report_definition_fields.grouping')"
      :error="props.errors.group_by_field_key"
    >
      <Combobox
        id="report-group-by-field"
        v-model="groupByFieldKey"
        :options="groupByFieldOptions"
        :searchable="groupByFieldOptions.length > SEARCHABLE_THRESHOLD"
        :placeholder="t('i18n.components.reports.report_definition_fields.select_field')"
        :empty-label="t('i18n.components.reports.report_definition_fields.no_fields_available')"
      />
    </FormField>

    <FormField
      v-if="showBucket"
      data-report-field="group-by-bucket"
      for="report-group-by-bucket"
      :label="t('i18n.components.reports.report_definition_fields.grouping_period')"
      :error="props.errors.group_by_bucket"
    >
      <Select v-model="groupByBucket">
        <SelectTrigger id="report-group-by-bucket" class="w-full">
          <SelectValue :placeholder="t('i18n.components.reports.report_definition_fields.select_period')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem v-for="option in bucketOptions" :key="option.value" :value="option.value">
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField
      data-report-field="series-field"
      for="report-series-field"
      :label="t('i18n.components.reports.report_definition_fields.second_dimension')"
      :error="props.errors.series_field_key"
    >
      <Combobox
        id="report-series-field"
        v-model="seriesFieldKey"
        :options="seriesFieldOptions"
        :searchable="seriesFieldOptions.length > SEARCHABLE_THRESHOLD"
        :placeholder="t('i18n.components.reports.report_definition_fields.select_field')"
        :empty-label="t('i18n.components.reports.report_definition_fields.no_fields_available')"
      />
    </FormField>

    <FormField
      data-report-field="chart-type"
      for="report-chart-type"
      :label="t('i18n.components.reports.report_definition_fields.display')"
      :error="props.errors.chart_type"
    >
      <Select v-model="chartType">
        <SelectTrigger id="report-chart-type" class="w-full">
          <SelectValue :placeholder="t('i18n.components.reports.report_definition_fields.select_display')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="option in presentationOptions"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
          >
            {{ option.label }}
          </SelectItem>
        </SelectContent>
      </Select>
      <p v-if="!hasGrouping" data-report-presentation-hint class="text-xs text-muted-foreground">
        {{ t('i18n.components.reports.report_definition_fields.without_grouping_the_result_is_a_single_value_charts') }}
      </p>
    </FormField>
  </div>
</template>
