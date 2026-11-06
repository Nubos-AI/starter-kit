<script setup lang="ts">
import type { Component } from 'vue';
import EmptyState from '@/components/engine/state/EmptyState.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

const props = defineProps<{
    icon: Component;
    title: string;
    description: string;
    emptyTitle: string;
    emptyDescription: string;
}>();
</script>

<template>
    <Card data-record-placeholder-panel>
        <CardHeader>
            <CardTitle>{{ props.title }}</CardTitle>
            <CardDescription>{{ props.description }}</CardDescription>
        </CardHeader>

        <CardContent class="flex flex-col items-center gap-3 py-10 text-center">
            <EmptyState
                :icon="props.icon"
                :title="props.emptyTitle"
                :description="props.emptyDescription"
            />
        </CardContent>
    </Card>
</template>
