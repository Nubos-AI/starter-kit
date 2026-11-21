<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { Button } from '@/shared/ui/primitives/button';

const props = withDefaults(
  defineProps<{
    label: string;
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{
  click: [];
}>();

function remove(): void {
  if (props.disabled) {
    return;
  }

  emit('click');
}
</script>

<template>
  <Button
    type="button"
    variant="ghost"
    size="icon"
    :disabled="props.disabled"
    :aria-label="props.label"
    :title="props.label"
    data-remove-button
    @click="remove"
  >
    <Trash2 aria-hidden="true" />
  </Button>
</template>
