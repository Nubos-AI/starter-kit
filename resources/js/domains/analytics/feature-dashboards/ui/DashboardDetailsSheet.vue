<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DashboardsController from '@/actions/App/Http/Controllers/Dashboards/DashboardsController';
import type { DashboardRow } from '@/domains/analytics/data';
import { useGuardedSheet } from '@/shared/composables/useGuardedSheet';
import { useI18n } from '@/shared/composables/useI18n';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Input } from '@/shared/ui/primitives/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/shared/ui/primitives/sheet';
import { Textarea } from '@/shared/ui/primitives/textarea';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';
import { dashboardPayload } from '../utils/dashboardPayload';

const { t } = useI18n();

const SHEET_TITLE = t('i18n.components.dashboards.dashboard_details_sheet.basic_information');

const SHEET_DESCRIPTION = t(
  'i18n.components.dashboards.dashboard_details_sheet.the_name_and_description_appear_in_the_list_and',
);

const SAVED_MESSAGE = t('i18n.components.dashboards.dashboard_details_sheet.the_dashboard_was_saved');

const props = defineProps<{
  dashboard: DashboardRow;
  open: boolean;
}>();

const emit = defineEmits<{
  close: [];
}>();

const name = ref<string>(props.dashboard.name);
const description = ref<string>(props.dashboard.description ?? '');

const form = useForm<Record<string, string>>({});

const { isDirty, promptOpen, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => ({ name: name.value, description: description.value }),
  backHref: DashboardsController.show.url({
    dashboard: props.dashboard.id,
  }),
});

function seed(): void {
  name.value = props.dashboard.name;
  description.value = props.dashboard.description ?? '';
  markSaved();
}

const { closeRequested, onCancel, onConfirmLeave, onCancelLeave, onOpenChange } = useGuardedSheet({
  isDirty,
  confirmLeave,
  cancelLeave,
  close: () => {
    seed();
    emit('close');
  },
});

watch(
  () => props.open,
  (open) => {
    if (open) {
      seed();
    }
  },
);

function onSubmit(): void {
  form
    .transform(() => dashboardPayload(name.value, description.value))
    .put(DashboardsController.update.url({ dashboard: props.dashboard.id }), {
      preserveScroll: true,
      onSuccess: (): void => {
        markSaved();
        toast.success(SAVED_MESSAGE);
        emit('close');
      },
    });
}
</script>

<template>
  <div>
    <Sheet :open="props.open" @update:open="onOpenChange">
      <SheetContent side="right" class="w-full sm:max-w-md">
        <div data-dashboard-details class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-4 pb-4">
          <SheetHeader class="px-0">
            <SheetTitle>{{ SHEET_TITLE }}</SheetTitle>
            <SheetDescription>
              {{ SHEET_DESCRIPTION }}
            </SheetDescription>
          </SheetHeader>

          <FormField
            for="dashboard-name"
            :label="t('i18n.components.dashboards.dashboard_details_sheet.name')"
            :error="form.errors.name"
          >
            <Input
              id="dashboard-name"
              v-model="name"
              :placeholder="t('i18n.components.dashboards.dashboard_details_sheet.dashboard_name')"
              required
            />
          </FormField>

          <FormField
            for="dashboard-description"
            :label="t('i18n.components.dashboards.dashboard_details_sheet.description')"
            :error="form.errors.description"
          >
            <Textarea
              id="dashboard-description"
              v-model="description"
              :placeholder="t('i18n.components.dashboards.dashboard_details_sheet.what_is_this_dashboard_for')"
              rows="2"
            />
          </FormField>

          <FormActions
            type="button"
            :dirty="isDirty"
            :processing="form.processing"
            @cancel="onCancel"
            @save="onSubmit"
          />
        </div>
      </SheetContent>
    </Sheet>

    <UnsavedChangesDialog :open="promptOpen || closeRequested" @confirm="onConfirmLeave" @cancel="onCancelLeave" />
  </div>
</template>
