<script setup lang="ts">
import { computed } from 'vue';

type SubsectionHeadingLevel = 3 | 4 | 5;

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string | null;
    level?: SubsectionHeadingLevel;
  }>(),
  { description: null, level: 4 },
);

const headingTag = computed<string>(() => `h${props.level}`);
</script>

<template>
  <div class="flex items-start justify-between gap-3" data-subsection-heading>
    <div class="flex min-w-0 flex-col gap-1">
      <component :is="headingTag" class="text-sm font-medium">{{ props.title }}</component>
      <p v-if="props.description" class="text-xs text-muted-foreground" data-subsection-heading-description>
        {{ props.description }}
      </p>
    </div>
    <slot name="actions" />
  </div>
</template>
