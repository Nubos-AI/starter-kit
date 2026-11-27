<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PromotionRunsController from '@/actions/App/Http/Controllers/Promotion/PromotionRunsController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import AlertError from '@/shared/ui/AlertError.vue';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import type {
  ConflictResolutionValue,
  PromotionDiffGroup,
  PromotionDiffRow,
  PromotionReport,
  PromotionReviewRun,
} from '../../types/promotion';
import PromotionDiffSection from '../../ui/PromotionDiffSection.vue';
import PromotionReportSection from '../../ui/PromotionReportSection.vue';
import type { PromotionReviewState } from '../../utils/promotionDiffColumns';

const { t } = useI18n();

const props = defineProps<{
  run: PromotionReviewRun;
  groups: PromotionDiffGroup[];
  decision_options: SelectOption[];
  source_error: string | null;
  report: PromotionReport | null;
}>();

const page = usePage();

usePageBreadcrumbs(() => [{ title: t('i18n.pages.promotion.review.review_promotion') }]);

const errors = computed<Partial<Record<string, string>>>(() => {
  const bag: unknown = page.props.errors;

  return typeof bag === 'object' && bag !== null ? (bag as Partial<Record<string, string>>) : {};
});

const selectionFields = ['selection', 'conflictDecisions', 'source', 'mode'];

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    ...(props.report === null
      ? []
      : [
          {
            key: 'report',
            title: t('i18n.pages.promotion.review.sections.report'),
          },
        ]),
    ...props.groups.map((group) => ({
      key: groupSection(group),
      title: group.kind_label,
      fields: selectionFields,
    })),
    {
      key: 'approval',
      title: t('i18n.pages.promotion.review.approval'),
      fields: ['conflicts', 'status'],
    },
  ],
  () => errors.value,
);

watch(errors, (bag) => {
  revealErrors(bag);
});

function groupSection(group: PromotionDiffGroup): string {
  return `kind-${group.kind}`;
}

const rows = computed<PromotionDiffRow[]>(() => props.groups.flatMap((group) => group.rows));

const rowKey = (row: PromotionDiffRow): string => `${row.kind}|${row.key}`;

const selectedKeys = ref<string[]>([]);
const overwriteKeys = ref<string[]>([]);
const decisions = ref<Partial<Record<string, ConflictResolutionValue>>>({});
const processing = ref<boolean>(false);

function adoptServerState(): void {
  selectedKeys.value = rows.value.filter((row) => row.is_selected && !row.is_pulled_in).map(rowKey);
  overwriteKeys.value = rows.value.filter((row) => row.overwrite).map(rowKey);
  decisions.value = Object.fromEntries(
    rows.value.flatMap((row) => (row.decision === null ? [] : [[rowKey(row), row.decision]])),
  );
}

adoptServerState();

watch(() => props.groups, adoptServerState, { flush: 'sync' });

const canEdit = computed<boolean>(() => props.run.can_update && props.source_error === null);

const isHandSelected = (row: PromotionDiffRow): boolean => selectedKeys.value.includes(rowKey(row));

const isChecked = (row: PromotionDiffRow): boolean => isHandSelected(row) || row.is_pulled_in;

const isOverwritten = (row: PromotionDiffRow): boolean => overwriteKeys.value.includes(rowKey(row));

function selectionPayload() {
  return rows.value.filter(isHandSelected).map((row) => ({
    artifactKind: row.kind,
    artifactKey: row.key,
    overwrite: isOverwritten(row),
  }));
}

function decisionsPayload(checkedOnly: boolean) {
  return rows.value.flatMap((row) => {
    const decision = decisions.value[rowKey(row)];

    return row.state === 'conflicted' && decision !== undefined && (!checkedOnly || isChecked(row))
      ? [
          {
            artifactKind: row.kind,
            artifactKey: row.key,
            decision,
          },
        ]
      : [];
  });
}

const reviewState: PromotionReviewState = {
  isChecked,
  setChecked: (row, checked) => {
    const key = rowKey(row);
    const others = selectedKeys.value.filter((entry) => entry !== key);

    selectedKeys.value = checked ? [...others, key] : others;

    if (!checked) {
      overwriteKeys.value = overwriteKeys.value.filter((entry) => entry !== key);
    }
  },
  isOverwritten,
  setOverwritten: (row, overwritten) => {
    const key = rowKey(row);
    const others = overwriteKeys.value.filter((entry) => entry !== key);

    overwriteKeys.value = overwritten ? [...others, key] : others;

    if (overwritten && !selectedKeys.value.includes(key)) {
      selectedKeys.value = [...selectedKeys.value, key];
    }
  },
  decisionOf: (row) => decisions.value[rowKey(row)],
  decide: (row, decision) => {
    decisions.value = { ...decisions.value, [rowKey(row)]: decision };
  },
  decisionOptions: () => props.decision_options,
  canEdit: () => canEdit.value,
};

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    selection: selectionPayload(),
    conflictDecisions: decisionsPayload(true),
  }),
  backHref: PromotionRunsController.index.url(),
});

const undecidedCount = computed<number>(
  () =>
    rows.value.filter(
      (row) => row.state === 'conflicted' && isChecked(row) && decisions.value[rowKey(row)] === undefined,
    ).length,
);

const saveEnabled = computed<boolean>(() => canEdit.value && (isDirty.value || props.run.has_outdated_decisions));

const submitReason = computed<string | null>(() => {
  if (isDirty.value) {
    return t('i18n.pages.promotion.review.please_save_your_selection_first');
  }

  if (!props.run.can_submit) {
    return props.run.submit_reason ?? t('i18n.pages.promotion.review.the_promotion_cannot_be_submitted_right_now');
  }

  if (undecidedCount.value > 0) {
    return t('i18n.pages.promotion.review.resolve_all_conflicts_in_the_selection_first');
  }

  return null;
});

const readOnlyReason = computed<string>(() =>
  [props.run.update_reason, t('i18n.pages.promotion.review.the_comparison_shows_the_current_state_of_both_sides')]
    .filter((part) => part !== null && part !== '')
    .join(' '),
);

const headingDescription = computed<string>(() =>
  t('i18n.pages.promotion.review.comparison_with_select_the_artifacts_to_transfer_to_live', {
    value1: props.run.counterpart_label,
  }),
);

const updateUrl = (): string => PromotionRunsController.update.url({ promotionRun: props.run.id });

const visitCallbacks = {
  preserveScroll: true,
  onStart: () => {
    processing.value = true;
  },
  onFinish: () => {
    processing.value = false;
  },
};

function save(): void {
  router.put(
    updateUrl(),
    {
      mode: 'selected',
      selection: selectionPayload(),
      conflictDecisions: decisionsPayload(true),
    },
    { ...visitCallbacks, onSuccess: () => markSaved() },
  );
}

function takeAll(): void {
  router.put(
    updateUrl(),
    { mode: 'all', conflictDecisions: decisionsPayload(false) },
    { ...visitCallbacks, onSuccess: () => markSaved() },
  );
}

function submit(): void {
  router.post(PromotionRunsController.submit.url({ promotionRun: props.run.id }), {}, visitCallbacks);
}
</script>

<template>
  <PageShell :title="t('i18n.pages.promotion.review.review_promotion')" :description="headingDescription">
    <template #actions>
      <Button variant="outline" data-promotion-take-all :disabled="!canEdit || processing" @click="takeAll">
        {{ t('i18n.pages.promotion.review.apply_all') }}
      </Button>
    </template>

    <ReadOnlyNotice v-if="!run.can_update" :reason="readOnlyReason" data-promotion-readonly />

    <AlertError
      v-if="source_error !== null"
      data-promotion-source-error
      :title="t('i18n.pages.promotion.review.source_unavailable')"
      :message="source_error"
    />

    <p v-if="groups.length === 0 && source_error === null" class="text-sm text-muted-foreground">
      {{ t('i18n.pages.promotion.review.there_are_no_differences_between_the_two_sides') }}
    </p>

    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.promotion.review.sections.label')"
      data-promotion-sections
    >
      <SectionPanel v-if="report !== null" section="report" :actions="false">
        <PromotionReportSection :report="report" />
      </SectionPanel>

      <SectionPanel v-for="group in groups" :key="group.kind" :section="groupSection(group)">
        <PromotionDiffSection :group="group" :state="reviewState">
          <InputError :message="errors.selection" />
          <InputError :message="errors.conflictDecisions" />
          <InputError :message="errors.source" />
          <InputError :message="errors.mode" />
        </PromotionDiffSection>
      </SectionPanel>

      <SectionPanel section="approval" :actions="false">
        <CardSection
          :title="t('i18n.pages.promotion.review.approval')"
          :description="t('i18n.pages.promotion.review.if_approval_is_enabled_under_approval_rules_approvers_decide')"
        >
          <p
            v-if="submitReason !== null"
            id="promotion-submit-reason"
            class="text-sm text-muted-foreground"
            data-promotion-submit-reason
          >
            {{ submitReason }}
          </p>
          <InputError :message="errors.conflicts" />
          <InputError :message="errors.status" />
          <div class="flex justify-end">
            <Button
              data-promotion-submit
              :disabled="processing || submitReason !== null"
              :aria-describedby="submitReason !== null ? 'promotion-submit-reason' : undefined"
              @click="submit"
            >
              {{ t('i18n.pages.promotion.review.submit_for_approval') }}
            </Button>
          </div>
        </CardSection>
      </SectionPanel>

      <template #actions>
        <FormActions type="button" :dirty="saveEnabled" :processing="processing" @save="save" @cancel="requestLeave" />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
