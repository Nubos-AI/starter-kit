<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import type { DashboardRow } from '@/domains/analytics/data';
import { usePageBreadcrumbs } from '@/shared/composables/useBreadcrumbs';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import PageShell from '@/shared/ui/PageShell.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { Input } from '@/shared/ui/primitives/input';
import { Textarea } from '@/shared/ui/primitives/textarea';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { dashboardPayload } from '../../utils/dashboardPayload';

const { t } = useI18n();

const props = defineProps<{
  mode: 'create' | 'edit';
  dashboard: DashboardRow | null;
}>();

const isEdit = computed<boolean>(() => props.mode === 'edit');

const heading = computed<string>(() =>
  isEdit.value
    ? (props.dashboard?.name ?? t('i18n.pages.dashboards.form.dashboard'))
    : t('i18n.pages.dashboards.form.new_dashboard'),
);

usePageBreadcrumbs(() => [{ title: heading.value }]);

const name = ref<string>(props.dashboard?.name ?? '');
const description = ref<string>(props.dashboard?.description ?? '');

const form = useForm<Record<string, string>>({});

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({ name: name.value, description: description.value }),
  backHref: DashboardsController.index.url(),
});

function onSaved(): void {
  markSaved();
  toast.success(t('i18n.pages.dashboards.form.the_dashboard_was_saved'));
}

function onSubmit(): void {
  const dashboard = props.dashboard;

  if (isEdit.value && dashboard !== null) {
    form
      .transform(() => dashboardPayload(name.value, description.value))
      .put(DashboardsController.update.url({ dashboard: dashboard.id }), {
        preserveScroll: true,
        onSuccess: onSaved,
      });

    return;
  }

  form
    .transform(() => dashboardPayload(name.value, description.value))
    .post(DashboardsController.store.url(), { preserveScroll: true, onSuccess: onSaved });
}
</script>

<template>
  <PageShell
    :title="heading"
    :description="
      mode === 'create'
        ? t('i18n.pages.dashboards.form.create_a_new_dashboard_and_give_it_a_name')
        : t('i18n.pages.dashboards.form.edit_this_dashboard_s_name_and_description')
    "
    :head-title="
      mode === 'create'
        ? t('i18n.pages.dashboards.form.new_dashboard')
        : t('i18n.pages.dashboards.form.edit_dashboard', {
            value1: dashboard?.name,
          })
    "
  >
    <form @submit.prevent="onSubmit">
      <Card>
        <CardHeader>
          <CardTitle>{{ t('i18n.pages.dashboards.form.basic_information') }}</CardTitle>
          <CardDescription>
            {{ t('i18n.pages.dashboards.form.the_name_and_description_appear_in_the_list_and') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField
            for="dashboard-name"
            :label="t('i18n.pages.dashboards.form.name')"
            :error="form.errors.name"
            class="sm:max-w-sm"
          >
            <Input
              id="dashboard-name"
              v-model="name"
              :placeholder="t('i18n.pages.dashboards.form.dashboard_name')"
              required
            />
          </FormField>

          <FormField
            for="dashboard-description"
            :label="t('i18n.pages.dashboards.form.description')"
            :error="form.errors.description"
          >
            <Textarea
              id="dashboard-description"
              v-model="description"
              :placeholder="t('i18n.pages.dashboards.form.what_is_this_dashboard_for')"
              rows="2"
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="form.processing" @cancel="requestLeave" />
        </CardContent>
      </Card>
    </form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </PageShell>
</template>
