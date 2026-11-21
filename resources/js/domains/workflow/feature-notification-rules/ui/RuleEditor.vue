<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import FormField from '@/shared/ui/FormField.vue';
import { Button } from '@/shared/ui/primitives/button';
import { Input } from '@/shared/ui/primitives/input';
import type {
  NotificationRuleItem,
  RuleAction,
  RuleConfig,
  RuleInput,
  RuleScope,
  RuleTriggerType,
} from '../data/useRules';
import { useRules } from '../data/useRules';
import RuleActionConfig from './RuleActionConfig.vue';
import RulePreview from './RulePreview.vue';
import RuleScopePicker from './RuleScopePicker.vue';
import type { TriggerState } from './RuleTriggerConfig.vue';
import RuleTriggerConfig from './RuleTriggerConfig.vue';

const { t } = useI18n();

const props = defineProps<{
  objectType: RecordObjectType;
  fields: FieldDefinition[];
  rule?: NotificationRuleItem | null;
}>();

const emit = defineEmits<{
  saved: [rule: NotificationRuleItem];
  cancel: [];
}>();

const { error, fieldErrors, previewCount, previewApproximate, previewLoading, create, update, preview } = useRules();

const name = ref<string>(String(props.rule?.name ?? ''));

const trigger = reactive<TriggerState>({
  triggerType: (props.rule?.trigger_type as RuleTriggerType) ?? 'date_based',
  config: (props.rule?.config as RuleConfig) ?? {
    date_field_key: '',
    lead_stages: [],
  },
});

const scope = reactive<RuleScope>({
  segment_id: (props.rule?.segment_id as string | null) ?? null,
  filter_definition: (props.rule?.filter_definition as RuleScope['filter_definition']) ?? null,
});

const action = reactive<RuleAction>(
  (props.rule?.action as RuleAction) ?? {
    notify: true,
    create_reminder: null,
  },
);

const saving = ref<boolean>(false);

function onTriggerUpdate(value: TriggerState): void {
  trigger.triggerType = value.triggerType;
  trigger.config = value.config;
}

function onScopeUpdate(value: RuleScope): void {
  scope.segment_id = value.segment_id;
  scope.filter_definition = value.filter_definition;
}

function onActionUpdate(value: RuleAction): void {
  action.notify = value.notify;
  action.create_reminder = value.create_reminder;
}

watch(
  () => [props.objectType.id, scope.segment_id, JSON.stringify(scope.filter_definition)],
  () => {
    void preview({
      object_type_id: props.objectType.id,
      segment_id: scope.segment_id,
      filter_definition: scope.filter_definition,
    });
  },
);

async function onSave(): Promise<void> {
  saving.value = true;

  const input: RuleInput = {
    object_type_id: props.objectType.id,
    name: name.value.trim(),
    trigger_type: trigger.triggerType,
    config: trigger.config,
    segment_id: scope.segment_id,
    filter_definition: scope.filter_definition,
    action,
    is_active: (props.rule?.is_active as boolean | undefined) ?? true,
  };

  const result = props.rule && props.rule.id !== '' ? await update(props.rule.id, input) : await create(input);

  saving.value = false;

  if (result !== null) {
    emit('saved', result);
  }
}
</script>

<template>
  <form class="flex flex-col gap-6" @submit.prevent="onSave">
    <FormField for="rule-name" :label="t('i18n.components.engine.rules.rule_editor.name')">
      <Input
        id="rule-name"
        v-model="name"
        :placeholder="t('i18n.components.engine.rules.rule_editor.rule_name')"
        required
      />
    </FormField>

    <RuleTriggerConfig
      :fields="fields"
      :model-value="trigger"
      :errors="fieldErrors"
      @update:model-value="onTriggerUpdate"
    />

    <RuleScopePicker
      :object-type="objectType"
      :fields="fields"
      :model-value="scope"
      @update:model-value="onScopeUpdate"
    />

    <RuleActionConfig :model-value="action" @update:model-value="onActionUpdate" />

    <RulePreview :count="previewCount" :approximate="previewApproximate" :loading="previewLoading" :error="error" />

    <div class="flex items-center gap-2">
      <Button type="submit" :disabled="saving || name.trim() === ''">
        {{ t('i18n.components.engine.rules.rule_editor.save') }}
      </Button>
      <Button type="button" variant="outline" @click="emit('cancel')">
        {{ t('i18n.components.engine.rules.rule_editor.cancel') }}
      </Button>
    </div>
  </form>
</template>
