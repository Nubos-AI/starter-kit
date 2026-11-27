<script setup lang="ts">
defineProps<{
  title: string;
  description?: string | null;
}>();
</script>

<template>
  <section class="flex flex-col gap-4 not-first:border-t not-first:pt-6" data-card-section>
    <div class="flex items-start justify-between gap-3" data-card-section-header>
      <div class="flex flex-col gap-1">
        <h3 class="text-lg font-bold">{{ title }}</h3>
        <p v-if="description" class="text-sm text-muted-foreground">
          {{ description }}
        </p>
      </div>
      <slot name="actions" />
    </div>
    <slot />
  </section>
</template>
