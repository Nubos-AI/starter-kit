<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RecordsController from '@/actions/App/Http/Controllers/Engine/RecordsController';
import type { MergeCandidates, MergeRequestPayload, MergeTransferPlan } from '@/domains/business-data/data';
import {
  MERGE_BLOCK_REASON_LABELS,
  MERGE_TRANSFER_POLICY_LABELS,
  mergeTransferCategoryLabel,
} from '@/domains/business-data/data';
import { recordRouteKey } from '@/domains/business-data/data';
import type { RecordObjectType, RecordPayload } from '@/shared/business-data/types/records';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { MERGE_ROLE } from '@/shared/lib/statusMaps';
import AlertError from '@/shared/ui/AlertError.vue';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import { Textarea } from '@/shared/ui/primitives/textarea';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { useRecordMerge } from '../../data/useRecordMerge';
import MergeFieldResolution from '../../ui/MergeFieldResolution.vue';

const { t } = useI18n();

const PAGE_TITLE = t('i18n.pages.records.merge.merge_records');

const PAGE_DESCRIPTION = t('i18n.pages.records.merge.two_records_become_one_the_target_remains_the_source');

const PARTNER_TITLE = t('i18n.pages.records.merge.select_partner');

const PARTNER_DESCRIPTION = t('i18n.pages.records.merge.select_the_record_to_merge_with_this_one_potential');

const PARTNER_PLACEHOLDER = t('i18n.pages.records.merge.select_record');

const DIRECTION_LABEL = t('i18n.pages.records.merge.swap_direction');

const TARGET_HINT = t('i18n.pages.records.merge.remains_and_receives_the_values');

const SOURCE_HINT = t('i18n.pages.records.merge.is_merged_and_then_moved_to_the_trash');

const RESOLUTION_TITLE = t('i18n.pages.records.merge.resolve_values');

const RESOLUTION_DESCRIPTION = t('i18n.pages.records.merge.click_a_value_to_use_it_without_a_selection');

const SUMMARY_TITLE = t('i18n.pages.records.merge.preview');

const SUMMARY_DESCRIPTION = t('i18n.pages.records.merge.these_items_move_when_you_confirm_items_that_stay');

const BLOCKED_TITLE = t('i18n.pages.records.merge.merge_not_possible');

const REASON_LABEL = t('i18n.pages.records.merge.reason');

const TRUNCATED_HINT = t('i18n.pages.records.merge.only_recently_changed_records_are_offered');

const props = defineProps<{
  objectType: RecordObjectType;
  record: RecordPayload;
  candidates: MergeCandidates;
}>();

const recordEditUrl = RecordsController.edit.url({
  record: recordRouteKey(props.record),
});

usePageBreadcrumbs(() => [
  {
    title: props.record.title,
    href: recordEditUrl,
  },
  { title: PAGE_TITLE },
]);

const partnerId = ref<string>('');
const recordIsTarget = ref<boolean>(false);
const overrides = ref<Record<string, 'target' | 'source'>>({});
const reason = ref<string>('');

const { promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    partnerId: partnerId.value,
    recordIsTarget: recordIsTarget.value,
    overrides: overrides.value,
    reason: reason.value,
  }),
  backHref: recordEditUrl,
});

const { plan, errors, previewing, merging, preview, merge } = useRecordMerge((result) => {
  markSaved();
  router.visit(RecordsController.edit.url({ record: result.target_id }));
});

const partnerError = computed<string | undefined>(() =>
  recordIsTarget.value ? errors.value.sourceId : errors.value.targetId,
);

const reasonError = computed<string | undefined>(() =>
  reason.value.trim() === '' ? t(MERGE_BLOCK_REASON_LABELS.reason_required) : errors.value.reason,
);

const sortedOptions = computed(() => {
  const suggested = new Set(props.candidates.suggested_ids);

  return [...props.candidates.options].sort((left, right) => {
    const leftSuggested = suggested.has(left.value) ? 0 : 1;
    const rightSuggested = suggested.has(right.value) ? 0 : 1;

    return leftSuggested - rightSuggested;
  });
});

const hasPartner = computed<boolean>(() => partnerId.value !== '');

const targetId = computed<string>(() => (recordIsTarget.value ? props.record.id : partnerId.value));

const sourceId = computed<string>(() => (recordIsTarget.value ? partnerId.value : props.record.id));

const partnerLabel = computed<string>(
  () => props.candidates.options.find((option) => option.value === partnerId.value)?.label ?? partnerId.value,
);

const targetLabel = computed<string>(() => (recordIsTarget.value ? props.record.title : partnerLabel.value));

const sourceLabel = computed<string>(() => (recordIsTarget.value ? partnerLabel.value : props.record.title));

const blockers = computed(() => plan.value?.blockers ?? []);

const blockerMessages = computed<string[]>(() =>
  blockers.value.map((blocker) =>
    blocker.detail !== null && blocker.reason === 'rule_forbids'
      ? blocker.detail
      : t(MERGE_BLOCK_REASON_LABELS[blocker.reason]),
  ),
);

const requiresReason = computed<boolean>(() => plan.value?.requires_reason === true);

const canMerge = computed<boolean>(
  () => hasPartner.value && plan.value !== null && plan.value.is_mergeable && !merging.value,
);

const resolutionDescription = computed<string>(() => {
  const ruleName = plan.value?.rule_name ?? null;

  if (ruleName === null) {
    return RESOLUTION_DESCRIPTION;
  }

  const appliedRule = t('i18n.pages.records.merge.the_applied_rule_is');

  return `${RESOLUTION_DESCRIPTION} ${appliedRule}${ruleName}“.`;
});

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'partner',
      title: PARTNER_TITLE,
      fields: ['sourceId', 'targetId'],
    },
    ...(plan.value === null
      ? []
      : [
          {
            key: 'resolution',
            title: RESOLUTION_TITLE,
            fields: ['overrides'],
          },
          { key: 'summary', title: SUMMARY_TITLE, fields: ['reason'] },
        ]),
  ],
  () => errors.value,
);

watch(errors, (bag) => {
  revealErrors(bag);
});

watch(
  () => plan.value === null,
  (isWithoutPlan) => {
    if (isWithoutPlan) {
      activeSection.value = 'partner';
    }
  },
);

function payload(): MergeRequestPayload {
  return {
    targetId: targetId.value,
    sourceId: sourceId.value,
    reason: reason.value.trim() === '' ? null : reason.value.trim(),
    overrides: overrides.value,
  };
}

function refreshPlan(): void {
  if (!hasPartner.value) {
    plan.value = null;

    return;
  }

  void preview(props.record.id, payload());
}

watch([partnerId, recordIsTarget], () => {
  overrides.value = {};
  refreshPlan();
});

watch(reason, () => {
  if (requiresReason.value) {
    refreshPlan();
  }
});

function onChoose(key: string, origin: 'target' | 'source'): void {
  overrides.value = { ...overrides.value, [key]: origin };
  refreshPlan();
}

function onReset(key: string): void {
  const next = { ...overrides.value };

  delete next[key];
  overrides.value = next;
  refreshPlan();
}

function onSwapDirection(): void {
  recordIsTarget.value = !recordIsTarget.value;
}

function onSubmit(): void {
  if (!canMerge.value) {
    return;
  }

  void merge(props.record.id, payload());
}

function transferLabel(transfer: MergeTransferPlan): string {
  return mergeTransferCategoryLabel(transfer.category, t);
}

function policyLabel(transfer: MergeTransferPlan): string {
  return t(MERGE_TRANSFER_POLICY_LABELS[transfer.policy]);
}
</script>

<template>
  <PageShell :title="PAGE_TITLE" :description="PAGE_DESCRIPTION">
    <AlertError v-if="blockers.length > 0" :title="BLOCKED_TITLE" :errors="blockerMessages" data-merge-blockers />

    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.records.merge.sections.label')"
      data-merge-sections
    >
      <SectionPanel section="partner">
        <CardSection :title="PARTNER_TITLE" :description="PARTNER_DESCRIPTION">
          <FormField
            for="merge-partner"
            :label="t('i18n.pages.records.merge.second_record')"
            :error="partnerError"
            class="sm:max-w-sm"
          >
            <Combobox
              id="merge-partner"
              v-model="partnerId"
              :options="sortedOptions"
              :searchable="true"
              :placeholder="PARTNER_PLACEHOLDER"
            />
            <p v-if="props.candidates.is_truncated" class="text-xs text-muted-foreground" data-merge-truncated>
              {{ TRUNCATED_HINT }}
            </p>
          </FormField>

          <div v-if="hasPartner" class="flex flex-col gap-3" data-merge-direction>
            <div class="grid gap-2 sm:grid-cols-2">
              <div class="flex flex-col gap-1 rounded-md border border-input p-3" data-merge-target-summary>
                <StatusBadge class="w-fit" :map="MERGE_ROLE" status="target" />
                <span class="text-sm font-medium">
                  {{ targetLabel }}
                </span>
                <span class="text-xs text-muted-foreground">
                  {{ TARGET_HINT }}
                </span>
              </div>
              <div class="flex flex-col gap-1 rounded-md border border-input p-3" data-merge-source-summary>
                <StatusBadge class="w-fit" :map="MERGE_ROLE" status="source" />
                <span class="text-sm font-medium">
                  {{ sourceLabel }}
                </span>
                <span class="text-xs text-muted-foreground">
                  {{ SOURCE_HINT }}
                </span>
              </div>
            </div>
            <Button type="button" variant="outline" size="sm" class="w-fit" data-merge-swap @click="onSwapDirection">
              {{ DIRECTION_LABEL }}
            </Button>
          </div>
        </CardSection>
      </SectionPanel>

      <SectionPanel v-if="plan !== null" section="resolution">
        <CardSection :title="RESOLUTION_TITLE" :description="resolutionDescription">
          <MergeFieldResolution
            :fields="plan.fields"
            :overrides="overrides"
            :disabled="previewing"
            @choose="onChoose"
            @reset="onReset"
          />
        </CardSection>
      </SectionPanel>

      <SectionPanel v-if="plan !== null" section="summary">
        <CardSection :title="SUMMARY_TITLE" :description="SUMMARY_DESCRIPTION">
          <ul class="grid gap-1 sm:grid-cols-2" data-merge-transfers>
            <li
              v-for="transfer in plan.transfers"
              :key="transfer.category"
              class="flex items-center justify-between gap-3 text-sm"
              :data-merge-transfer="transfer.category"
            >
              <span>{{ transferLabel(transfer) }}</span>
              <span class="text-muted-foreground">
                {{ transfer.count }} ·
                {{ policyLabel(transfer) }}
              </span>
            </li>
          </ul>

          <FormField v-if="requiresReason" for="merge-reason" :label="REASON_LABEL" :error="reasonError">
            <Textarea id="merge-reason" v-model="reason" rows="3" />
          </FormField>
        </CardSection>
      </SectionPanel>

      <template #actions>
        <FormActions
          type="button"
          :dirty="canMerge"
          :processing="merging || previewing"
          @cancel="requestLeave"
          @save="onSubmit"
        />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
