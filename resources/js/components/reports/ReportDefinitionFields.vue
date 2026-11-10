<script setup lang="ts">
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Combobox } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useI18n } from '@/composables/useI18n';
import type { FieldDefinition } from '@/types/fields';
import type { ReportAggregation, ReportPresentation } from '@/types/reports';
import {
    aggregationAllowsFieldType,
    isQualifiedFieldKey,
    linkedAggregationAllowed,
    REPORT_AGGREGATION_LABELS,
    REPORT_BUCKET_LABELS,
    REPORT_PRESENTATION_LABELS,
    reportFieldDisabledReason,
} from '@/types/reports';
import type { SelectOption } from '@/types/ui';

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
    return Object.entries(labels).map(([value, label]) => ({ value, label }));
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

const groupableFields = computed<FieldDefinition[]>(() => [
    ...props.fields,
    ...props.linkedFields,
]);

const aggregationOptions = computed<SelectOption[]>(() =>
    labelOptions(REPORT_AGGREGATION_LABELS),
);

const bucketOptions = computed<SelectOption[]>(() =>
    labelOptions(REPORT_BUCKET_LABELS),
);

const hasGrouping = computed<boolean>(
    () => groupByFieldKey.value !== null && groupByFieldKey.value !== '',
);

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
            !aggregationAllowsFieldType(
                aggregationType.value,
                field.field_type,
            ) ||
            (qualified && !linkedAggregationAllowed(aggregationType.value));

        return {
            value: field.key,
            label: field.label,
            disabled,
            disabledReason: disabled
                ? reportFieldDisabledReason(aggregationType.value, qualified)
                : undefined,
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
    () =>
        groupingField.value?.field_type === 'date' ||
        groupingField.value?.field_type === 'datetime',
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
        <div class="grid gap-2" data-report-field="aggregation-type">
            <Label for="report-aggregation-type">{{
                t(
                    'i18n.components.reports.report_definition_fields.calculation',
                )
            }}</Label>
            <Select v-model="aggregationType">
                <SelectTrigger id="report-aggregation-type" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.reports.report_definition_fields.select_calculation',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in aggregationOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="props.errors.aggregation_type" />
        </div>

        <div class="grid gap-2" data-report-field="aggregation-field">
            <Label for="report-aggregation-field">{{
                t(
                    'i18n.components.reports.report_definition_fields.calculation_field',
                )
            }}</Label>
            <Combobox
                id="report-aggregation-field"
                v-model="aggregationFieldKey"
                :options="aggregationFieldOptions"
                :searchable="
                    aggregationFieldOptions.length > SEARCHABLE_THRESHOLD
                "
                :placeholder="
                    t(
                        'i18n.components.reports.report_definition_fields.select_field',
                    )
                "
                :empty-label="
                    t(
                        'i18n.components.reports.report_definition_fields.no_fields_available',
                    )
                "
            />
            <InputError :message="props.errors.aggregation_field_key" />
        </div>

        <div class="grid gap-2" data-report-field="group-by-field">
            <Label for="report-group-by-field">{{
                t('i18n.components.reports.report_definition_fields.grouping')
            }}</Label>
            <Combobox
                id="report-group-by-field"
                v-model="groupByFieldKey"
                :options="groupByFieldOptions"
                :searchable="groupByFieldOptions.length > SEARCHABLE_THRESHOLD"
                :placeholder="
                    t(
                        'i18n.components.reports.report_definition_fields.select_field',
                    )
                "
                :empty-label="
                    t(
                        'i18n.components.reports.report_definition_fields.no_fields_available',
                    )
                "
            />
            <InputError :message="props.errors.group_by_field_key" />
        </div>

        <div
            v-if="showBucket"
            class="grid gap-2"
            data-report-field="group-by-bucket"
        >
            <Label for="report-group-by-bucket">{{
                t(
                    'i18n.components.reports.report_definition_fields.grouping_period',
                )
            }}</Label>
            <Select v-model="groupByBucket">
                <SelectTrigger id="report-group-by-bucket" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.reports.report_definition_fields.select_period',
                            )
                        "
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in bucketOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="props.errors.group_by_bucket" />
        </div>

        <div class="grid gap-2" data-report-field="series-field">
            <Label for="report-series-field">{{
                t(
                    'i18n.components.reports.report_definition_fields.second_dimension',
                )
            }}</Label>
            <Combobox
                id="report-series-field"
                v-model="seriesFieldKey"
                :options="seriesFieldOptions"
                :searchable="seriesFieldOptions.length > SEARCHABLE_THRESHOLD"
                :placeholder="
                    t(
                        'i18n.components.reports.report_definition_fields.select_field',
                    )
                "
                :empty-label="
                    t(
                        'i18n.components.reports.report_definition_fields.no_fields_available',
                    )
                "
            />
            <InputError :message="props.errors.series_field_key" />
        </div>

        <div class="grid gap-2" data-report-field="chart-type">
            <Label for="report-chart-type">{{
                t('i18n.components.reports.report_definition_fields.display')
            }}</Label>
            <Select v-model="chartType">
                <SelectTrigger id="report-chart-type" class="w-full">
                    <SelectValue
                        :placeholder="
                            t(
                                'i18n.components.reports.report_definition_fields.select_display',
                            )
                        "
                    />
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
            <p
                v-if="!hasGrouping"
                data-report-presentation-hint
                class="text-xs text-muted-foreground"
            >
                {{
                    t(
                        'i18n.components.reports.report_definition_fields.without_grouping_the_result_is_a_single_value_charts',
                    )
                }}
            </p>
            <InputError :message="props.errors.chart_type" />
        </div>
    </div>
</template>
