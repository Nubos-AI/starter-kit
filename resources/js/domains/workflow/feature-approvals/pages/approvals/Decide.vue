<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalsController from '@/actions/App/Http/Controllers/Approvals/ApprovalsController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { formatDateTime } from '@/shared/lib/formatDate';
import { APPROVAL_DEADLINE } from '@/shared/lib/statusMaps';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue';
import DetailItem from '@/shared/ui/DetailItem.vue';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import { Textarea } from '@/shared/ui/primitives/textarea';
import ReadOnlyNotice from '@/shared/ui/ReadOnlyNotice.vue';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import TextLink from '@/shared/ui/TextLink.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const { t } = useI18n();

interface ApprovalHistoryEntry {
  id: string;
  type: string;
  type_label: string;
  actor_label: string;
  on_behalf_of_label: string | null;
  reason: string | null;
  occurred_at: string;
}

interface ApprovalDetail {
  id: string;
  record_id: string | null;
  anchor_label: string;
  anchor_kind_label: string;
  anchor_url: string | null;
  transition_label: string;
  stage_label: string;
  deadline_at: string | null;
  is_overdue: boolean;
  on_behalf_of_id: string | null;
  on_behalf_of_label: string | null;
  history: ApprovalHistoryEntry[];
  can_decide: boolean;
  can_cancel: boolean;
  decision_reason: string | null;
  on_behalf_of_options: SelectOption[];
}

type Decision = 'approved' | 'rejected' | '';

const PAGE_TITLE = t('i18n.pages.approvals.decide.decide_approval');

const PAGE_DESCRIPTION = t('i18n.pages.approvals.decide.review_the_process_and_its_history_then_record_your');

const ANCHOR_TITLE = t('i18n.pages.approvals.decide.process');

const ANCHOR_DESCRIPTION = t('i18n.pages.approvals.decide.what_you_are_deciding_on');

const HISTORY_TITLE = t('i18n.pages.approvals.decide.history');

const HISTORY_DESCRIPTION = t('i18n.pages.approvals.decide.all_previous_events_in_this_process');

const HISTORY_EMPTY = t('i18n.pages.approvals.decide.nothing_has_been_recorded_for_this_process_yet');

const DECISION_TITLE = t('i18n.pages.approvals.decide.your_decision');

const DECISION_DESCRIPTION = t('i18n.pages.approvals.decide.a_rejection_requires_a_reason_which_will_be_recorded');

const BLOCKED_TITLE = t('i18n.pages.approvals.decide.you_cannot_decide_here');

const DECISION_MISSING = t('i18n.pages.approvals.decide.please_select_a_decision');

const CANCEL_TITLE = t('i18n.pages.approvals.decide.cancel_process');

const CANCEL_DESCRIPTION = t('i18n.pages.approvals.decide.a_cancelled_process_can_no_longer_be_decided_and');

const CANCEL_CONFIRM_DESCRIPTION = t('i18n.pages.approvals.decide.are_you_sure_you_want_to_cancel_this_approval');

const CANCEL_REASON_LABEL = t('i18n.pages.approvals.decide.reason_for_cancellation_optional');

const REASON_MISSING = t('i18n.pages.approvals.decide.please_provide_a_reason_for_the_rejection');

const DECISION_OPTIONS: SelectOption[] = [
  { value: 'approved', label: t('i18n.pages.approvals.decide.approve') },
  { value: 'rejected', label: t('i18n.pages.approvals.decide.reject') },
];

const props = defineProps<{
  approval: ApprovalDetail;
}>();

usePageBreadcrumbs(() => [{ title: props.approval.anchor_label }]);

const decision = ref<Decision>('');
const reason = ref<string>('');
const onBehalfOfId = ref<string | null>(props.approval.on_behalf_of_id);
const processing = ref<boolean>(false);
const errors = ref<Record<string, string>>({});
const cancelOpen = ref<boolean>(false);
const cancelReason = ref<string>('');
const cancelling = ref<boolean>(false);
const cancelErrors = ref<Record<string, string>>({});

const requiresReason = computed<boolean>(() => decision.value === 'rejected');

const hasDelegation = computed<boolean>(() => props.approval.on_behalf_of_options.length > 0);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    decision: decision.value,
    reason: reason.value,
    onBehalfOfId: onBehalfOfId.value,
  }),
  backHref: ApprovalsController.index.url(),
});

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    { key: 'process', title: ANCHOR_TITLE },
    { key: 'history', title: HISTORY_TITLE },
    {
      key: 'decision',
      title: DECISION_TITLE,
      fields: ['decision', 'onBehalfOfId', 'reason'],
    },
    ...(props.approval.can_cancel ? [{ key: 'cancel', title: CANCEL_TITLE }] : []),
  ],
  () => errors.value,
);

const deadlineLabel = computed<string>(() => formatDateTime(props.approval.deadline_at));

function historyLine(entry: ApprovalHistoryEntry): string {
  const actor =
    entry.on_behalf_of_label === null
      ? entry.actor_label
      : t('i18n.pages.approvals.decide.acting_for', {
          value1: entry.actor_label,
          value2: entry.on_behalf_of_label,
        });

  return `${entry.type_label} · ${actor}`;
}

function onCancelProcess(): void {
  if (cancelling.value) {
    return;
  }

  cancelOpen.value = false;
  cancelling.value = true;
  cancelErrors.value = {};

  const trimmedReason = cancelReason.value.trim();

  router.post(
    ApprovalsController.cancel.url({ approval: props.approval.id }),
    { reason: trimmedReason === '' ? null : trimmedReason },
    {
      preserveScroll: true,
      onSuccess: () => {
        markSaved();
      },
      onError: (bag: Record<string, string>) => {
        cancelErrors.value = bag;
      },
      onFinish: () => {
        cancelling.value = false;
      },
    },
  );
}

function onSubmit(): void {
  if (!props.approval.can_decide || processing.value) {
    return;
  }

  if (decision.value === '') {
    errors.value = { decision: DECISION_MISSING };

    return;
  }

  const trimmedReason = reason.value.trim();

  if (requiresReason.value && trimmedReason === '') {
    errors.value = { reason: REASON_MISSING };

    return;
  }

  errors.value = {};
  processing.value = true;

  router.post(
    ApprovalsController.decide.url({ approval: props.approval.id }),
    {
      decision: decision.value,
      reason: trimmedReason === '' ? null : trimmedReason,
      onBehalfOfId: hasDelegation.value ? onBehalfOfId.value : null,
    },
    {
      preserveScroll: true,
      onSuccess: () => {
        markSaved();
      },
      onError: (bag: Record<string, string>) => {
        errors.value = bag;
        revealErrors(bag);
      },
      onFinish: () => {
        processing.value = false;
      },
    },
  );
}
</script>

<template>
  <PageShell :title="PAGE_TITLE" :description="PAGE_DESCRIPTION">
    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.approvals.decide.sections.label')"
      data-approval-sections
    >
      <SectionPanel section="process" :actions="false">
        <CardSection :title="ANCHOR_TITLE" :description="ANCHOR_DESCRIPTION">
          <dl class="grid gap-4 sm:grid-cols-2">
            <DetailItem :label="t('i18n.pages.approvals.decide.process')">
              <TextLink
                v-if="props.approval.anchor_url !== null"
                :href="props.approval.anchor_url"
                data-approval-anchor
              >
                {{ props.approval.anchor_label }}
              </TextLink>
              <span v-else data-approval-anchor>
                {{ props.approval.anchor_label }}
              </span>
            </DetailItem>
            <DetailItem :label="t('i18n.pages.approvals.decide.kind')">
              <span data-approval-anchor-kind>
                {{ props.approval.anchor_kind_label }}
              </span>
            </DetailItem>
            <DetailItem :label="t('i18n.pages.approvals.decide.transition')">
              {{ props.approval.transition_label }}
            </DetailItem>
            <DetailItem :label="t('i18n.pages.approvals.decide.level')">
              {{ props.approval.stage_label }}
            </DetailItem>
            <DetailItem :label="t('i18n.pages.approvals.decide.deadline')">
              <span class="flex flex-wrap items-center gap-2">
                <span>{{ deadlineLabel }}</span>
                <StatusBadge
                  v-if="props.approval.is_overdue"
                  :map="APPROVAL_DEADLINE"
                  status="overdue"
                  data-approval-overdue
                />
              </span>
            </DetailItem>
          </dl>
        </CardSection>
      </SectionPanel>

      <SectionPanel section="history" :actions="false">
        <CardSection :title="HISTORY_TITLE" :description="HISTORY_DESCRIPTION">
          <EmptyState v-if="props.approval.history.length === 0" variant="inline" :description="HISTORY_EMPTY" />
          <ul v-else class="flex flex-col gap-3" data-approval-history>
            <li v-for="entry in props.approval.history" :key="entry.id" class="flex flex-col gap-0.5">
              <span class="text-sm">{{ historyLine(entry) }}</span>
              <span class="text-xs text-muted-foreground">
                {{ formatDateTime(entry.occurred_at) }}
              </span>
              <span v-if="entry.reason !== null" class="text-xs text-muted-foreground">
                {{ t('i18n.pages.approvals.decide.reason') }}
                {{ entry.reason }}
              </span>
            </li>
          </ul>
        </CardSection>
      </SectionPanel>

      <SectionPanel section="decision">
        <CardSection :title="DECISION_TITLE" :description="DECISION_DESCRIPTION">
          <ReadOnlyNotice
            v-if="!props.approval.can_decide"
            :title="BLOCKED_TITLE"
            :reason="props.approval.decision_reason ?? ''"
            data-approval-blocked
          />

          <fieldset :disabled="!props.approval.can_decide" class="flex flex-col gap-4">
            <FormField
              for="approval-decision"
              :label="t('i18n.pages.approvals.decide.decision')"
              :error="errors.decision"
              class="sm:max-w-sm"
            >
              <Select v-model="decision">
                <SelectTrigger id="approval-decision" class="w-full">
                  <SelectValue :placeholder="t('i18n.pages.approvals.decide.please_select')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in DECISION_OPTIONS" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <FormField
              v-if="hasDelegation"
              for="approval-on-behalf-of"
              :label="t('i18n.pages.approvals.decide.decide_on_behalf_of')"
              :error="errors.onBehalfOfId"
              class="sm:max-w-sm"
            >
              <Combobox
                id="approval-on-behalf-of"
                v-model="onBehalfOfId"
                :options="props.approval.on_behalf_of_options"
                :searchable="props.approval.on_behalf_of_options.length > 8"
                :placeholder="t('i18n.pages.approvals.decide.select_person')"
              />
            </FormField>

            <FormField for="approval-reason" :error="errors.reason">
              <template #label
                >{{ t('i18n.pages.approvals.decide.reason_2')
                }}{{ requiresReason ? '' : t('i18n.pages.approvals.decide.optional') }}</template
              >
              <Textarea id="approval-reason" v-model="reason" rows="3" />
            </FormField>
          </fieldset>
        </CardSection>
      </SectionPanel>

      <SectionPanel v-if="props.approval.can_cancel" section="cancel" :actions="false">
        <CardSection :title="CANCEL_TITLE" :description="CANCEL_DESCRIPTION">
          <FormField for="approval-cancel-reason" :label="CANCEL_REASON_LABEL" :error="cancelErrors.reason">
            <Textarea id="approval-cancel-reason" v-model="cancelReason" rows="2" />
          </FormField>
          <Button
            type="button"
            variant="destructive"
            class="self-start"
            :disabled="cancelling"
            data-approval-cancel
            @click="cancelOpen = true"
          >
            {{ CANCEL_TITLE }}
          </Button>
        </CardSection>
      </SectionPanel>

      <template #actions>
        <FormActions type="button" :dirty="isDirty" :processing="processing" @cancel="requestLeave" @save="onSubmit" />
      </template>
    </SectionNav>

    <ConfirmDialog
      v-if="props.approval.can_cancel"
      :open="cancelOpen"
      :title="CANCEL_TITLE"
      :description="CANCEL_CONFIRM_DESCRIPTION"
      :confirm-label="CANCEL_TITLE"
      :pending="cancelling"
      variant="destructive"
      @confirm="onCancelProcess"
      @cancel="cancelOpen = false"
    />

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
