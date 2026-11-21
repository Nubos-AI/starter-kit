<script setup lang="ts">
import type { SourceLink } from '@/shared/business-data/types/links';
import TableLink from '@/shared/ui/data-grid/TableLink.vue';

defineProps<{ links: SourceLink[] }>();
</script>

<template>
  <span v-if="links.length > 0" class="inline-flex flex-wrap items-center gap-x-3 gap-y-1" data-source-links>
    <TableLink v-for="link in links" :key="`${link.key}:${link.id}`" :href="link.href" :data-source-link="link.key">
      {{ link.label }}
    </TableLink>
  </span>
</template>
