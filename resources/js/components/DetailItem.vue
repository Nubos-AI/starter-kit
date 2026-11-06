<script setup lang="ts">
import { computed } from 'vue';

type DetailItemVariant = 'stacked' | 'inline' | 'metric';

interface DetailItemClasses {
    root: string;
    label: string;
    value: string;
}

const props = withDefaults(
    defineProps<{
        label: string;
        variant?: DetailItemVariant;
    }>(),
    { variant: 'stacked' },
);

const variantClasses: Record<DetailItemVariant, DetailItemClasses> = {
    stacked: {
        root: 'flex flex-col gap-1',
        label: 'text-xs text-muted-foreground',
        value: 'text-sm',
    },
    inline: {
        root: 'flex flex-col gap-0.5 text-sm sm:flex-row sm:gap-3',
        label: 'text-muted-foreground sm:w-44 sm:shrink-0',
        value: 'min-w-0',
    },
    metric: {
        root: 'flex flex-col gap-1 rounded-md border p-3',
        label: 'text-xs text-muted-foreground',
        value: 'text-2xl font-bold tabular-nums',
    },
};

const classes = computed<DetailItemClasses>(
    () => variantClasses[props.variant],
);
</script>

<template>
    <div :class="classes.root" :data-detail-item="props.variant">
        <dt :class="classes.label" data-detail-item-label>
            {{ props.label }}
        </dt>
        <dd :class="classes.value" data-detail-item-value>
            <slot />
        </dd>
    </div>
</template>
