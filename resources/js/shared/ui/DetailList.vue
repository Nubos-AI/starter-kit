<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/shared/lib/utils';

type DetailListColumns = 1 | 2 | 3 | 4;

const props = withDefaults(
  defineProps<{
    columns?: DetailListColumns;
    compact?: boolean;
  }>(),
  { columns: 1, compact: false },
);

const columnClasses: Record<DetailListColumns, string> = {
  1: '',
  2: 'sm:grid-cols-2',
  3: 'sm:grid-cols-3',
  4: 'sm:grid-cols-2 lg:grid-cols-4',
};

const listClass = computed<string>(() =>
  cn('grid', props.compact ? 'gap-2 text-sm' : 'gap-4', columnClasses[props.columns]),
);
</script>

<template>
  <dl :class="listClass" data-detail-list>
    <slot />
  </dl>
</template>
