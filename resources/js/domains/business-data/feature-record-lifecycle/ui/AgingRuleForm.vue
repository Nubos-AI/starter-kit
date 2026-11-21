<script setup lang="ts">
import type { FormDataConvertible, FormDataType } from '@inertiajs/core';
import { computed, ref, useId } from 'vue';
import ObjectTypesController from '@/actions/App/Http/Controllers/Engine/ObjectTypesController';
import type {
  AgingClock,
  AgingRuleFields,
  AgingRuleModuleValues,
  AgingRulePayload,
  AgingRuleRow,
  AgingThreshold,
} from '@/shared/business-data/types/aging';
import { hasDuplicateDurations, sortThresholds } from '@/shared/business-data/types/aging';
import { useGuardedSheet } from '@/shared/composables/useGuardedSheet';
import { useI18n } from '@/shared/composables/useI18n';
import { useModuleOptions } from '@/shared/composables/useModuleOptions';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import { hydratableFilterTree, normalizeFilterTree } from '@/shared/record-format/utils/filterTree';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import InputError from '@/shared/ui/InputError.vue';
import { Input } from '@/shared/ui/primitives/input';
import { Label } from '@/shared/ui/primitives/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/shared/ui/primitives/sheet';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import FilterBuilder from '../../../../shared/filters/ui/FilterBuilder.vue';
import AgingThresholdEditor from './AgingThresholdEditor.vue';

const { t } = useI18n();

const CREATE_TITLE = t('i18n.components.engine.object_type.aging_rule_form.create_aging_rule');

const EDIT_TITLE = t('i18n.components.engine.object_type.aging_rule_form.edit_aging_rule');

const SHEET_DESCRIPTION = t(
  'i18n.components.engine.object_type.aging_rule_form.clock_optional_condition_and_thresholds_for_this_rule',
);

const CLOCK_FIELD_HINT = t(
  'i18n.components.engine.object_type.aging_rule_form.only_date_fields_of_this_object_type_that_are',
);

const CLOCK_FIELD_EMPTY_HINT = t(
  'i18n.components.engine.object_type.aging_rule_form.this_object_type_has_no_date_fields_that_can',
);

const CONDITION_EMPTY_HINT = t(
  'i18n.components.engine.object_type.aging_rule_form.without_a_condition_the_rule_applies_to_all_records',
);

const DEFAULT_THRESHOLD: AgingThreshold = { after_days: 7, color: 'amber' };

const props = defineProps<{
  rule: AgingRuleRow | null;
  clockFieldOptions: SelectOption[];
  conditionFields: FieldDefinition[];
  activeRuleCount: number;
  activeRuleLimit: number;
  errors: Record<string, string>;
  processing: boolean;
}>();

const emit = defineEmits<{
  submit: [payload: AgingRulePayload];
  close: [];
}>();

const clockOptions = useModuleOptions('aging.clocks', [
  {
    value: 'updated_at',
    label: t('i18n.components.engine.object_type.aging_rule_form.last_change'),
  },
  {
    value: 'field',
    label: t('i18n.components.engine.object_type.aging_rule_form.custom_date_field'),
  },
]);

const name = ref<string>(props.rule?.name ?? '');
const clock = ref<AgingClock>(props.rule?.clock ?? 'updated_at');
const clockFieldKey = ref<string>(props.rule?.clock_field_key ?? '');
const conditionSeed = ref<FilterGroupNode | undefined>(hydratableFilterTree(props.rule?.condition));
const conditionTree = ref<FilterGroupNode | null>(conditionSeed.value ?? null);
const thresholds = ref<AgingThreshold[]>(
  props.rule === null ? [{ ...DEFAULT_THRESHOLD }] : props.rule.thresholds.map((threshold) => ({ ...threshold })),
);
const activeLimitReached = computed<boolean>(
  () => props.rule?.is_active !== true && props.activeRuleCount >= props.activeRuleLimit,
);

const isActive = ref<boolean>(props.rule?.is_active ?? !activeLimitReached.value);
const moduleValues = ref<AgingRuleModuleValues>({});

const controlId = useId();

function fieldId(suffix: string): string {
  return `${controlId}-${suffix}`;
}

const heading = computed<string>(() => (props.rule === null ? CREATE_TITLE : EDIT_TITLE));

const measuresField = computed<boolean>(() => clock.value === 'field');

const clockFieldMissing = computed<boolean>(() => measuresField.value && props.clockFieldOptions.length === 0);

const activeLimitHint = computed<string>(() =>
  t('i18n.components.engine.object_type.aging_rule_form.this_object_type_already_has_the_maximum_supported_number', {
    value1: props.activeRuleLimit,
  }),
);

const hasDuplicateThresholds = computed<boolean>(() => hasDuplicateDurations(thresholds.value));

const { isDirty, promptOpen, confirmLeave, cancelLeave } = useUnsavedChanges({
  values: () => ({
    name: name.value,
    clock: clock.value,
    clockFieldKey: clockFieldKey.value,
    conditionTree: conditionTree.value,
    thresholds: thresholds.value,
    isActive: isActive.value,
    moduleValues: moduleValues.value,
  }),
  backHref: () => ObjectTypesController.index.url(),
});

const { closeRequested, onCancel, onConfirmLeave, onCancelLeave, onOpenChange } = useGuardedSheet({
  isDirty,
  confirmLeave,
  cancelLeave,
  close: () => emit('close'),
});

const canSubmit = computed<boolean>(() => isDirty.value && !hasDuplicateThresholds.value && !clockFieldMissing.value);

function onClockChange(value: unknown): void {
  const next = clockOptions.value.find((entry) => entry.value === String(value))?.value;

  if (next === undefined) {
    return;
  }

  clock.value = next;
}

function updateModuleValue(key: string, value: FormDataConvertible): void {
  moduleValues.value = { ...moduleValues.value, [key]: value };
}

function onConditionChange(tree: FilterGroupNode): void {
  conditionTree.value = normalizeFilterTree(tree);
}

function buildPayload(): AgingRulePayload {
  const fields: FormDataType<AgingRuleFields> = {
    name: name.value.trim(),
    clock: clock.value,
    clock_field_key: measuresField.value && clockFieldKey.value !== '' ? clockFieldKey.value : null,
    condition: conditionTree.value,
    thresholds: sortThresholds(thresholds.value),
    is_active: isActive.value,
  };

  return { ...moduleValues.value, ...fields };
}

function onSubmit(): void {
  if (!canSubmit.value) {
    return;
  }

  emit('submit', buildPayload());
}
</script>

<template>
  <div>
    <Sheet :open="true" @update:open="onOpenChange">
      <SheetContent side="right" class="w-full sm:max-w-xl">
        <div data-aging-form class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4">
          <SheetHeader class="px-0">
            <SheetTitle>{{ heading }}</SheetTitle>
            <SheetDescription>
              {{ SHEET_DESCRIPTION }}
            </SheetDescription>
          </SheetHeader>

          <FormField
            :for="fieldId('name')"
            :label="t('i18n.components.engine.object_type.aging_rule_form.name')"
            :error="props.errors.name"
          >
            <Input :id="fieldId('name')" v-model="name" name="name" />
          </FormField>

          <FormField
            :for="fieldId('clock')"
            :label="t('i18n.components.engine.object_type.aging_rule_form.clock')"
            :error="props.errors.clock"
          >
            <Select :model-value="clock" name="clock" @update:model-value="onClockChange">
              <SelectTrigger :id="fieldId('clock')" class="w-full">
                <SelectValue :placeholder="t('i18n.components.engine.object_type.aging_rule_form.select_clock')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem v-for="option in clockOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </FormField>

          <div v-if="measuresField" class="grid gap-2" data-aging-clock-field>
            <template v-if="props.clockFieldOptions.length > 0">
              <Label :for="fieldId('clock_field_key')">{{
                t('i18n.components.engine.object_type.aging_rule_form.date_field')
              }}</Label>
              <Select v-model="clockFieldKey" name="clock_field_key">
                <SelectTrigger :id="fieldId('clock_field_key')" class="w-full">
                  <SelectValue
                    :placeholder="t('i18n.components.engine.object_type.aging_rule_form.select_date_field')"
                  />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in props.clockFieldOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
              <p class="text-xs text-muted-foreground">
                {{ CLOCK_FIELD_HINT }}
              </p>
            </template>
            <p v-else class="text-sm text-muted-foreground" data-aging-clock-field-empty>
              {{ CLOCK_FIELD_EMPTY_HINT }}
            </p>
            <InputError :message="props.errors.clock_field_key" />
          </div>

          <FormField
            :label-id="fieldId('condition')"
            :label="t('i18n.components.engine.object_type.aging_rule_form.condition')"
            :error="props.errors.condition"
          >
            <p v-if="conditionTree === null" class="text-xs text-muted-foreground" data-aging-condition-empty>
              {{ CONDITION_EMPTY_HINT }}
            </p>
            <div role="group" :aria-labelledby="fieldId('condition')">
              <FilterBuilder
                :fields="props.conditionFields"
                :model-value="conditionSeed"
                :show-actions="false"
                @update:model-value="onConditionChange"
              />
            </div>
          </FormField>

          <FormField
            :label-id="fieldId('thresholds')"
            :label="t('i18n.components.engine.object_type.aging_rule_form.thresholds')"
            :error="props.errors.thresholds"
          >
            <div role="group" :aria-labelledby="fieldId('thresholds')">
              <AgingThresholdEditor v-model="thresholds" :errors="props.errors" />
            </div>
          </FormField>

          <FormCheckbox
            :id="fieldId('is_active')"
            v-model="isActive"
            :label="t('i18n.components.engine.object_type.aging_rule_form.active')"
            :description="activeLimitReached ? activeLimitHint : undefined"
            :error="props.errors.is_active"
            :disabled="activeLimitReached"
            data-aging-active-toggle
          />

          <UiExtensionPoint
            name="aging.rule-form"
            :context="{
              rule: props.rule,
              values: moduleValues,
              update: updateModuleValue,
              fieldId,
              errors: props.errors,
            }"
          />

          <FormActions
            type="button"
            :dirty="canSubmit"
            :processing="props.processing"
            @cancel="onCancel"
            @save="onSubmit"
          />
        </div>
      </SheetContent>
    </Sheet>

    <UnsavedChangesDialog :open="promptOpen || closeRequested" @confirm="onConfirmLeave" @cancel="onCancelLeave" />
  </div>
</template>
