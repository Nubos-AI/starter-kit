<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/shared/lib/utils';

type DetailItemVariant = 'stacked' | 'inline' | 'metric' | 'summary';

interface DetailItemClasses {
  root: string;
  term: string;
  value: string;
}

const props = withDefaults(
  defineProps<{
    label?: string;
    variant?: DetailItemVariant;
    emphasized?: boolean;
  }>(),
  { label: undefined, variant: 'stacked', emphasized: false },
);

const variantClasses: Record<DetailItemVariant, DetailItemClasses> = {
  stacked: {
    root: 'flex flex-col gap-1',
    term: 'text-xs',
    value: 'text-sm',
  },
  inline: {
    root: 'flex flex-col gap-0.5 text-sm sm:flex-row sm:gap-3',
    term: 'sm:w-44 sm:shrink-0',
    value: 'min-w-0',
  },
  metric: {
    root: 'flex flex-col gap-1 rounded-md border p-3',
    term: 'text-xs',
    value: 'text-2xl font-bold tabular-nums',
  },
  summary: {
    root: 'flex items-baseline justify-between gap-3',
    term: 'min-w-0',
    value: 'text-right tabular-nums',
  },
};

const classes = computed<DetailItemClasses>(() => {
  const base = variantClasses[props.variant];

  return {
    root: cn(base.root, props.emphasized && 'font-bold'),
    term: cn(base.term, props.emphasized ? 'text-foreground' : 'text-muted-foreground'),
    value: base.value,
  };
});
</script>

<template>
  <div
    :class="classes.root"
    :data-detail-item="props.variant"
    :data-detail-item-emphasized="props.emphasized ? '' : undefined"
  >
    <dt :class="classes.term" data-detail-item-label>
      <slot name="label">{{ props.label }}</slot>
    </dt>
    <dd :class="classes.value" data-detail-item-value>
      <slot />
    </dd>
  </div>
</template>
