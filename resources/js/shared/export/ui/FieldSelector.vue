<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import EmptyState from '@/shared/ui/EmptyState.vue';
import FormCheckbox from '@/shared/ui/FormCheckbox.vue';
import type { ExportFieldOption } from '../data/useExport';

const { t } = useI18n();

const props = defineProps<{
  fields: ExportFieldOption[];
  modelValue: string[];
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string[]];
}>();

const selected = computed<Set<string>>(() => new Set(props.modelValue));

function toggle(key: string): void {
  const next = new Set(props.modelValue);

  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }

  emit('update:modelValue', [...next]);
}
</script>

<template>
  <fieldset class="flex flex-col gap-2">
    <legend class="mb-1 text-sm font-medium">
      {{ t('i18n.components.engine.export.field_selector.fields_to_export') }}
    </legend>

    <EmptyState
      v-if="fields.length === 0"
      variant="inline"
      :description="
        t('i18n.components.engine.export.field_selector.there_are_no_exportable_fields_for_this_object_type')
      "
    />

    <FormCheckbox
      v-for="field in fields"
      :key="field.key"
      :id="`export-field-${field.key}`"
      :model-value="selected.has(field.key)"
      :label="field.label"
      @update:model-value="toggle(field.key)"
    />
  </fieldset>
</template>
