<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ReminderTypesController from '@/actions/App/Http/Controllers/Engine/ReminderTypesController';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

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
    : (props.reminderType?.name ?? t('i18n.pages.reminder_types.form.reminder_type')),
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

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({ name: name.value }),
  backHref: ReminderTypesController.index.url(),
});
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.reminder_types.form.create_a_category_to_select_when_adding_a_reminder')
        : t('i18n.pages.reminder_types.form.edit_this_reminder_type')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.reminder_types.form.create_reminder_type')
        : t('i18n.pages.reminder_types.form.edit', {
            value1: reminderType?.name,
          })
    "
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.reminder_types.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.reminder_types.form.reminders_use_these_categories') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form v-bind="formAction" :on-success="markSaved" class="flex flex-col gap-4" v-slot="{ errors, processing }">
          <FormField
            for="reminder-type-name"
            :label="t('i18n.pages.reminder_types.form.name')"
            :error="errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="reminder-type-name"
              v-model="name"
              name="name"
              :placeholder="t('i18n.pages.reminder_types.form.e_g_call_email_follow_up')"
              required
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </Form>
      </CardContent>
    </Card>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
