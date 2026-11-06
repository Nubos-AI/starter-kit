<script setup lang="ts">
import { LayoutDashboard } from '@lucide/vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';

withDefaults(
    defineProps<{
        title: string;
        description: string;
        headingLevel?: 'h2' | 'h3';
    }>(),
    { headingLevel: 'h2' },
);
</script>

<template>
    <div
        class="flex flex-col items-center gap-2 rounded-xl border border-dashed px-4 py-10 text-center"
    >
        <EmptyState
            :icon="LayoutDashboard"
            :title="title"
            :description="description"
            :heading-level="headingLevel"
        />
        <slot />
    </div>
</template>
