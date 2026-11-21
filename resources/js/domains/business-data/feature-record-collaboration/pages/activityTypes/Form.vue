<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ActivityTypesController from '@/actions/App/Http/Controllers/Engine/ActivityTypesController';
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

interface ActivityTypePayload {
  id: string;
  name: string;
}

const props = defineProps<{
  mode: 'create' | 'edit';
  activityType: ActivityTypePayload | null;
}>();

const heading = computed<string>(() =>
  props.mode === 'create'
    ? t('i18n.pages.activity_types.form.create_activity_type')
    : (props.activityType?.name ?? t('i18n.pages.activity_types.form.activity_type')),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const formAction = computed(() =>
  props.activityType === null
    ? ActivityTypesController.store.form()
    : ActivityTypesController.update.form({
        activityType: props.activityType.id,
      }),
);

const name = ref<string>(props.activityType?.name ?? '');

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({ name: name.value }),
  backHref: ActivityTypesController.index.url(),
});
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.activity_types.form.create_a_category_to_select_when_adding_an_activity')
        : t('i18n.pages.activity_types.form.edit_this_activity_type')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.activity_types.form.create_activity_type')
        : t('i18n.pages.activity_types.form.edit', {
            value1: activityType?.name,
          })
    "
  >
    <Card>
      <CardHeader>
        <CardTitle>{{ t('i18n.pages.activity_types.form.details') }}</CardTitle>
        <CardDescription>
          {{ t('i18n.pages.activity_types.form.record_activities_use_these_categories') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Form v-bind="formAction" :on-success="markSaved" class="flex flex-col gap-4" v-slot="{ errors, processing }">
          <FormField
            for="activity-type-name"
            :label="t('i18n.pages.activity_types.form.name')"
            :error="errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="activity-type-name"
              v-model="name"
              name="name"
              :placeholder="t('i18n.pages.activity_types.form.e_g_phone_call_meeting_customer_visit')"
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
