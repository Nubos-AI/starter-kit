<script setup lang="ts">
import PageShell from '@/shared/ui/PageShell.vue';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';

const props = defineProps<{
  title: string;
  description?: string;
}>();
</script>

<template>
  <PageShell :title="props.title" :description="props.description" fill>
    <template #before>
      <UiExtensionPoint name="lists.before" />
    </template>
    <template #actions>
      <slot name="actions" />
    </template>
    <slot />
    <UiExtensionPoint name="lists.after" />
  </PageShell>
</template>
