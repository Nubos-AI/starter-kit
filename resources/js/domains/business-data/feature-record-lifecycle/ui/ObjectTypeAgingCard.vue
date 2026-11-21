<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, ref, watch } from 'vue';
import AgingRulesController from '@/actions/App/Http/Controllers/Aging/AgingRulesController';
import type { AgingRulePayload, AgingRuleRefusalReason, AgingRuleRow } from '@/shared/business-data/types/aging';
import { AGING_CLOCK_LABELS, AGING_RULE_REFUSAL_LABELS } from '@/shared/business-data/types/aging';
import { useI18n } from '@/shared/composables/useI18n';
import { useListSelection } from '@/shared/composables/useListSelection';
import { useModuleOptions } from '@/shared/composables/useModuleOptions';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import { AGING_RULE_STATE, AGING_THRESHOLD_COLOR } from '@/shared/lib/statusMaps';
import type { FieldDefinition } from '@/shared/types/fields';
import type { SelectOption } from '@/shared/types/ui';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { actionsColumn } from '@/shared/ui/data-grid/actions';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/shared/ui/data-grid/selectionColumn';
import { statusBadgeColumn } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';
import TitledCard from '@/shared/ui/TitledCard.vue';
import ViewStates from '@/shared/ui/ViewStates.vue';
import AgingRuleForm from './AgingRuleForm.vue';

const { t } = useI18n();

const CARD_TITLE = t('i18n.components.engine.object_type.object_type_aging_card.aging_rules');

const CARD_DESCRIPTION = t(
  'i18n.components.engine.object_type.object_type_aging_card.aging_rules_measure_how_long_a_record_has_been',
);

const CREATE_LABEL = t('i18n.components.engine.object_type.object_type_aging_card.create_aging_rule');

const EMPTY_TITLE = t('i18n.components.engine.object_type.object_type_aging_card.no_aging_rules_yet');

const EMPTY_DESCRIPTION = t(
  'i18n.components.engine.object_type.object_type_aging_card.a_rule_measures_how_long_a_record_has_been',
);

const DELETE_TITLE = t('i18n.components.engine.object_type.object_type_aging_card.delete_aging_rule');

const REFUSAL_FALLBACK = t('i18n.components.engine.object_type.object_type_aging_card.not_possible');

const props = defineProps<{
  objectTypeSlug: string;
  rules: AgingRuleRow[];
  clockFieldOptions: SelectOption[];
  conditionFields: FieldDefinition[];
  activeRuleLimit: number;
  canCreate: boolean;
}>();

const editorOpen = ref<boolean>(false);
const editingRule = ref<AgingRuleRow | null>(null);
const saveErrors = ref<Record<string, string>>({});
const saving = ref<boolean>(false);

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } = useRowDeletion<AgingRuleRow>({
  url: (rule) =>
    AgingRulesController.destroy.url({
      objectType: props.objectTypeSlug,
      agingRule: rule.id,
    }),
  preserveState: true,
});

const selection = useListSelection();

watch(
  () => props.rules,
  (rows) => selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const hasRules = computed<boolean>(() => props.rules.length > 0);

const activeRuleCount = computed<number>(() => props.rules.filter((rule) => rule.is_active).length);

const deleteDescription = computed<string>(() =>
  pendingDelete.value === null
    ? ''
    : t(
        'i18n.components.engine.object_type.object_type_aging_card.the_rule_will_be_deleted_records_remain_unchanged_the',
        { value1: pendingDelete.value.name },
      ),
);

function refusalLabel(reason: AgingRuleRefusalReason | null): string {
  return reason === null ? REFUSAL_FALLBACK : t(AGING_RULE_REFUSAL_LABELS[reason]);
}

function thresholdSummary(rule: AgingRuleRow): string {
  return rule.thresholds
    .map((threshold) =>
      t('i18n.components.engine.object_type.object_type_aging_card.from_days', { value1: threshold.after_days }),
    )
    .join(' · ');
}

function highestThreshold(rule: AgingRuleRow): string | null {
  return rule.thresholds[rule.thresholds.length - 1]?.color ?? null;
}

function openCreate(): void {
  editingRule.value = null;
  editorOpen.value = true;
}

function openEdit(rule: AgingRuleRow): void {
  editingRule.value = rule;
  editorOpen.value = true;
}

function closeEditor(): void {
  editorOpen.value = false;
  editingRule.value = null;
}

function writeOptions() {
  return {
    preserveScroll: true,
    preserveState: true,
    onStart: (): void => {
      saving.value = true;
    },
    onError: (errors: Record<string, string>): void => {
      saveErrors.value = errors;
    },
    onSuccess: (): void => {
      saveErrors.value = {};
      closeEditor();
    },
    onFinish: (): void => {
      saving.value = false;
    },
  };
}

function submitRule(payload: AgingRulePayload): void {
  const rule = editingRule.value;

  if (rule === null) {
    router.post<AgingRulePayload>(
      AgingRulesController.store.url({
        objectType: props.objectTypeSlug,
      }),
      payload,
      writeOptions(),
    );

    return;
  }

  router.put<AgingRulePayload>(
    AgingRulesController.update.url({
      objectType: props.objectTypeSlug,
      agingRule: rule.id,
    }),
    payload,
    writeOptions(),
  );
}

function bulkDeleteRules(): void {
  router.post(
    AgingRulesController.bulkDestroy.url({
      objectType: props.objectTypeSlug,
    }),
    { ids: selection.ids.value },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => selection.clear(),
    },
  );
}

const extraColumns = useModuleOptions('aging.boolean-columns');

const columnDefs = computed<ColDef<AgingRuleRow>[]>(() => [
  selectionColumn<AgingRuleRow>(selection, (row) => row.can_delete),
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.components.engine.object_type.object_type_aging_card.name'),
    flex: 1,
    minWidth: 180,
  },
  {
    colId: 'clock',
    headerName: t('i18n.components.engine.object_type.object_type_aging_card.clock'),
    minWidth: 180,
    valueGetter: (params: ValueGetterParams<AgingRuleRow>): string =>
      params.data === undefined ? '' : t(AGING_CLOCK_LABELS[params.data.clock]),
  },
  {
    colId: 'thresholds',
    headerName: t('i18n.components.engine.object_type.object_type_aging_card.thresholds'),
    flex: 1,
    minWidth: 180,
    valueGetter: (params: ValueGetterParams<AgingRuleRow>): string =>
      params.data === undefined ? '' : thresholdSummary(params.data),
  },
  statusBadgeColumn<AgingRuleRow>('highest_threshold', AGING_THRESHOLD_COLOR, {
    headerName: t('i18n.components.engine.object_type.object_type_aging_card.highest_level'),
    minWidth: 140,
    valueGetter: (params: ValueGetterParams<AgingRuleRow>): string | null =>
      params.data === undefined ? null : highestThreshold(params.data),
  }),
  statusBadgeColumn<AgingRuleRow>('state', AGING_RULE_STATE, {
    headerName: t('i18n.components.engine.object_type.object_type_aging_card.state'),
    minWidth: 120,
    valueGetter: (params: ValueGetterParams<AgingRuleRow>): string =>
      params.data?.is_active === true ? 'active' : 'inactive',
  }),
  ...extraColumns.value.map((column): ColDef<AgingRuleRow> => ({
    colId: column.value,
    headerName: column.label,
    minWidth: 140,
    valueGetter: (params: ValueGetterParams<AgingRuleRow>): string =>
      params.data?.[column.value] === true
        ? t('i18n.components.engine.object_type.object_type_aging_card.triggers')
        : '—',
  })),
  actionsColumn<AgingRuleRow>([
    {
      icon: Pencil,
      label: t('i18n.components.engine.object_type.object_type_aging_card.edit'),
      variant: 'edit',
      testId: 'aging-rule-edit',
      onClick: (row) => openEdit(row),
      isDisabled: (row) => !row.can_update,
      disabledReason: (row) => refusalLabel(row.update_reason),
    },
    {
      icon: Trash2,
      label: t('i18n.components.engine.object_type.object_type_aging_card.delete'),
      variant: 'destructive',
      testId: 'aging-rule-delete',
      onClick: (row) => (pendingDelete.value = row),
      isDisabled: (row) => !row.can_delete,
      disabledReason: (row) => refusalLabel(row.delete_reason),
    },
  ]),
]);
</script>

<template>
  <TitledCard
    class="flex min-h-0 flex-1 flex-col"
    data-aging-card
    :title="CARD_TITLE"
    :description="CARD_DESCRIPTION"
    content-class="min-h-0 flex-1"
  >
    <template #actions>
      <CreateButton v-if="props.canCreate" size="sm" :label="CREATE_LABEL" @click="openCreate" />
    </template>

    <template v-if="hasRules">
      <SelectionBulkBar
        :count="selection.count.value"
        :delete-label="t('i18n.components.engine.object_type.object_type_aging_card.delete_aging_rules')"
        @delete="bulkDeleteRules"
        @clear="selection.clear"
      />

      <DataGrid
        class="h-full min-h-0 w-full flex-1"
        :column-defs="columnDefs"
        :row-data="props.rules"
        :is-row-activatable="(row: AgingRuleRow) => row.can_update"
        :aria-label="t('i18n.components.engine.object_type.object_type_aging_card.aging_rules')"
        @row-activate="openEdit"
      />
    </template>

    <div v-else class="relative flex min-h-40 flex-1 flex-col" data-aging-empty>
      <ViewStates
        state="empty"
        empty-kind="no-records"
        :empty-title="EMPTY_TITLE"
        :empty-description="EMPTY_DESCRIPTION"
        :create-label="CREATE_LABEL"
        :can-create="props.canCreate"
        @create="openCreate"
      />
    </div>

    <AgingRuleForm
      v-if="editorOpen"
      :rule="editingRule"
      :clock-field-options="props.clockFieldOptions"
      :condition-fields="props.conditionFields"
      :active-rule-count="activeRuleCount"
      :active-rule-limit="props.activeRuleLimit"
      :errors="saveErrors"
      :processing="saving"
      @submit="submitRule"
      @close="closeEditor"
    />

    <ConfirmDialog
      v-model:open="deleteDialogOpen"
      :title="DELETE_TITLE"
      :description="deleteDescription"
      :confirm-label="t('i18n.components.engine.object_type.object_type_aging_card.delete')"
      variant="destructive"
      :pending="deletePending"
      @confirm="confirmDelete"
    />
  </TitledCard>
</template>
