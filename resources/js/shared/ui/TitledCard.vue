<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/shared/lib/utils';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/shared/ui/primitives/card';

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    contentClass?: HTMLAttributes['class'];
  }>(),
  {
    description: undefined,
    contentClass: undefined,
  },
);
</script>

<template>
  <Card>
    <CardHeader class="flex flex-row items-start justify-between gap-4">
      <div class="flex min-w-0 flex-col gap-1.5">
        <CardTitle>{{ props.title }}</CardTitle>
        <CardDescription v-if="props.description !== undefined">{{ props.description }}</CardDescription>
      </div>
      <slot name="actions" />
    </CardHeader>
    <CardContent :class="cn('flex flex-col gap-4', props.contentClass)">
      <slot />
    </CardContent>
    <CardFooter v-if="$slots.footer !== undefined">
      <slot name="footer" />
    </CardFooter>
  </Card>
</template>
