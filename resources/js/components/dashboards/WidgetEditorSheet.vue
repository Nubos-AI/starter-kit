<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FormActions from '@/components/FormActions.vue';
import InputError from '@/components/InputError.vue';
import ReportDefinitionFields from '@/components/reports/ReportDefinitionFields.vue';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { useReportDefinition } from '@/composables/useReportDefinition';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import type { DashboardWidgetMeta } from '@/types/dashboards';
import {
    clampColumnSpan,
    COLUMN_SPAN_LABELS,
    COLUMN_SPAN_VALUES,
    WIDGET_EDITOR_CREATE_TITLE,
    WIDGET_EDITOR_EDIT_TITLE,
    WIDGET_SEGMENT_PREFILL_ERROR,
} from '@/types/dashboards';
import type { FieldDefinition } from '@/types/fields';
import type {
    ReportAggregation,
    ReportGroupingBucket,
    ReportPresentation,
} from '@/types/reports';
import {
    hydratableFilterTree,
    normalizeFilterTree,
    REPORT_PRESENTATION_LABELS,
} from '@/types/reports';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

type WidgetSource = 'report' | 'adhoc' | 'goal';

const SEARCHABLE_THRESHOLD = 8;

const SOURCE_OPTIONS: SelectOption[] = [
    {
        value: 'report',
        label: t('i18n.components.dashboards.widget_editor_sheet.saved_report'),
    },
    {
        value: 'adhoc',
        label: t(
            'i18n.components.dashboards.widget_editor_sheet.custom_definition',
        ),
    },
    {
        value: 'goal',
        label: t('i18n.components.dashboards.widget_editor_sheet.goal'),
    },
];

const GOAL_LABEL = t('i18n.components.dashboards.widget_editor_sheet.goal');

const NO_GOAL_HINT = t(
    'i18n.components.dashboards.widget_editor_sheet.there_are_no_goals_you_can_view_yet_create',
);

const GOAL_HINT = t(
    'i18n.components.dashboards.widget_editor_sheet.this_tile_compares_the_current_period_s_actual_value',
);

const SHEET_DESCRIPTION = t(
    'i18n.components.dashboards.widget_editor_sheet.select_a_saved_report_or_create_a_custom_definition',
);

const NO_REPORT_HINT = t(
    'i18n.components.dashboards.widget_editor_sheet.there_are_no_saved_reports_yet_create_a_report',
);

const SEGMENT_PREFILL_LABEL = t(
    'i18n.components.dashboards.widget_editor_sheet.prefill_from_segment',
);

const SEGMENT_PREFILL_ACTION = t(
    'i18n.components.dashboards.widget_editor_sheet.apply_filter',
);

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

function initialSource(): WidgetSource {
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
const aggregationType = ref<ReportAggregation>(
    definition?.aggregation_type ?? 'count',
);
const aggregationFieldKey = ref<string | null>(
    definition?.aggregation_field_key ?? null,
);
const groupByFieldKey = ref<string | null>(
    definition?.group_by_field_key ?? null,
);
const groupByBucket = ref<string | null>(definition?.group_by_bucket ?? null);
const seriesFieldKey = ref<string | null>(definition?.series_field_key ?? null);
const filterSeed = ref<FilterGroupNode | undefined>(
    hydratableFilterTree(definition?.filter_definition ?? null),
);
const filterTree = ref<FilterGroupNode | null>(filterSeed.value ?? null);
const filterVersion = ref<number>(0);

const prefillSegmentId = ref<string | null>(null);

const closeRequested = ref<boolean>(false);

const segmentSource = useReportDefinition({
    report: null,
    objectTypeOptions: [],
});

const heading = computed<string>(() =>
    props.widget === null
        ? WIDGET_EDITOR_CREATE_TITLE
        : WIDGET_EDITOR_EDIT_TITLE,
);

const segmentOptions = computed<SelectOption[]>(() =>
    objectTypeId.value === null
        ? []
        : (props.segmentsByType[objectTypeId.value] ?? []),
);

const isPrefilling = computed<boolean>(
    () => segmentSource.prefillState.value === 'loading',
);

const prefillFailed = computed<boolean>(
    () => segmentSource.prefillState.value === 'error',
);

const fields = computed<FieldDefinition[]>(() =>
    objectTypeId.value === null
        ? []
        : (props.fieldsByType[objectTypeId.value] ?? []),
);

const linkedFields = computed<FieldDefinition[]>(() =>
    objectTypeId.value === null
        ? []
        : (props.linkedFieldsByType[objectTypeId.value] ?? []),
);

const spanOptions = COLUMN_SPAN_VALUES.map((span) => ({
    value: String(span),
    label: COLUMN_SPAN_LABELS[span],
}));

const presentationOptions = Object.entries(REPORT_PRESENTATION_LABELS).map(
    ([value, label]) => ({ value, label }),
);

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
    source.value =
        value === 'adhoc' || value === 'goal'
            ? (value as WidgetSource)
            : 'report';
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

function onCancel(): void {
    if (isDirty.value) {
        closeRequested.value = true;

        return;
    }

    emit('close');
}

function onConfirmLeave(): void {
    if (closeRequested.value) {
        closeRequested.value = false;
        emit('close');

        return;
    }

    confirmLeave();
}

function onCancelLeave(): void {
    closeRequested.value = false;
    cancelLeave();
}

function onOpenChange(next: boolean): void {
    if (!next) {
        onCancel();
    }
}
</script>

<template>
    <div>
        <Sheet :open="true" @update:open="onOpenChange">
            <SheetContent side="right" class="w-full sm:max-w-xl">
                <div
                    data-widget-editor
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4"
                >
                    <SheetHeader class="px-0">
                        <SheetTitle>{{ heading }}</SheetTitle>
                        <SheetDescription>{{
                            SHEET_DESCRIPTION
                        }}</SheetDescription>
                    </SheetHeader>

                    <div class="grid gap-2">
                        <Label for="widget-source">{{
                            t(
                                'i18n.components.dashboards.widget_editor_sheet.tile_source',
                            )
                        }}</Label>
                        <Select
                            :model-value="source"
                            data-widget-source-select
                            @update:model-value="onSourceChange"
                        >
                            <SelectTrigger id="widget-source" class="w-full">
                                <SelectValue
                                    :placeholder="
                                        t(
                                            'i18n.components.dashboards.widget_editor_sheet.select_source',
                                        )
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in SOURCE_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <template v-if="source === 'goal'">
                        <div
                            v-if="props.goalOptions.length === 0"
                            class="grid gap-2"
                        >
                            <p
                                data-widget-goal-empty
                                class="text-sm text-muted-foreground"
                            >
                                {{ NO_GOAL_HINT }}
                            </p>
                            <InputError :message="props.errors.goal_id" />
                        </div>
                        <div v-else class="grid gap-2">
                            <Label for="widget-goal">{{ GOAL_LABEL }}</Label>
                            <Combobox
                                id="widget-goal"
                                v-model="goalId"
                                :options="props.goalOptions"
                                :searchable="
                                    props.goalOptions.length >
                                    SEARCHABLE_THRESHOLD
                                "
                                :aria-label="GOAL_LABEL"
                                :placeholder="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.select_goal',
                                    )
                                "
                                :empty-label="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.no_goals_available',
                                    )
                                "
                                data-widget-goal-select
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ GOAL_HINT }}
                            </p>
                            <InputError :message="props.errors.goal_id" />
                        </div>
                    </template>

                    <template v-else-if="source === 'report'">
                        <div
                            v-if="props.reportOptions.length === 0"
                            class="grid gap-2"
                        >
                            <p
                                data-widget-report-empty
                                class="text-sm text-muted-foreground"
                            >
                                {{ NO_REPORT_HINT }}
                            </p>
                            <InputError :message="props.errors.report_id" />
                        </div>
                        <div v-else class="grid gap-2">
                            <Label for="widget-report">{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.report',
                                )
                            }}</Label>
                            <Combobox
                                id="widget-report"
                                v-model="reportId"
                                :options="props.reportOptions"
                                :searchable="
                                    props.reportOptions.length >
                                    SEARCHABLE_THRESHOLD
                                "
                                :placeholder="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.select_report',
                                    )
                                "
                                :empty-label="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.no_reports_available',
                                    )
                                "
                                :aria-label="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.report',
                                    )
                                "
                                data-widget-report-select
                            />
                            <InputError :message="props.errors.report_id" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="widget-chart-type">{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.display',
                                )
                            }}</Label>
                            <Select v-model="chartType">
                                <SelectTrigger
                                    id="widget-chart-type"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.dashboards.widget_editor_sheet.select_display',
                                            )
                                        "
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="option in presentationOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="props.errors.chart_type" />
                        </div>
                    </template>

                    <template v-else>
                        <div class="grid gap-2">
                            <Label for="widget-object-type">{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.object_type',
                                )
                            }}</Label>
                            <Combobox
                                id="widget-object-type"
                                v-model="objectTypeId"
                                :options="props.objectTypeOptions"
                                :searchable="
                                    props.objectTypeOptions.length >
                                    SEARCHABLE_THRESHOLD
                                "
                                :placeholder="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.select_object_type',
                                    )
                                "
                                :empty-label="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.no_object_types_available',
                                    )
                                "
                                :aria-label="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.object_type',
                                    )
                                "
                                data-widget-object-type-select
                            />
                            <InputError
                                :message="props.errors.object_type_id"
                            />
                        </div>

                        <div class="flex flex-col gap-2">
                            <Label for="widget-segment-prefill">
                                {{ SEGMENT_PREFILL_LABEL }}
                            </Label>
                            <div class="flex flex-wrap items-center gap-2">
                                <Combobox
                                    id="widget-segment-prefill"
                                    v-model="prefillSegmentId"
                                    class="sm:max-w-sm"
                                    :options="segmentOptions"
                                    :searchable="
                                        segmentOptions.length >
                                        SEARCHABLE_THRESHOLD
                                    "
                                    :placeholder="
                                        t(
                                            'i18n.components.dashboards.widget_editor_sheet.select_segment',
                                        )
                                    "
                                    :empty-label="
                                        t(
                                            'i18n.components.dashboards.widget_editor_sheet.no_segments_available',
                                        )
                                    "
                                    :aria-label="
                                        t(
                                            'i18n.components.dashboards.widget_editor_sheet.segment',
                                        )
                                    "
                                    data-widget-segment-select
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        prefillSegmentId === null ||
                                        isPrefilling
                                    "
                                    data-widget-segment-apply
                                    @click="applySegmentPrefill"
                                >
                                    {{ SEGMENT_PREFILL_ACTION }}
                                </Button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ SEGMENT_PREFILL_HINT }}
                            </p>
                            <p
                                v-if="prefillFailed"
                                data-widget-segment-error
                                class="text-sm text-destructive"
                            >
                                {{ WIDGET_SEGMENT_PREFILL_ERROR }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label>{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.restriction',
                                )
                            }}</Label>
                            <FilterBuilder
                                :key="filterVersion"
                                :fields="fields"
                                :model-value="filterSeed"
                                :show-actions="false"
                                @update:model-value="onFilterChange"
                            />
                            <InputError
                                :message="props.errors.filter_definition"
                            />
                        </div>

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

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="widget-title">{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.tile_title',
                                )
                            }}</Label>
                            <Input
                                id="widget-title"
                                v-model="title"
                                data-widget-title-input
                                :placeholder="
                                    t(
                                        'i18n.components.dashboards.widget_editor_sheet.optional',
                                    )
                                "
                            />
                            <InputError :message="props.errors.title" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="widget-column-span">{{
                                t(
                                    'i18n.components.dashboards.widget_editor_sheet.tile_width',
                                )
                            }}</Label>
                            <Select
                                v-model="columnSpan"
                                data-widget-editor-span
                            >
                                <SelectTrigger
                                    id="widget-column-span"
                                    class="w-full"
                                >
                                    <SelectValue
                                        :placeholder="
                                            t(
                                                'i18n.components.dashboards.widget_editor_sheet.select_width',
                                            )
                                        "
                                    />
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
                            <InputError :message="props.errors.column_span" />
                        </div>
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

        <UnsavedChangesDialog
            :open="promptOpen || closeRequested"
            @confirm="onConfirmLeave"
            @cancel="onCancelLeave"
        />
    </div>
</template>
