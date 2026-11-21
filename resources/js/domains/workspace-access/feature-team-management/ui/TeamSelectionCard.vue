<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import TeamsController from '@/actions/App/Http/Controllers/Teams/TeamsController';
import { useUnsavedChanges } from '@/shared/composables/useUnsavedChanges';
import type { SelectOption } from '@/shared/types/ui';
import FormActions from '@/shared/ui/FormActions.vue';
import FormField from '@/shared/ui/FormField.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/primitives/card';
import { MultiSelect } from '@/shared/ui/primitives/multi-select';
import UnsavedChangesDialog from '@/shared/ui/UnsavedChangesDialog.vue';

const props = defineProps<{
  teamId: string;
  field: 'member_ids' | 'role_ids' | 'denied_permission_ids';
  selected: string[];
  options: SelectOption[];
  title: string;
  description: string;
  label: string;
  placeholder: string;
  emptyLabel: string;
}>();

const selection = ref<string[]>([...props.selected]);

const { isDirty, promptOpen, requestLeave, confirmLeave, cancelLeave, markSaved } = useUnsavedChanges({
  values: () => [...selection.value].sort(),
  backHref: TeamsController.index.url(),
});

function onlyThisSection(): Record<string, FormDataConvertible> {
  return { [props.field]: selection.value };
}
</script>

<template>
  <div class="flex flex-col gap-6" data-team-selection-card>
    <Form
      v-bind="TeamsController.update.form({ team: props.teamId })"
      :transform="onlyThisSection"
      :options="{ preserveScroll: true }"
      :on-success="markSaved"
      v-slot="{ errors, processing }"
    >
      <Card>
        <CardHeader>
          <CardTitle>{{ props.title }}</CardTitle>
          <CardDescription>{{ props.description }}</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
          <FormField :for="props.field" :label="props.label" :error="errors[props.field]" class="sm:max-w-sm">
            <MultiSelect
              :id="props.field"
              v-model="selection"
              :options="props.options"
              :placeholder="props.placeholder"
              :empty-label="props.emptyLabel"
              :aria-label="props.label"
            />
          </FormField>

          <FormActions :dirty="isDirty" :processing="processing" @cancel="requestLeave" />
        </CardContent>
      </Card>
    </Form>

    <UnsavedChangesDialog :open="promptOpen" @confirm="confirmLeave" @cancel="cancelLeave" />
  </div>
</template>
