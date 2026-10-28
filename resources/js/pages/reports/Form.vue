<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ReportDefinitionFields from '@/components/reports/ReportDefinitionFields.vue';
import ReportPreview from '@/components/reports/ReportPreview.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import { usePermissions } from '@/composables/usePermissions';
import { useReportDefinition } from '@/composables/useReportDefinition';
import type { ReportDrillDownSource } from '@/composables/useReportDrillDown';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';
import { REPORT_EXECUTION_MODE } from '@/lib/statusMaps';
import type { FieldDefinition } from '@/types/fields';
import type { ReportGroupingBucket, ReportListRow } from '@/types/reports';
import { REPORT_BUCKET_LABELS } from '@/types/reports';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    mode: 'create' | 'edit';
    report: ReportListRow | null;
    objectTypeOptions: SelectOption[];
    fieldsByType: Record<string, FieldDefinition[]>;
    linkedFieldsByType: Record<string, FieldDefinition[]>;
    segmentsByType: Record<string, SelectOption[]>;
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
    isEdit.value
        ? (props.report?.name ?? t('i18n.pages.reports.form.report'))
        : t('i18n.pages.reports.form.new_report'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const { isEscalated } = usePermissions();

const {
    name,
    description,
    objectTypeId,
    aggregationType,
    aggregationFieldKey,
    groupByFieldKey,
    groupByBucket,
    seriesFieldKey,
    chartType,
    executionMode,
    filterSeed,
    filterTree,
    filterKey,
    prefillState,
    snapshot,
    savePayload,
    previewPayload,
    applySegmentPrefill,
} = useReportDefinition({
    report: props.report,
    objectTypeOptions: props.objectTypeOptions,
});

const form = useForm<Record<string, string>>({});

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => snapshot(),
    backHref: ReportsController.index.url(),
});

const prefillSegmentId = ref<string | null>(null);

const fields = computed<FieldDefinition[]>(
    () => props.fieldsByType[objectTypeId.value] ?? [],
);

const linkedFields = computed<FieldDefinition[]>(
    () => props.linkedFieldsByType[objectTypeId.value] ?? [],
);

const segmentOptions = computed<SelectOption[]>(
    () => props.segmentsByType[objectTypeId.value] ?? [],
);

const objectTypeLabel = computed<string>(
    () =>
        props.objectTypeOptions.find(
            (option) => option.value === objectTypeId.value,
        )?.label ?? '—',
);

const definitionErrors = computed(() => ({
    aggregation_type: form.errors.aggregation_type,
    aggregation_field_key: form.errors.aggregation_field_key,
    group_by_field_key: form.errors.group_by_field_key,
    group_by_bucket: form.errors.group_by_bucket,
    series_field_key: form.errors.series_field_key,
    chart_type: form.errors.chart_type,
}));

const previewBucket = computed<ReportGroupingBucket | null>(() =>
    isGroupingBucket(groupByBucket.value) ? groupByBucket.value : null,
);

const drillDownSource = computed<ReportDrillDownSource | null>(() => {
    const report = props.report;

    if (!isEdit.value || report === null || report.object_type === null) {
        return null;
    }

    return isDirty.value
        ? null
        : { reportId: report.id, objectTypeSlug: report.object_type.slug };
});

function isGroupingBucket(value: string | null): value is ReportGroupingBucket {
    return (
        value !== null &&
        Object.prototype.hasOwnProperty.call(REPORT_BUCKET_LABELS, value)
    );
}

function onFilterChange(tree: FilterGroupNode): void {
    filterTree.value = tree;
}

function onExecutionModeChange(checked: boolean | 'indeterminate'): void {
    executionMode.value = checked === true ? 'definer' : 'viewer';
}

function applyPrefill(): void {
    const segmentId = prefillSegmentId.value;

    if (segmentId === null || segmentId === '') {
        return;
    }

    void applySegmentPrefill(segmentId);
}

function submitPayload(): Record<string, unknown> {
    const payload = savePayload();

    if (!isEscalated.value) {
        delete payload.execution_mode;
    }

    return payload;
}

function onSaved(): void {
    markSaved();
    toast.success(t('i18n.pages.reports.form.the_report_was_saved'));
}

function onSubmit(): void {
    const report = props.report;

    if (isEdit.value && report !== null) {
        form.transform(() => submitPayload()).put(
            ReportsController.update.url({ report: report.id }),
            { preserveScroll: true, onSuccess: onSaved },
        );

        return;
    }

    form.transform(() => submitPayload()).post(ReportsController.store.url(), {
        preserveScroll: true,
        onSuccess: onSaved,
    });
}
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.reports.form.new_report')
                : t('i18n.pages.reports.form.edit_report', {
                      value1: report?.name,
                  })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.reports.form.create_a_new_report_for_an_object_type',
                      )
                    : t(
                          'i18n.pages.reports.form.edit_this_report_s_definition_and_display',
                      )
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="onSubmit">
            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.reports.form.basic_information')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.reports.form.the_name_and_description_appear_in_the_list_and',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div
                        class="grid gap-2 sm:max-w-sm"
                        data-report-field="name"
                    >
                        <Label for="report-name">{{
                            t('i18n.pages.reports.form.name')
                        }}</Label>
                        <Input
                            id="report-name"
                            v-model="name"
                            :placeholder="
                                t('i18n.pages.reports.form.report_name')
                            "
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2" data-report-field="description">
                        <Label for="report-description">{{
                            t('i18n.pages.reports.form.description')
                        }}</Label>
                        <Textarea
                            id="report-description"
                            v-model="description"
                            :placeholder="
                                t(
                                    'i18n.pages.reports.form.what_is_this_report_for',
                                )
                            "
                            rows="2"
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.reports.form.data_source_and_filters')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.reports.form.the_object_type_determines_which_records_are_analysed_the',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <div
                        class="grid gap-2 sm:max-w-sm"
                        data-report-field="object-type"
                    >
                        <Label for="report-object-type">{{
                            t('i18n.pages.reports.form.object_type')
                        }}</Label>
                        <Combobox
                            v-if="!isEdit"
                            id="report-object-type"
                            v-model="objectTypeId"
                            :options="objectTypeOptions"
                            :searchable="objectTypeOptions.length > 8"
                            :placeholder="
                                t('i18n.pages.reports.form.select_object_type')
                            "
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            {{ objectTypeLabel }}
                        </p>
                        <InputError :message="form.errors.object_type_id" />
                    </div>

                    <div class="flex flex-col gap-2" data-report-prefill>
                        <Label for="report-segment-prefill">
                            {{
                                t(
                                    'i18n.pages.reports.form.prefill_from_segment',
                                )
                            }}
                        </Label>
                        <div class="flex flex-wrap items-center gap-2">
                            <Combobox
                                id="report-segment-prefill"
                                v-model="prefillSegmentId"
                                class="sm:max-w-sm"
                                :options="segmentOptions"
                                :searchable="segmentOptions.length > 8"
                                :placeholder="
                                    t('i18n.pages.reports.form.select_segment')
                                "
                                :empty-label="
                                    t(
                                        'i18n.pages.reports.form.no_segments_available',
                                    )
                                "
                            />
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="
                                    prefillSegmentId === null ||
                                    prefillState === 'loading'
                                "
                                @click="applyPrefill"
                            >
                                {{ t('i18n.pages.reports.form.apply_filter') }}
                            </Button>
                        </div>
                        <p
                            class="text-xs text-muted-foreground"
                            data-report-prefill-hint
                        >
                            {{
                                t(
                                    'i18n.pages.reports.form.the_segment_s_filter_is_copied_once_later_changes',
                                )
                            }}
                        </p>
                        <p
                            v-if="prefillState === 'error'"
                            class="text-sm text-danger"
                        >
                            {{
                                t(
                                    'i18n.pages.reports.form.the_segment_s_filter_could_not_be_applied',
                                )
                            }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Label>{{ t('i18n.pages.reports.form.filter') }}</Label>
                        <FilterBuilder
                            :key="filterKey"
                            :fields="fields"
                            :model-value="filterSeed"
                            :show-actions="false"
                            @update:model-value="onFilterChange"
                        />
                        <InputError :message="form.errors.filter_definition" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.reports.form.report')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.reports.form.calculation_grouping_and_display_determine_what_the_report_shows',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
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

                    <div
                        v-if="isEscalated"
                        class="flex flex-col gap-2"
                        data-report-execution-mode
                    >
                        <div class="flex items-center gap-2">
                            <Checkbox
                                id="report-execution-mode"
                                :model-value="executionMode === 'definer'"
                                @update:model-value="onExecutionModeChange"
                            />
                            <Label for="report-execution-mode">
                                {{
                                    t(
                                        'i18n.pages.reports.form.run_with_my_permissions',
                                    )
                                }}
                            </Label>
                            <StatusBadge
                                v-if="executionMode === 'definer'"
                                :map="REPORT_EXECUTION_MODE"
                                status="definer"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'i18n.pages.reports.form.everyone_who_opens_this_report_will_see_the_numbers',
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.execution_mode" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{
                        t('i18n.pages.reports.form.preview')
                    }}</CardTitle>
                    <CardDescription>
                        {{
                            t(
                                'i18n.pages.reports.form.the_preview_runs_the_current_definition_without_saving_it',
                            )
                        }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <ReportPreview
                        :title="
                            name === ''
                                ? t('i18n.pages.reports.form.preview')
                                : name
                        "
                        :presentation="chartType"
                        :bucket="previewBucket"
                        :payload="previewPayload"
                        :drill-down-source="drillDownSource"
                    />
                </CardContent>
            </Card>

            <FormActions
                :dirty="isDirty"
                :processing="form.processing"
                @cancel="requestLeave"
            />
        </form>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
