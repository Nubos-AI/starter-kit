<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type { RuleAction } from '@/composables/useRules';

const { t } = useI18n();

const props = defineProps<{
    modelValue: RuleAction;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: RuleAction];
}>();

const reminderEnabled = computed<boolean>(
    () => props.modelValue.create_reminder !== null,
);

function toggleReminder(enabled: boolean | 'indeterminate'): void {
    emit('update:modelValue', {
        ...props.modelValue,
        create_reminder:
            enabled === true ? { subject: '', due_offset: 0 } : null,
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
    <section
        :aria-label="
            t('i18n.components.engine.rules.rule_action_config.action')
        "
        class="flex flex-col gap-4"
    >
        <div class="flex items-center gap-2">
            <span class="text-sm font-medium">{{
                t('i18n.components.engine.rules.rule_action_config.notify')
            }}</span>
            <Badge variant="secondary">{{
                t(
                    'i18n.components.engine.rules.rule_action_config.always_active',
                )
            }}</Badge>
        </div>

        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <Checkbox
                    id="rule-action-reminder"
                    :model-value="reminderEnabled"
                    @update:model-value="toggleReminder"
                />
                <Label for="rule-action-reminder">
                    {{
                        t(
                            'i18n.components.engine.rules.rule_action_config.create_reminder_task',
                        )
                    }}
                </Label>
            </div>

            <div
                v-if="modelValue.create_reminder !== null"
                class="grid gap-3 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label for="rule-reminder-subject">{{
                        t(
                            'i18n.components.engine.rules.rule_action_config.subject',
                        )
                    }}</Label>
                    <Input
                        id="rule-reminder-subject"
                        :model-value="modelValue.create_reminder.subject"
                        :placeholder="
                            t(
                                'i18n.components.engine.rules.rule_action_config.reminder_subject',
                            )
                        "
                        @update:model-value="updateSubject(String($event))"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="rule-reminder-offset">
                        {{
                            t(
                                'i18n.components.engine.rules.rule_action_config.due_date_offset_days',
                            )
                        }}
                    </Label>
                    <Input
                        id="rule-reminder-offset"
                        type="number"
                        min="0"
                        :model-value="modelValue.create_reminder.due_offset"
                        @update:model-value="updateDueOffset(String($event))"
                    />
                </div>
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            {{
                t(
                    'i18n.components.engine.rules.rule_action_config.more_actions_will_follow_in_a_later_milestone',
                )
            }}
        </p>
    </section>
</template>
