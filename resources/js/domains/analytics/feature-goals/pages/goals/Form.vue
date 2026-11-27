<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import GoalsController from '@/actions/App/Http/Controllers/Goals/GoalsController';
import type { GoalDirection, GoalListRow, GoalPeriodType, GoalScopeType } from '@/domains/analytics/data';
import {
  GOAL_DIRECTION_HINTS,
  GOAL_PERIOD_SNAPSHOT_LABEL,
  GOAL_SCOPE_FIELD_DEFAULTS,
  resolveGoalOptionRefusal,
} from '@/domains/analytics/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { submitCreateOrUpdate } from '@/shared/lib/formSubmit';
import { numberInputFormat } from '@/shared/lib/localizedNumber';
import type { SelectOption } from '@/shared/types/ui';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import { NumberInput } from '@/shared/ui/primitives/number-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import GoalPeriodHistory from '../../ui/GoalPeriodHistory.vue';
import GoalProgressCard from '../../ui/GoalProgressCard.vue';

const { t } = useI18n();

const SEARCHABLE_THRESHOLD = 8;

const SCOPE_FIELD_DEFAULT_KEYS = Object.values(GOAL_SCOPE_FIELD_DEFAULTS);

const props = defineProps<{
  mode: 'create' | 'edit';
  goal: GoalListRow | null;
  reportOptions: SelectOption[];
  scopeFieldsByReport: Record<string, SelectOption[]>;
  periodFieldsByReport: Record<string, SelectOption[]>;
  userOptions: SelectOption[];
  teamOptions: SelectOption[];
  scopeTypeOptions: SelectOption[];
  periodTypeOptions: SelectOption[];
  directionOptions: SelectOption[];
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
  isEdit.value ? (props.goal?.name ?? t('i18n.pages.goals.form.goal')) : t('i18n.pages.goals.form.new_goal'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const name = ref<string>(props.goal?.name ?? '');
const reportId = ref<string | null>(props.goal?.report_id ?? null);
const scopeType = ref<GoalScopeType>(props.goal?.scope_type ?? 'user');
const targetUserId = ref<string | null>(props.goal?.target_user_id ?? null);
const targetTeamId = ref<string | null>(props.goal?.target_team_id ?? null);
const includesSubteams = ref<boolean>(props.goal?.includes_subteams ?? false);
const scopeFieldKey = ref<string | null>(props.goal?.scope_field_key ?? null);
const periodFieldKey = ref<string | null>(props.goal?.period_field_key ?? '');
const periodType = ref<GoalPeriodType>(props.goal?.period_type ?? 'month');
const direction = ref<GoalDirection>(props.goal?.direction ?? 'at_least');
const targetValue = ref<string>(props.goal?.target_value ?? '');

const form = useForm<Record<string, string>>({});

const isTeamScope = computed<boolean>(() => scopeType.value === 'team');

const isTenantScope = computed<boolean>(() => scopeType.value === 'tenant');

const sourceOptions = computed<SelectOption[]>(() =>
  props.reportOptions.map((option) => ({
    ...option,
    disabledReason: resolveGoalOptionRefusal(option.disabledReason, t),
  })),
);

const targetOptions = computed<SelectOption[]>(() => (isTeamScope.value ? props.teamOptions : props.userOptions));

const targetId = computed<string | null>({
  get: () => (isTeamScope.value ? targetTeamId.value : targetUserId.value),
  set: (value) => {
    if (isTeamScope.value) {
      targetTeamId.value = value;

      return;
    }

    targetUserId.value = value;
  },
});

const scopeFieldOptions = computed<SelectOption[]>(() => props.scopeFieldsByReport[reportId.value ?? ''] ?? []);

const periodFieldOptions = computed<SelectOption[]>(() => [
  { value: '', label: t(GOAL_PERIOD_SNAPSHOT_LABEL) },
  ...(props.periodFieldsByReport[reportId.value ?? ''] ?? []),
]);

const directionHint = computed<string>(() => t(GOAL_DIRECTION_HINTS[direction.value]));

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'general',
      title: t('i18n.pages.goals.form.basic_information'),
      fields: ['name'],
    },
    {
      key: 'source',
      title: t('i18n.pages.goals.form.source'),
      fields: ['report_id'],
    },
    {
      key: 'assignment',
      title: t('i18n.pages.goals.form.assignment'),
      fields: ['scope_type', 'target_user_id', 'target_team_id', 'includes_subteams', 'scope_field_key'],
    },
    {
      key: 'period',
      title: t('i18n.pages.goals.form.period'),
      fields: ['period_field_key', 'period_type'],
    },
    {
      key: 'target',
      title: t('i18n.pages.goals.form.goal'),
      fields: ['direction', 'target_value'],
    },
    ...(isEdit.value
      ? [
          {
            key: 'progress',
            title: t('i18n.pages.goals.form.sections.progress'),
          },
          {
            key: 'history',
            title: t('i18n.pages.goals.form.sections.history'),
          },
        ]
      : []),
  ],
  () => form.errors,
);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => snapshot(),
  backHref: GoalsController.index.url(),
});

watch(scopeType, (next) => {
  if (next === 'tenant') {
    targetUserId.value = null;
    targetTeamId.value = null;
    includesSubteams.value = false;
    scopeFieldKey.value = null;

    return;
  }

  prefillScopeField(next);
});

watch(reportId, () => {
  const followedScope = followsScope(scopeFieldKey.value);

  if (!isOffered(scopeFieldOptions.value, scopeFieldKey.value)) {
    scopeFieldKey.value = null;
  }

  if (!isOffered(periodFieldOptions.value, periodFieldKey.value)) {
    periodFieldKey.value = '';
  }

  if (followedScope) {
    prefillScopeField(scopeType.value);
  }
});

function isOffered(options: SelectOption[], value: string | null): boolean {
  return options.some((option) => option.value === value);
}

function followsScope(value: string | null): boolean {
  const current = blankToNull(value);

  return current === null || SCOPE_FIELD_DEFAULT_KEYS.includes(current);
}

function prefillScopeField(scope: GoalScopeType): void {
  if (scope === 'tenant' || !followsScope(scopeFieldKey.value)) {
    return;
  }

  const fallback = GOAL_SCOPE_FIELD_DEFAULTS[scope];

  if (isOffered(scopeFieldOptions.value, fallback)) {
    scopeFieldKey.value = fallback;
  }
}

function blankToNull(value: string | null): string | null {
  return value === null || value === '' ? null : value;
}

function snapshot(): Record<string, unknown> {
  return {
    name: name.value,
    reportId: reportId.value,
    scopeType: scopeType.value,
    targetUserId: targetUserId.value,
    targetTeamId: targetTeamId.value,
    includesSubteams: includesSubteams.value,
    scopeFieldKey: scopeFieldKey.value,
    periodFieldKey: periodFieldKey.value,
    periodType: periodType.value,
    direction: direction.value,
    targetValue: targetValue.value,
  };
}

function submitPayload(): Record<string, unknown> {
  return {
    name: name.value,
    report_id: blankToNull(reportId.value),
    scope_type: scopeType.value,
    target_user_id: blankToNull(targetUserId.value),
    target_team_id: blankToNull(targetTeamId.value),
    includes_subteams: includesSubteams.value,
    scope_field_key: blankToNull(scopeFieldKey.value),
    period_field_key: blankToNull(periodFieldKey.value),
    period_type: periodType.value,
    direction: direction.value,
    target_value: targetValue.value,
  };
}

function onSaved(): void {
  markSaved();
  toast.success(t('i18n.pages.goals.form.the_goal_was_saved'));
}

function onSubmit(): void {
  const goal = props.goal;

  submitCreateOrUpdate(form, submitPayload, {
    updateUrl: isEdit.value && goal !== null ? GoalsController.update.url({ goal: goal.id }) : null,
    storeUrl: GoalsController.store.url(),
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
        ? t('i18n.pages.goals.form.create_a_new_goal_based_on_a_report')
        : t('i18n.pages.goals.form.edit_this_goal_s_definition')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.goals.form.new_goal')
        : t('i18n.pages.goals.form.edit_goal', {
            value1: goal?.name,
          })
    "
  >
    <form class="flex flex-col gap-6" @submit.prevent="onSubmit">
      <SectionNav
        v-model="activeSection"
        :entries="sections"
        :label="t('i18n.pages.goals.form.sections.label')"
        data-goal-sections
      >
        <SectionPanel section="general">
          <CardSection
            :title="t('i18n.pages.goals.form.basic_information')"
            :description="t('i18n.pages.goals.form.the_name_appears_in_the_list_and_above_the')"
          >
            <FormField
              data-goal-field="name"
              for="goal-name"
              :label="t('i18n.pages.goals.form.name')"
              :error="form.errors.name"
              class="sm:max-w-sm"
            >
              <Input id="goal-name" v-model="name" :placeholder="t('i18n.pages.goals.form.goal_name')" required />
            </FormField>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="source">
          <CardSection
            :title="t('i18n.pages.goals.form.source')"
            :description="t('i18n.pages.goals.form.a_goal_measures_exactly_one_metric_reports_with_grouping')"
          >
            <FormField
              data-goal-field="report"
              for="goal-report"
              :label="t('i18n.pages.goals.form.report')"
              :error="form.errors.report_id"
              class="sm:max-w-sm"
            >
              <Combobox
                id="goal-report"
                v-model="reportId"
                :options="sourceOptions"
                :searchable="sourceOptions.length > SEARCHABLE_THRESHOLD"
                :placeholder="t('i18n.pages.goals.form.select_report')"
                :empty-label="t('i18n.pages.goals.form.no_reports_available')"
              />
            </FormField>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="assignment">
          <CardSection
            :title="t('i18n.pages.goals.form.assignment')"
            :description="t('i18n.pages.goals.form.the_assignment_determines_whose_numbers_the_goal_measures_and')"
          >
            <div class="grid items-start gap-4 sm:grid-cols-2">
              <FormField
                data-goal-field="scope-type"
                for="goal-scope-type"
                :label="t('i18n.pages.goals.form.applies_to')"
                :error="form.errors.scope_type"
              >
                <Select v-model="scopeType">
                  <SelectTrigger id="goal-scope-type" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.goals.form.select_assignment')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in scopeTypeOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                v-if="!isTenantScope"
                data-goal-field="target"
                for="goal-target"
                :label="isTeamScope ? t('i18n.pages.goals.form.team') : t('i18n.pages.goals.form.person')"
                :error="isTeamScope ? form.errors.target_team_id : form.errors.target_user_id"
              >
                <Combobox
                  id="goal-target"
                  v-model="targetId"
                  :options="targetOptions"
                  :searchable="targetOptions.length > SEARCHABLE_THRESHOLD"
                  :placeholder="
                    isTeamScope ? t('i18n.pages.goals.form.select_team') : t('i18n.pages.goals.form.select_person')
                  "
                  :empty-label="t('i18n.pages.goals.form.no_options_available')"
                />
              </FormField>

              <FormField
                v-if="!isTenantScope"
                data-goal-field="scope-field"
                for="goal-scope-field"
                :label="t('i18n.pages.goals.form.restricting_field')"
                :error="form.errors.scope_field_key"
              >
                <Combobox
                  id="goal-scope-field"
                  v-model="scopeFieldKey"
                  :options="scopeFieldOptions"
                  :searchable="scopeFieldOptions.length > SEARCHABLE_THRESHOLD"
                  :placeholder="t('i18n.pages.goals.form.select_field')"
                  :empty-label="t('i18n.pages.goals.form.please_select_a_report_first')"
                />
              </FormField>

              <div v-if="isTeamScope" class="grid gap-2" data-goal-field="subteams">
                <FormCheckbox
                  v-model="includesSubteams"
                  name="includes_subteams"
                  :label="t('i18n.pages.goals.form.include_subteams')"
                />
                <InputError :message="form.errors.includes_subteams" />
              </div>
            </div>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="period">
          <CardSection
            :title="t('i18n.pages.goals.form.period')"
            :description="t('i18n.pages.goals.form.the_time_reference_determines_whether_the_metric_is_limited')"
          >
            <div class="grid items-start gap-4 sm:grid-cols-2">
              <FormField
                data-goal-field="period-field"
                for="goal-period-field"
                :label="t('i18n.pages.goals.form.time_reference')"
                :error="form.errors.period_field_key"
              >
                <Combobox
                  id="goal-period-field"
                  v-model="periodFieldKey"
                  :options="periodFieldOptions"
                  :searchable="periodFieldOptions.length > SEARCHABLE_THRESHOLD"
                  :placeholder="t('i18n.pages.goals.form.select_time_reference')"
                />
              </FormField>

              <FormField
                data-goal-field="period-type"
                for="goal-period-type"
                :label="t('i18n.pages.goals.form.period_type')"
                :error="form.errors.period_type"
              >
                <Select v-model="periodType">
                  <SelectTrigger id="goal-period-type" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.goals.form.select_period_type')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in periodTypeOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>
            </div>
          </CardSection>
        </SectionPanel>

        <SectionPanel section="target">
          <CardSection
            :title="t('i18n.pages.goals.form.goal')"
            :description="t('i18n.pages.goals.form.the_direction_and_target_value_determine_when_the_goal')"
          >
            <div class="grid items-start gap-4 sm:grid-cols-2">
              <FormField
                data-goal-field="direction"
                for="goal-direction"
                :label="t('i18n.pages.goals.form.direction')"
                :error="form.errors.direction"
                :hint="directionHint"
              >
                <Select v-model="direction">
                  <SelectTrigger id="goal-direction" class="w-full">
                    <SelectValue :placeholder="t('i18n.pages.goals.form.select_direction')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem v-for="option in directionOptions" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <FormField
                data-goal-field="target-value"
                for="goal-target-value"
                :label="t('i18n.pages.goals.form.target_value')"
                :error="form.errors.target_value"
              >
                <NumberInput
                  id="goal-target-value"
                  v-model="targetValue"
                  :format="numberInputFormat('decimal')"
                  :placeholder="t('i18n.pages.goals.form.e_g_100000')"
                  required
                />
              </FormField>
            </div>
          </CardSection>
        </SectionPanel>

        <template v-if="isEdit && goal !== null">
          <SectionPanel section="progress" bare>
            <GoalProgressCard :goal="goal" />
          </SectionPanel>
          <SectionPanel section="history" bare>
            <GoalPeriodHistory :goal="goal" />
          </SectionPanel>
        </template>

        <template #actions>
          <FormActions :dirty="isDirty" :processing="form.processing" @cancel="requestLeave" />
        </template>
      </SectionNav>
    </form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
