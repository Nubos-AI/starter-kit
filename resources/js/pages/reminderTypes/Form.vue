<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ReminderTypesController from '@/actions/App/Http/Controllers/Engine/ReminderTypesController';
import FormActions from '@/components/FormActions.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { usePageBreadcrumbs } from '@/composables/useBreadcrumbs';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

const { t } = useI18n();

interface ReminderTypePayload {
    id: string;
    name: string;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    reminderType: ReminderTypePayload | null;
}>();

const heading = computed<string>(() =>
    props.mode === 'create'
        ? t('i18n.pages.reminder_types.form.create_reminder_type')
        : (props.reminderType?.name ??
          t('i18n.pages.reminder_types.form.reminder_type')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
    props.mode === 'create'
        ? ReminderTypesController.store.form()
        : ReminderTypesController.update.form({
              reminderType: props.reminderType!.id,
          }),
);

const name = ref<string>(props.reminderType?.name ?? '');

const {
    isDirty,
    promptOpen,
    requestLeave,
    confirmLeave,
    cancelLeave,
    markSaved,
} = useUnsavedChanges({
    values: () => ({ name: name.value }),
    backHref: ReminderTypesController.index.url(),
});
</script>

<template>
    <Head
        :title="
            mode === 'create'
                ? t('i18n.pages.reminder_types.form.create_reminder_type')
                : t('i18n.pages.reminder_types.form.edit', {
                      value1: reminderType?.name,
                  })
        "
    />

    <div class="flex flex-col gap-6 p-3">
        <Heading
            variant="small"
            :title="heading"
            :description="
                mode === 'create'
                    ? t(
                          'i18n.pages.reminder_types.form.create_a_category_to_select_when_adding_a_reminder',
                      )
                    : t(
                          'i18n.pages.reminder_types.form.edit_this_reminder_type',
                      )
            "
        />

        <Card>
            <CardHeader>
                <CardTitle>{{
                    t('i18n.pages.reminder_types.form.details')
                }}</CardTitle>
                <CardDescription>
                    {{
                        t(
                            'i18n.pages.reminder_types.form.reminders_and_the_create_reminder_automation_use_these_categories',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    v-bind="formAction"
                    :on-success="markSaved"
                    class="flex flex-col gap-4"
                    v-slot="{ errors, processing }"
                >
                    <div class="grid gap-2 sm:max-w-sm">
                        <Label for="reminder-type-name">{{
                            t('i18n.pages.reminder_types.form.name')
                        }}</Label>
                        <Input
                            id="reminder-type-name"
                            v-model="name"
                            name="name"
                            :placeholder="
                                t(
                                    'i18n.pages.reminder_types.form.e_g_call_email_follow_up',
                                )
                            "
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <FormActions
                        :dirty="isDirty"
                        :processing="processing"
                        @cancel="requestLeave"
                    />
                </Form>
            </CardContent>
        </Card>

        <UnsavedChangesDialog
            :open="promptOpen"
            @confirm="confirmLeave"
            @cancel="cancelLeave"
        />
    </div>
</template>
