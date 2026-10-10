<script setup lang="ts">
import type { ICellRendererParams } from 'ag-grid-community';
import AgingBadge from '@/components/engine/AgingBadge.vue';
import type { RecordPayload } from '@/types/records';

const props = defineProps<{
    params: ICellRendererParams<RecordPayload>;
}>();
</script>

<template>
    <AgingBadge :aging="props.params.data?.aging ?? null" />
</template>
