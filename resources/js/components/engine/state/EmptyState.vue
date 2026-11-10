<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        description: string;
        headingLevel?: 'h2' | 'h3';
        iconClass?: string;
        titleClass?: string;
    }>(),
    {
        headingLevel: 'h2',
        iconClass: 'size-10 text-muted-foreground',
        titleClass: 'text-lg font-semibold',
    },
);
</script>

<template>
    <div class="contents">
        <component :is="icon" :class="iconClass" aria-hidden="true" />
        <component :is="headingLevel" :class="titleClass">
            {{ title }}
        </component>
        <p class="text-sm text-muted-foreground">{{ description }}</p>
        <slot />
    </div>
</template>
