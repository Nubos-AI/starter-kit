<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/shared/lib/utils';
import { Skeleton } from '@/shared/ui/primitives/skeleton';

type SkeletonListSize = 'sm' | 'md' | 'lg';

const props = withDefaults(
  defineProps<{
    count?: number;
    size?: SkeletonListSize;
    class?: HTMLAttributes['class'];
    itemClass?: HTMLAttributes['class'];
  }>(),
  { count: 3, size: 'md', class: undefined, itemClass: undefined },
);

const sizeClasses: Record<SkeletonListSize, string> = {
  sm: 'h-6',
  md: 'h-10',
  lg: 'h-24',
};

const listClass = computed<string>(() => cn('grid gap-2', props.class));

const itemClasses = computed<string>(() => cn('w-full', sizeClasses[props.size], props.itemClass));
</script>

<template>
  <div :class="listClass" aria-busy="true" :data-skeleton-size="props.size" data-skeleton-list>
    <Skeleton v-for="placeholder in props.count" :key="placeholder" :class="itemClasses" aria-hidden="true" />
  </div>
</template>
