<script setup lang="ts">
import type { ICellRendererParams } from 'ag-grid-community';
import type { RecordPayload } from '@/shared/business-data/types/records';
import AgingBadge from './AgingBadge.vue';

const props = defineProps<{
  params: ICellRendererParams<RecordPayload>;
}>();
</script>

<template>
  <AgingBadge :aging="props.params.data?.aging ?? null" />
</template>
