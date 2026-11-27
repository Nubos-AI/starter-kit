<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
  defineProps<{
    variant?: 'default' | 'inline';
    icon?: Component;
    title?: string;
    description: string;
    headingLevel?: 'h2' | 'h3';
    iconClass?: string;
    titleClass?: string;
  }>(),
  {
    variant: 'default',
    icon: undefined,
    title: undefined,
    headingLevel: 'h2',
    iconClass: 'size-10 text-muted-foreground',
    titleClass: 'text-lg font-semibold',
  },
);
</script>

<template>
  <p v-if="variant === 'inline'" class="text-sm text-muted-foreground" data-empty-state="inline">
    {{ description }}
    <slot />
  </p>
  <div v-else class="contents">
    <component :is="icon" v-if="icon" :class="iconClass" aria-hidden="true" />
    <component :is="headingLevel" v-if="title" :class="titleClass">
      {{ title }}
    </component>
    <p class="text-sm text-muted-foreground">{{ description }}</p>
    <slot />
  </div>
</template>
