<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ReportsController from '@/actions/App/Http/Controllers/Reports/ReportsController';
import type { ReportGroupingBucket, ReportListRow } from '@/domains/analytics/data';
import { useReportDefinition } from '@/domains/analytics/data';
import { REPORT_BUCKET_LABELS } from '@/domains/analytics/data';
import { ReportDefinitionFields } from '@/domains/analytics/ui-charts';
import type { ReportDrillDownSource } from '@/shared/business-data/data/useReportDrillDown';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { usePermissions } from '@/shared/composables/usePermissions';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { submitCreateOrUpdate } from '@/shared/lib/formSubmit';
import { REPORT_EXECUTION_MODE } from '@/shared/lib/statusMaps';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Checkbox } from '@/shared/ui/primitives/checkbox';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import { Label } from '@/shared/ui/primitives/label';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import { Textarea } from '@/shared/ui/primitives/textarea';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../../shared/filters/ui/FilterBuilder.vue';
import ReportPreview from '../../ui/ReportPreview.vue';

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
  isEdit.value ? (props.report?.name ?? t('i18n.pages.reports.form.report')) : t('i18n.pages.reports.form.new_report'),
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

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => snapshot(),
  backHref: ReportsController.index.url(),
});

const prefillSegmentId = ref<string | null>(null);

const fields = computed<FieldDefinition[]>(() => props.fieldsByType[objectTypeId.value] ?? []);

const linkedFields = computed<FieldDefinition[]>(() => props.linkedFieldsByType[objectTypeId.value] ?? []);

const segmentOptions = computed<SelectOption[]>(() => props.segmentsByType[objectTypeId.value] ?? []);

const objectTypeLabel = computed<string>(
  () => props.objectTypeOptions.find((option) => option.value === objectTypeId.value)?.label ?? '—',
);

const definitionErrors = computed(() => ({
  aggregation_type: form.errors.aggregation_type,
  aggregation_field_key: form.errors.aggregation_field_key,
  group_by_field_key: form.errors.group_by_field_key,
  group_by_bucket: form.errors.group_by_bucket,
  series_field_key: form.errors.series_field_key,
  chart_type: form.errors.chart_type,
}));

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'general',
      title: t('i18n.pages.reports.form.basic_information'),
      fields: ['name', 'description'],
    },
    {
      key: 'source',
      title: t('i18n.pages.reports.form.data_source_and_filters'),
      fields: ['object_type_id', 'filter_definition'],
    },
    {
      key: 'definition',
      title: t('i18n.pages.reports.form.report'),
      fields: [
        'aggregation_type',
        'aggregation_field_key',
        'group_by_field_key',
        'group_by_bucket',
        'series_field_key',
        'chart_type',
        'execution_mode',
      ],
    },
    { key: 'preview', title: t('i18n.pages.reports.form.preview') },
  ],
  () => form.errors,
);

const previewBucket = computed<ReportGroupingBucket | null>(() =>
  isGroupingBucket(groupByBucket.value) ? groupByBucket.value : null,
);

const drillDownSource = computed<ReportDrillDownSource | null>(() => {
  const report = props.report;

  if (!isEdit.value || report === null || report.object_type === null) {
    return null;
  }

  return isDirty.value ? null : { reportId: report.id, objectTypeSlug: report.object_type.slug };
});

function isGroupingBucket(value: string | null): value is ReportGroupingBucket {
  return value !== null && Object.prototype.hasOwnProperty.call(REPORT_BUCKET_LABELS, value);
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

  submitCreateOrUpdate(form, submitPayload, {
    updateUrl: isEdit.value && report !== null ? ReportsController.update.url({ report: report.id }) : null,
    storeUrl: ReportsController.store.url(),
    onSuccess: onSaved,
    onError: revealErrors,
  });
}
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.reports.form.create_a_new_report_for_an_object_type')
        : t('i18n.pages.reports.form.edit_this_report_s_definition_and_display')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.reports.form.new_report')
        : t('i18n.pages.reports.form.edit_report', {
            value1: report?.name,
          })
    "
  >
    <form class="flex flex-col gap-6" @submit.prevent="onSubmit">
      <SectionNav
        v-model="activeSection"
        :entries="sections"
        :label="t('i18n.pages.reports.form.sections.label')"
        data-report-sections
      >
        <SectionPanel section="general">
          <CardSection
            :title="t('i18n.pages.reports.form.basic_information')"
            :description="t('i18n.pages.reports.form.the_name_and_description_appear_in_the_list_and')"
          >
            <FormField
              data-report-field="name"
              for="report-name"
              :label="t('i18n.pages.reports.form.name')"
              :error="form.errors.name"
              class="sm:max-w-sm"
            >
              <Input id="report-name" v-model="name" :placeholder="t('i18n.pages.reports.form.report_name')" required />
            </FormField>

            <FormField
              data-report-field="description"
              for="report-description"
              :label="t('i18n.pages.reports.form.description')"
              :error="form.errors.description"
            >
              <Textarea
                id="report-description"
                v-model="description"
                :placeholder="t('i18n.pages.reports.form.what_is_this_report_for')"
                rows="2"
              />
            </FormField>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="source">
          <CardSection
            :title="t('i18n.pages.reports.form.data_source_and_filters')"
            :description="t('i18n.pages.reports.form.the_object_type_determines_which_records_are_analysed_the')"
          >
            <FormField
              data-report-field="object-type"
              for="report-object-type"
              :label="t('i18n.pages.reports.form.object_type')"
              :error="form.errors.object_type_id"
              class="sm:max-w-sm"
            >
              <Combobox
                v-if="!isEdit"
                id="report-object-type"
                v-model="objectTypeId"
                :options="objectTypeOptions"
                :searchable="objectTypeOptions.length > 8"
                :placeholder="t('i18n.pages.reports.form.select_object_type')"
              />
              <p v-else class="text-sm text-muted-foreground">
                {{ objectTypeLabel }}
              </p>
            </FormField>

            <FormField
              data-report-prefill
              for="report-segment-prefill"
              :label="t('i18n.pages.reports.form.prefill_from_segment')"
            >
              <div class="flex flex-wrap items-center gap-2">
                <Combobox
                  id="report-segment-prefill"
                  v-model="prefillSegmentId"
                  class="sm:max-w-sm"
                  :options="segmentOptions"
                  :searchable="segmentOptions.length > 8"
                  :placeholder="t('i18n.pages.reports.form.select_segment')"
                  :empty-label="t('i18n.pages.reports.form.no_segments_available')"
                />
                <Button
                  type="button"
                  variant="outline"
                  :disabled="prefillSegmentId === null || prefillState === 'loading'"
                  @click="applyPrefill"
                >
                  {{ t('i18n.pages.reports.form.apply_filter') }}
                </Button>
              </div>
              <p class="text-xs text-muted-foreground" data-report-prefill-hint>
                {{ t('i18n.pages.reports.form.the_segment_s_filter_is_copied_once_later_changes') }}
              </p>
              <p v-if="prefillState === 'error'" class="text-sm text-danger">
                {{ t('i18n.pages.reports.form.the_segment_s_filter_could_not_be_applied') }}
              </p>
            </FormField>

            <FormField :label="t('i18n.pages.reports.form.filter')" :error="form.errors.filter_definition">
              <FilterBuilder
                :key="filterKey"
                :fields="fields"
                :model-value="filterSeed"
                :show-actions="false"
                @update:model-value="onFilterChange"
              />
            </FormField>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="definition">
          <CardSection
            :title="t('i18n.pages.reports.form.report')"
            :description="t('i18n.pages.reports.form.calculation_grouping_and_display_determine_what_the_report_shows')"
          >
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

            <div v-if="isEscalated" class="flex flex-col gap-2" data-report-execution-mode>
              <div class="flex items-center gap-2">
                <Checkbox
                  id="report-execution-mode"
                  :model-value="executionMode === 'definer'"
                  @update:model-value="onExecutionModeChange"
                />
                <Label for="report-execution-mode">
                  {{ t('i18n.pages.reports.form.run_with_my_permissions') }}
                </Label>
                <StatusBadge v-if="executionMode === 'definer'" :map="REPORT_EXECUTION_MODE" status="definer" />
              </div>
              <p class="text-xs text-muted-foreground">
                {{ t('i18n.pages.reports.form.everyone_who_opens_this_report_will_see_the_numbers') }}
              </p>
              <InputError :message="form.errors.execution_mode" />
            </div>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="preview">
          <CardSection
            :title="t('i18n.pages.reports.form.preview')"
            :description="t('i18n.pages.reports.form.the_preview_runs_the_current_definition_without_saving_it')"
          >
            <ReportPreview
              :title="name === '' ? t('i18n.pages.reports.form.preview') : name"
              :presentation="chartType"
              :bucket="previewBucket"
              :payload="previewPayload"
              :drill-down-source="drillDownSource"
            />
          </CardSection>
        </SectionPanel>

        <template #actions>
          <FormActions :dirty="isDirty" :processing="form.processing" @cancel="requestLeave" />
        </template>
      </SectionNav>
    </form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
