<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { useI18n } from '@/shared/composables/useI18n';
import { Button } from '@/shared/ui/primitives/button';

defineProps<{
  data?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <InfiniteScroll
    v-if="data !== undefined"
    :data="data"
    manual
    only-next
    preserve-url
    class="flex min-h-0 w-full flex-1 flex-col"
  >
    <slot />
    <template #next="{ fetch, hasMore, loading }">
      <div v-if="hasMore" class="flex justify-center pt-3">
        <Button variant="outline" :disabled="loading" data-load-more @click="fetch">
          {{ t('i18n.components.data_grid.load_more_rows.load_more') }}
        </Button>
      </div>
    </template>
  </InfiniteScroll>
  <div v-else class="flex min-h-0 w-full flex-1 flex-col">
    <slot />
  </div>
</template>
