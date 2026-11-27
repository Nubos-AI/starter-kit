<script setup lang="ts">
import { computed } from 'vue';
import { numberInputFormat } from '@/shared/lib/localizedNumber';
import type { FieldDefinition } from '@/shared/types/fields';
import { NumberInput } from '@/shared/ui/primitives/number-input';

const props = defineProps<{
  field: FieldDefinition;
  error?: string;
  describedBy?: string;
}>();

const model = defineModel<string | number | null>();

const format = computed<Intl.NumberFormatOptions>(() => numberInputFormat(props.field.field_type));
</script>

<template>
  <NumberInput
    :id="field.key"
    :model-value="model ?? null"
    :format="format"
    :required="field.is_required"
    :placeholder="field.placeholder ?? undefined"
    :aria-invalid="error ? true : undefined"
    :aria-describedby="describedBy"
    @update:model-value="model = $event"
  />
</template>
