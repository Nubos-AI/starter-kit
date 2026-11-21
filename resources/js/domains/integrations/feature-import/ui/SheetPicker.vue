<script setup lang="ts">
import { useI18n } from '@/shared/composables/useI18n';
import FormField from '@/shared/ui/FormField.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';

const { t } = useI18n();

defineProps<{
  sheets: string[];
  modelValue: string | null;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();
</script>

<template>
  <FormField
    v-if="sheets.length > 1"
    for="import-sheet"
    :label="t('i18n.components.engine.import.sheet_picker.select_worksheet')"
  >
    <Select :model-value="modelValue ?? undefined" @update:model-value="emit('update:modelValue', String($event))">
      <SelectTrigger id="import-sheet" class="w-64">
        <SelectValue :placeholder="t('i18n.components.engine.import.sheet_picker.select_worksheet_2')" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="name in sheets" :key="name" :value="name">
          {{ name }}
        </SelectItem>
      </SelectContent>
    </Select>
  </FormField>
</template>
