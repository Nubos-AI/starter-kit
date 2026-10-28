<script setup lang="ts">
import { ref } from 'vue';
import CreateButton from '@/components/engine/CreateButton.vue';
import ReminderForm from '@/components/engine/reminders/ReminderForm.vue';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

const CARD_TITLE = t(
    'i18n.components.engine.reminders.record_reminders_panel.reminders',
);

const CARD_DESCRIPTION = t(
    'i18n.components.engine.reminders.record_reminders_panel.create_a_task_or_due_date_for_this_record',
);

const READONLY_HINT = t(
    'i18n.components.engine.reminders.record_reminders_panel.reminders_cannot_be_added_to_a_deleted_record',
);

const props = withDefaults(
    defineProps<{
        recordId: string;
        readonly?: boolean;
    }>(),
    { readonly: false },
);

const emit = defineEmits<{ changed: [] }>();

const formOpen = ref<boolean>(false);

function onSaved(): void {
    emit('changed');
}
</script>

<template>
    <div class="contents">
        <Card data-record-reminders-panel>
            <CardHeader>
                <CardTitle>{{ CARD_TITLE }}</CardTitle>
                <CardDescription>{{ CARD_DESCRIPTION }}</CardDescription>
                <CardAction v-if="!props.readonly">
                    <CreateButton
                        :label="
                            t(
                                'i18n.components.engine.reminders.record_reminders_panel.new_reminder',
                            )
                        "
                        data-reminder-new
                        @create="formOpen = true"
                    />
                </CardAction>
            </CardHeader>

            <CardContent>
                <p
                    v-if="props.readonly"
                    data-record-reminders-readonly
                    class="text-sm text-muted-foreground"
                >
                    {{ READONLY_HINT }}
                </p>
            </CardContent>
        </Card>

        <ReminderForm
            v-model:open="formOpen"
            :record-id="props.recordId"
            :reminder="null"
            @saved="onSaved"
        />
    </div>
</template>
