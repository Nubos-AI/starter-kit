<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Badge } from '@/shared/ui/primitives/badge';
import { Input } from '@/shared/ui/primitives/input';
import type { RuleAction } from '../data/useRules';

const { t } = useI18n();

const props = defineProps<{
  modelValue: RuleAction;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: RuleAction];
}>();

const reminderEnabled = computed<boolean>(() => props.modelValue.create_reminder !== null);

function toggleReminder(enabled: boolean | 'indeterminate'): void {
  emit('update:modelValue', {
    ...props.modelValue,
    create_reminder: enabled === true ? { subject: '', due_offset: 0 } : null,
  });
}

function updateSubject(subject: string): void {
  if (props.modelValue.create_reminder === null) {
    return;
  }

  emit('update:modelValue', {
    ...props.modelValue,
    create_reminder: { ...props.modelValue.create_reminder, subject },
  });
}

function updateDueOffset(value: string): void {
  if (props.modelValue.create_reminder === null) {
    return;
  }

  emit('update:modelValue', {
    ...props.modelValue,
    create_reminder: {
      ...props.modelValue.create_reminder,
      due_offset: Number.parseInt(value, 10) || 0,
    },
  });
}
</script>

<template>
  <section :aria-label="t('i18n.components.engine.rules.rule_action_config.action')" class="flex flex-col gap-4">
    <div class="flex items-center gap-2">
      <span class="text-sm font-medium">{{ t('i18n.components.engine.rules.rule_action_config.notify') }}</span>
      <Badge variant="secondary">{{ t('i18n.components.engine.rules.rule_action_config.always_active') }}</Badge>
    </div>

    <div class="flex flex-col gap-3">
      <FormCheckbox
        id="rule-action-reminder"
        :model-value="reminderEnabled"
        :label="t('i18n.components.engine.rules.rule_action_config.create_reminder_task')"
        @update:model-value="toggleReminder"
      />

      <div v-if="modelValue.create_reminder !== null" class="grid gap-3 sm:grid-cols-2">
        <FormField for="rule-reminder-subject" :label="t('i18n.components.engine.rules.rule_action_config.subject')">
          <Input
            id="rule-reminder-subject"
            :model-value="modelValue.create_reminder.subject"
            :placeholder="t('i18n.components.engine.rules.rule_action_config.reminder_subject')"
            @update:model-value="updateSubject(String($event))"
          />
        </FormField>

        <FormField
          for="rule-reminder-offset"
          :label="t('i18n.components.engine.rules.rule_action_config.due_date_offset_days')"
        >
          <Input
            id="rule-reminder-offset"
            type="number"
            min="0"
            :model-value="modelValue.create_reminder.due_offset"
            @update:model-value="updateDueOffset(String($event))"
          />
        </FormField>
      </div>
    </div>

    <p class="text-xs text-muted-foreground">
      {{ t('i18n.components.engine.rules.rule_action_config.more_actions_will_follow_in_a_later_milestone') }}
    </p>
  </section>
</template>
