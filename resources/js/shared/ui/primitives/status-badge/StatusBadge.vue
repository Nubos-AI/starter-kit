<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/shared/ui/primitives/badge';
import { useI18n } from '@/shared/composables/useI18n';
import type { StatusMap } from '@/shared/lib/statusMaps';
import { resolveStatus } from '@/shared/lib/statusMaps';

const props = withDefaults(
    defineProps<{
        map: StatusMap;
        status: string;
        label?: string;
    }>(),
    { label: undefined },
);

const { t } = useI18n();

const entry = computed(() => resolveStatus(props.map, props.status));

const text = computed<string>(() => props.label ?? t(entry.value.label));
</script>

<template>
    <Badge :variant="entry.variant">{{ text }}</Badge>
</template>
