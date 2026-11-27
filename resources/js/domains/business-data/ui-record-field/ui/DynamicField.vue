<script setup lang="ts">
import { computed } from 'vue';
import { fieldCurrency } from '@/domains/business-data/util-record-format';
import type { FieldDefinition } from '@/shared/types/fields';
import { useFieldTypeRegistry } from '../composables/useFieldTypeRegistry';
import FieldWrapper from './FieldWrapper.vue';

const props = withDefaults(
  defineProps<{
    field: FieldDefinition;
    label?: string | null;
    error?: string;
    values?: Record<string, unknown>;
  }>(),
  {
    label: null,
    error: undefined,
    values: () => ({}),
  },
);

const value = defineModel<unknown>();

const { resolveWidget } = useFieldTypeRegistry();

const widget = computed(() => resolveWidget(props.field.field_type));

const widgetProps = computed<Record<string, unknown>>(() =>
  widget.value?.inputType ? { inputType: widget.value.inputType } : {},
);

const displayedLabel = computed<string>(() => {
  const label = props.label ?? props.field.label;
  const currency = props.field.field_type === 'money' ? fieldCurrency(props.field, props.values) : null;

  return currency ? `${label} (${currency})` : label;
});
</script>

<template>
  <FieldWrapper
    v-slot="{ describedBy }"
    :field-id="field.key"
    :label="displayedLabel"
    :required="field.is_required"
    :help="field.description"
    :error="error"
  >
    <component
      :is="widget?.component"
      v-model="value"
      :field="field"
      v-bind="widgetProps"
      :error="error"
      :described-by="describedBy"
    />
  </FieldWrapper>
</template>
