<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/shared/ui/Heading.vue';

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    headTitle?: string;
    fill?: boolean;
  }>(),
  {
    description: undefined,
    headTitle: undefined,
    fill: false,
  },
);
</script>

<template>
  <div
    :class="props.fill ? 'relative flex h-full min-h-0 flex-1 flex-col gap-6 p-3' : 'flex flex-col gap-6 p-3'"
    data-page-shell
    :data-page-shell-fill="props.fill ? '' : undefined"
  >
    <Head :title="props.headTitle ?? props.title" />
    <slot name="before" />
    <div class="flex flex-wrap items-start justify-between gap-4" data-page-header>
      <Heading variant="small" class="min-w-0 break-words" :title="props.title" :description="props.description" />
      <slot name="actions" />
    </div>
    <slot />
  </div>
</template>
