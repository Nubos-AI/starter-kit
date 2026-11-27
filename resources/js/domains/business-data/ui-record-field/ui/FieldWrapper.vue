<script setup lang="ts">
import FormField from '@/shared/ui/FormField.vue';

const props = defineProps<{
  fieldId: string;
  label: string;
  required?: boolean;
  error?: string;
  help?: string | null;
}>();
</script>

<template>
  <FormField
    v-slot="{ describedBy }"
    :for="props.fieldId"
    :label="props.label"
    :required="props.required"
    :error="props.error"
    :help="props.help"
  >
    <slot :described-by="describedBy" />
  </FormField>
</template>
