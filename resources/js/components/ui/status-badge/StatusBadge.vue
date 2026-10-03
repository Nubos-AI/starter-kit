<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { StatusMap } from '@/lib/statusMaps';
import { resolveStatus } from '@/lib/statusMaps';

const props = defineProps<{
    map: StatusMap;
    status: string;
}>();

const entry = computed(() => resolveStatus(props.map, props.status));
</script>

<template>
    <Badge :variant="entry.variant">{{ entry.label }}</Badge>
</template>
