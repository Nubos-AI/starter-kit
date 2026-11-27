<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Pencil, Trash2 } from '@lucide/vue';
import type { ColDef, ValueGetterParams } from 'ag-grid-community';
import { computed, watch } from 'vue';
import MergeRulesController from '@/actions/App/Http/Controllers/Engine/MergeRulesController';
import type { MergeRuleRefusalReason, MergeRuleRow } from '@/domains/business-data/data';
import { MERGE_RULE_REFUSAL_LABELS } from '@/domains/business-data/data';
import { useI18n } from '@/shared/composables/useI18n';
import { useListSelection } from '@/shared/composables/useListSelection';
import { useRowDeletion } from '@/shared/composables/useRowDeletion';
import { MERGE_RULE_MODE, MERGE_RULE_STATE } from '@/shared/lib/statusMaps';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import CreateButton from '@/shared/ui/CreateButton.vue';
import { actionsColumn } from '@/shared/ui/data-grid/actions';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import SelectionBulkBar from '@/shared/ui/data-grid/SelectionBulkBar.vue';
import { selectionColumn } from '@/shared/ui/data-grid/selectionColumn';
import { statusBadgeColumn } from '@/shared/ui/data-grid/StatusBadgeCellRenderer';
import TitledCard from '@/shared/ui/TitledCard.vue';
import ViewStates from '@/shared/ui/ViewStates.vue';

const { t } = useI18n();

const CARD_TITLE = t('i18n.components.engine.object_type.object_type_merge_card.merge_rules');

const CARD_DESCRIPTION = t(
  'i18n.components.engine.object_type.object_type_merge_card.merge_rules_determine_whether_two_records_may_be_merged',
);

const CREATE_LABEL = t('i18n.components.engine.object_type.object_type_merge_card.create_merge_rule');

const EMPTY_TITLE = t('i18n.components.engine.object_type.object_type_merge_card.no_merge_rules_yet');

const EMPTY_DESCRIPTION = t(
  'i18n.components.engine.object_type.object_type_merge_card.without_a_custom_rule_merging_uses_the_default_the',
);

const DELETE_TITLE = t('i18n.components.engine.object_type.object_type_merge_card.delete_merge_rule');

const REFUSAL_FALLBACK = t('i18n.components.engine.object_type.object_type_merge_card.not_possible');

const NO_CONDITION = t('i18n.components.engine.object_type.object_type_merge_card.all_records');

const HAS_CONDITION = t('i18n.components.engine.object_type.object_type_merge_card.restricted');

const props = defineProps<{
  objectTypeSlug: string;
  rules: MergeRuleRow[];
  canCreate: boolean;
}>();

const { pendingDelete, deletePending, deleteDialogOpen, confirmDelete } = useRowDeletion<MergeRuleRow>({
  url: (rule) =>
    MergeRulesController.destroy.url({
      objectType: props.objectTypeSlug,
      mergeRule: rule.id,
    }),
  preserveState: true,
});

const selection = useListSelection();

watch(
  () => props.rules,
  (rows) => selection.prune(rows.filter((row) => row.can_delete).map((row) => row.id)),
);

const hasRules = computed<boolean>(() => props.rules.length > 0);

const deleteDescription = computed<string>(() =>
  pendingDelete.value === null
    ? ''
    : t(
        'i18n.components.engine.object_type.object_type_merge_card.the_rule_will_be_deleted_previously_merged_records_remain',
        { value1: pendingDelete.value.name },
      ),
);

function refusalLabel(reason: MergeRuleRefusalReason | null): string {
  return reason === null ? REFUSAL_FALLBACK : t(MERGE_RULE_REFUSAL_LABELS[reason]);
}

function conditionSummary(rule: MergeRuleRow): string {
  const condition = rule.condition;
  const isEmpty =
    condition === null ||
    condition === undefined ||
    (Array.isArray(condition) && condition.length === 0) ||
    (typeof condition === 'object' && Object.keys(condition as object).length === 0);

  return isEmpty ? NO_CONDITION : HAS_CONDITION;
}

function openCreate(): void {
  router.visit(MergeRulesController.create.url({ objectType: props.objectTypeSlug }));
}

function openEdit(rule: MergeRuleRow): void {
  router.visit(
    MergeRulesController.edit.url({
      objectType: props.objectTypeSlug,
      mergeRule: rule.id,
    }),
  );
}

function bulkDeleteRules(): void {
  router.post(
    MergeRulesController.bulkDestroy.url({
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

const columnDefs = computed<ColDef<MergeRuleRow>[]>(() => [
  selectionColumn<MergeRuleRow>(selection, (row) => row.can_delete),
  {
    colId: 'position',
    field: 'position',
    headerName: t('i18n.components.engine.object_type.object_type_merge_card.order'),
    maxWidth: 130,
  },
  {
    colId: 'name',
    field: 'name',
    headerName: t('i18n.components.engine.object_type.object_type_merge_card.name'),
    flex: 1,
    minWidth: 180,
  },
  statusBadgeColumn<MergeRuleRow>('mode', MERGE_RULE_MODE, {
    headerName: t('i18n.components.engine.object_type.object_type_merge_card.effect'),
    minWidth: 130,
    valueGetter: (params: ValueGetterParams<MergeRuleRow>): string => params.data?.mode ?? 'allow',
  }),
  {
    colId: 'condition',
    headerName: t('i18n.components.engine.object_type.object_type_merge_card.applies_to'),
    minWidth: 160,
    valueGetter: (params: ValueGetterParams<MergeRuleRow>): string =>
      params.data === undefined ? '' : conditionSummary(params.data),
  },
  statusBadgeColumn<MergeRuleRow>('state', MERGE_RULE_STATE, {
    headerName: t('i18n.components.engine.object_type.object_type_merge_card.state'),
    minWidth: 120,
    valueGetter: (params: ValueGetterParams<MergeRuleRow>): string =>
      params.data?.is_active === true ? 'active' : 'inactive',
  }),
  actionsColumn<MergeRuleRow>([
    {
      icon: Pencil,
      label: t('i18n.components.engine.object_type.object_type_merge_card.edit'),
      variant: 'edit',
      testId: 'merge-rule-edit',
      onClick: (row) => openEdit(row),
      isDisabled: (row) => !row.can_update,
      disabledReason: (row) => refusalLabel(row.update_reason),
    },
    {
      icon: Trash2,
      label: t('i18n.components.engine.object_type.object_type_merge_card.delete'),
      variant: 'destructive',
      testId: 'merge-rule-delete',
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
    data-merge-card
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
        :delete-label="t('i18n.components.engine.object_type.object_type_merge_card.delete_merge_rules')"
        @delete="bulkDeleteRules"
        @clear="selection.clear"
      />

      <DataGrid
        class="h-full min-h-0 w-full flex-1"
        :column-defs="columnDefs"
        :row-data="props.rules"
        :is-row-activatable="(row: MergeRuleRow) => row.can_update"
        :aria-label="t('i18n.components.engine.object_type.object_type_merge_card.merge_rules')"
        @row-activate="openEdit"
      />
    </template>

    <div v-else class="relative flex min-h-40 flex-1 flex-col" data-merge-empty>
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

    <ConfirmDialog
      v-model:open="deleteDialogOpen"
      :title="DELETE_TITLE"
      :description="deleteDescription"
      :confirm-label="t('i18n.components.engine.object_type.object_type_merge_card.delete')"
      variant="destructive"
      :pending="deletePending"
      @confirm="confirmDelete"
    />
  </TitledCard>
</template>
