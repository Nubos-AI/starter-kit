<script setup lang="ts">
import type { FormDataType } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import MergeRulesController from '@/actions/App/Http/Controllers/Engine/MergeRulesController';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import type {
  MergeFieldStrategy,
  MergeFieldStrategyOption,
  MergeRuleMode,
  MergeRuleObjectType,
  MergeRuleOption,
  MergeRulePayload,
  MergeRuleRow,
  MergeTransferCategoryOption,
  MergeTransferPolicy,
} from '@/domains/business-data/data';
import {
  MERGE_FIELD_STRATEGY_LABELS,
  MERGE_RULE_MODE_LABELS,
  MERGE_RULE_MODES,
  MERGE_RULE_OPTION_LABELS,
  MERGE_RULE_OPTIONS,
  MERGE_TRANSFER_POLICY_LABELS,
  mergeTransferCategoryLabel,
} from '@/domains/business-data/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useModuleOptions } from '@/shared/composables/useModuleOptions';
import { useSectionTabs } from '@/shared/composables/useSectionTabs';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { hydratableFilterTree, normalizeFilterTree } from '@/shared/record-format/utils/filterTree';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import CardSection from '@/shared/ui/CardSection.vue';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import FormSwitch from '@/shared/ui/FormSwitch.vue';
import InputError from '@/shared/ui/InputError.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import SectionNav from '@/shared/ui/SectionNav.vue';
import SectionPanel from '@/shared/ui/SectionPanel.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../../../shared/filters/ui/FilterBuilder.vue';

const { t } = useI18n();

const CREATE_TITLE = t('i18n.pages.object_types.merge_rules.form.create_merge_rule');

const EDIT_TITLE = t('i18n.pages.object_types.merge_rules.form.edit_merge_rule');

const PAGE_DESCRIPTION = t(
  'i18n.pages.object_types.merge_rules.form.rules_are_checked_in_ascending_order_the_first_match',
);

const SECTION_TITLE = t('i18n.pages.object_types.merge_rules.form.merge_rules');

const INHERIT_VALUE = '__inherit';

const INHERIT_LABEL = t('i18n.pages.object_types.merge_rules.form.default');

const BASICS_TITLE = t('i18n.pages.object_types.merge_rules.form.basic_information');

const BASICS_DESCRIPTION = t(
  'i18n.pages.object_types.merge_rules.form.name_effect_and_the_position_at_which_this_rule',
);

const CONDITION_TITLE = t('i18n.pages.object_types.merge_rules.form.condition');

const CONDITION_DESCRIPTION = t(
  'i18n.pages.object_types.merge_rules.form.without_a_condition_the_rule_applies_to_all_records',
);

const MODE_HINT_ALLOW = t(
  'i18n.pages.object_types.merge_rules.form.the_rule_allows_merging_and_determines_which_value_wins',
);

const MODE_HINT_DENY = t(
  'i18n.pages.object_types.merge_rules.form.the_rule_prevents_merging_it_applies_when_either_record',
);

const STRATEGIES_TITLE = t('i18n.pages.object_types.merge_rules.form.field_resolution');

const STRATEGIES_DESCRIPTION = t(
  'i18n.pages.object_types.merge_rules.form.which_value_wins_when_both_records_contain_a_value',
);

const STRATEGY_EMPTY_HINT = t('i18n.pages.object_types.merge_rules.form.this_object_type_has_no_fields_for_which_a');

const TRANSFERS_TITLE = t('i18n.pages.object_types.merge_rules.form.what_moves_with_the_record');

const TRANSFERS_DESCRIPTION = t(
  'i18n.pages.object_types.merge_rules.form.for_each_category_move_to_the_target_leave_at',
);

const OPTIONS_TITLE = t('i18n.pages.object_types.merge_rules.form.additional_conditions');

const OPTIONS_DESCRIPTION = t('i18n.pages.object_types.merge_rules.form.switches_that_further_restrict_merging');

const props = defineProps<{
  objectType: MergeRuleObjectType;
  mergeRule: MergeRuleRow | null;
  mergeConditionFields: FieldDefinition[];
  mergeFieldStrategyOptions: MergeFieldStrategyOption[];
  mergeTransferCategories: MergeTransferCategoryOption[];
  mergeActiveRuleLimit: number;
  activeRuleCount: number;
}>();

const listHref = computed<string>(() =>
  ObjectTypesController.mergeRules.url({
    objectType: props.objectType.slug,
  }),
);

const heading = computed<string>(() => (props.mergeRule === null ? CREATE_TITLE : EDIT_TITLE));

usePageBreadcrumbs(() => [
  {
    title: props.objectType.label,
    href: ObjectTypesController.edit({ objectType: props.objectType.slug }),
  },
  {
    title: SECTION_TITLE,
    href: ObjectTypesController.mergeRules({
      objectType: props.objectType.slug,
    }),
  },
  { title: heading.value },
]);

const modeOptions = MERGE_RULE_MODES.map((mode) => ({
  value: mode,
  label: t(MERGE_RULE_MODE_LABELS[mode]),
}));

const name = ref<string>(props.mergeRule?.name ?? '');
const mode = ref<MergeRuleMode>(props.mergeRule?.mode ?? 'allow');
const position = ref<string>(String(props.mergeRule?.position ?? 0));
const denyReason = ref<string>(props.mergeRule?.deny_reason ?? '');
const conditionSeed = ref<FilterGroupNode | undefined>(hydratableFilterTree(props.mergeRule?.condition));
const conditionTree = ref<FilterGroupNode | null>(conditionSeed.value ?? null);

const fieldStrategies = ref<Record<string, string>>(
  Object.fromEntries(
    props.mergeFieldStrategyOptions.map((option) => [
      option.key,
      props.mergeRule?.field_strategies[option.key] ?? INHERIT_VALUE,
    ]),
  ),
);

const transferPolicies = ref<Record<string, string>>(
  Object.fromEntries(
    props.mergeTransferCategories.map((category) => [
      category.value,
      props.mergeRule?.transfer_policy[category.value] ?? INHERIT_VALUE,
    ]),
  ),
);

const mergeOptions = useModuleOptions(
  'merge.options',
  MERGE_RULE_OPTIONS.map((value) => ({
    value,
    label: t(MERGE_RULE_OPTION_LABELS[value]),
  })),
);

const options = ref<Record<string, boolean>>(
  Object.fromEntries(
    mergeOptions.value.map((option) => [option.value, props.mergeRule?.options[option.value] ?? false]),
  ),
);

const activeLimitReached = computed<boolean>(
  () => props.mergeRule?.is_active !== true && props.activeRuleCount >= props.mergeActiveRuleLimit,
);

const isActive = ref<boolean>(props.mergeRule?.is_active ?? !activeLimitReached.value);

const errors = ref<Record<string, string>>({});
const processing = ref<boolean>(false);

const forbids = computed<boolean>(() => mode.value === 'deny');

const modeHint = computed<string>(() => (forbids.value ? MODE_HINT_DENY : MODE_HINT_ALLOW));

const hasStrategyFields = computed<boolean>(() => props.mergeFieldStrategyOptions.length > 0);

const {
  active: activeSection,
  sections,
  revealErrors,
} = useSectionTabs(
  () => [
    {
      key: 'basics',
      title: BASICS_TITLE,
      fields: ['name', 'mode', 'position', 'deny_reason', 'is_active'],
    },
    { key: 'condition', title: CONDITION_TITLE, fields: ['condition'] },
    ...(forbids.value
      ? []
      : [
          {
            key: 'strategies',
            title: STRATEGIES_TITLE,
            fields: ['field_strategies'],
          },
          {
            key: 'transfers',
            title: TRANSFERS_TITLE,
            fields: ['transfer_policy'],
          },
          {
            key: 'options',
            title: OPTIONS_TITLE,
            fields: ['options'],
          },
        ]),
  ],
  () => errors.value,
);

const activeLimitHint = computed<string>(() =>
  t('i18n.pages.object_types.merge_rules.form.this_object_type_already_has_the_maximum_supported_number', {
    value1: props.mergeActiveRuleLimit,
  }),
);

const positionValue = computed<number>(() => {
  const parsed = Number.parseInt(position.value, 10);

  return Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;
});

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    mode: mode.value,
    position: position.value,
    denyReason: denyReason.value,
    conditionTree: conditionTree.value,
    fieldStrategies: fieldStrategies.value,
    transferPolicies: transferPolicies.value,
    options: options.value,
    isActive: isActive.value,
  }),
  backHref: () => listHref.value,
});

const canSubmit = computed<boolean>(
  () => isDirty.value && name.value.trim() !== '' && (!forbids.value || denyReason.value.trim() !== ''),
);

function onModeChange(value: unknown): void {
  const next = MERGE_RULE_MODES.find((entry) => entry === String(value));

  if (next === undefined) {
    return;
  }

  mode.value = next;
}

function onConditionChange(tree: FilterGroupNode): void {
  conditionTree.value = normalizeFilterTree(tree);
}

function strategyLabel(strategy: MergeFieldStrategy): string {
  return t(MERGE_FIELD_STRATEGY_LABELS[strategy]);
}

function policyLabel(policy: MergeTransferPolicy): string {
  return t(MERGE_TRANSFER_POLICY_LABELS[policy]);
}

function categoryLabel(category: MergeTransferCategoryOption): string {
  return mergeTransferCategoryLabel(category.value, t);
}

function chosenMap<T extends string>(source: Record<string, string>): Record<string, T> {
  const chosen: Record<string, T> = {};

  for (const [key, value] of Object.entries(source)) {
    if (value !== INHERIT_VALUE) {
      chosen[key] = value as T;
    }
  }

  return chosen;
}

function payload(): MergeRulePayload {
  const forbidding = forbids.value;

  return {
    name: name.value.trim(),
    mode: mode.value,
    position: positionValue.value,
    is_active: isActive.value,
    deny_reason: forbidding ? denyReason.value.trim() : null,
    condition: conditionTree.value,
    field_strategies: forbidding ? {} : chosenMap<MergeFieldStrategy>(fieldStrategies.value),
    transfer_policy: forbidding ? {} : chosenMap<MergeTransferPolicy>(transferPolicies.value),
    options: forbidding ? {} : (options.value as Partial<Record<MergeRuleOption, boolean>>),
  };
}

function onSubmit(): void {
  if (!canSubmit.value) {
    return;
  }

  const rule = props.mergeRule;

  const writeOptions = {
    onStart: (): void => {
      processing.value = true;
    },
    onError: (received: Record<string, string>): void => {
      errors.value = received;
      revealErrors(received);
    },
    onSuccess: (): void => {
      errors.value = {};
      markSaved();
    },
    onFinish: (): void => {
      processing.value = false;
    },
  };

  if (rule === null) {
    router.post<FormDataType<MergeRulePayload>>(
      MergeRulesController.store.url({
        objectType: props.objectType.slug,
      }),
      payload(),
      writeOptions,
    );

    return;
  }

  router.put<FormDataType<MergeRulePayload>>(
    MergeRulesController.update.url({
      objectType: props.objectType.slug,
      mergeRule: rule.id,
    }),
    payload(),
    writeOptions,
  );
}
</script>

<template>
  <PageShell :title="heading" :description="PAGE_DESCRIPTION" :head-title="`${objectType.label} — ${heading}`">
    <SectionNav
      v-model="activeSection"
      :entries="sections"
      :label="t('i18n.pages.object_types.merge_rules.form.sections.label')"
      data-merge-rule-sections
    >
      <SectionPanel section="basics">
        <CardSection :title="BASICS_TITLE" :description="BASICS_DESCRIPTION">
          <FormField
            for="merge-rule-name"
            :label="t('i18n.pages.object_types.merge_rules.form.name')"
            :error="errors.name"
          >
            <Input id="merge-rule-name" v-model="name" name="name" />
          </FormField>

          <div class="grid items-start gap-4 sm:grid-cols-2">
            <FormField
              for="merge-rule-mode"
              :label="t('i18n.pages.object_types.merge_rules.form.effect')"
              :error="errors.mode"
            >
              <Select :model-value="mode" name="mode" @update:model-value="onModeChange">
                <SelectTrigger id="merge-rule-mode" class="w-full">
                  <SelectValue :placeholder="t('i18n.pages.object_types.merge_rules.form.select_effect')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in modeOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </FormField>

            <FormField
              for="merge-rule-position"
              :label="t('i18n.pages.object_types.merge_rules.form.order')"
              :error="errors.position"
            >
              <Input id="merge-rule-position" v-model="position" name="position" type="number" min="0" />
            </FormField>
          </div>

          <p class="text-xs text-muted-foreground" data-merge-mode-hint>
            {{ modeHint }}
          </p>

          <FormField
            v-if="forbids"
            data-merge-deny-reason
            for="merge-rule-deny-reason"
            :label="t('i18n.pages.object_types.merge_rules.form.reason')"
            :error="errors.deny_reason"
            :hint="t('i18n.pages.object_types.merge_rules.form.this_text_appears_in_the_menu_and_wizard_when')"
          >
            <Input id="merge-rule-deny-reason" v-model="denyReason" name="deny_reason" />
          </FormField>

          <FormSwitch
            id="merge-rule-active"
            v-model="isActive"
            :label="t('i18n.pages.object_types.merge_rules.form.active')"
            :description="activeLimitReached ? activeLimitHint : undefined"
            :error="errors.is_active"
            :disabled="activeLimitReached"
            data-merge-active-toggle
          />
        </CardSection>
      </SectionPanel>

      <SectionPanel section="condition">
        <CardSection :title="CONDITION_TITLE" :description="CONDITION_DESCRIPTION">
          <div role="group" :aria-label="t('i18n.pages.object_types.merge_rules.form.condition')">
            <FilterBuilder
              :fields="props.mergeConditionFields"
              :model-value="conditionSeed"
              :show-actions="false"
              @update:model-value="onConditionChange"
            />
          </div>
          <InputError :message="errors.condition" />
        </CardSection>
      </SectionPanel>

      <template v-if="!forbids">
        <SectionPanel section="strategies" data-merge-strategies>
          <CardSection :title="STRATEGIES_TITLE" :description="STRATEGIES_DESCRIPTION">
            <p v-if="!hasStrategyFields" class="text-sm text-muted-foreground" data-merge-strategies-empty>
              {{ STRATEGY_EMPTY_HINT }}
            </p>
            <div v-else class="grid items-start gap-4 sm:grid-cols-2">
              <FormField
                v-for="option in props.mergeFieldStrategyOptions"
                :key="option.key"
                :for="`merge-strategy-${option.key}`"
                :label="option.label"
              >
                <Select v-model="fieldStrategies[option.key]">
                  <SelectTrigger :id="`merge-strategy-${option.key}`" class="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem :value="INHERIT_VALUE">
                      {{ INHERIT_LABEL }}
                    </SelectItem>
                    <SelectItem v-for="strategy in option.strategies" :key="strategy" :value="strategy">
                      {{ strategyLabel(strategy) }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>
            </div>
            <InputError :message="errors.field_strategies" />
          </CardSection>
        </SectionPanel>

        <SectionPanel section="transfers" data-merge-transfers>
          <CardSection :title="TRANSFERS_TITLE" :description="TRANSFERS_DESCRIPTION">
            <div class="grid items-start gap-4 sm:grid-cols-2">
              <FormField
                v-for="category in props.mergeTransferCategories"
                :key="category.value"
                :for="`merge-transfer-${category.value}`"
                :label="categoryLabel(category)"
              >
                <Select v-model="transferPolicies[category.value]" :disabled="category.policies.length < 2">
                  <SelectTrigger :id="`merge-transfer-${category.value}`" class="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem :value="INHERIT_VALUE">
                      {{ INHERIT_LABEL }} ({{ policyLabel(category.default_policy) }})
                    </SelectItem>
                    <SelectItem v-for="policy in category.policies" :key="policy" :value="policy">
                      {{ policyLabel(policy) }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>
            </div>
            <InputError :message="errors.transfer_policy" />
          </CardSection>
        </SectionPanel>

        <SectionPanel section="options" data-merge-options>
          <CardSection :title="OPTIONS_TITLE" :description="OPTIONS_DESCRIPTION">
            <FormSwitch
              v-for="option in mergeOptions"
              :id="`merge-option-${option.value}`"
              :key="option.value"
              v-model="options[option.value]"
              :label="option.label"
              :data-merge-option="option.value"
            />
            <InputError :message="errors.options" />
          </CardSection>
        </SectionPanel>
      </template>

      <template #actions>
        <FormActions
          type="button"
          :dirty="canSubmit"
          :processing="processing"
          @cancel="requestLeave"
          @save="onSubmit"
        />
      </template>
    </SectionNav>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
