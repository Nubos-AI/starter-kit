<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import type { StatusEntry } from '@/lib/statusMaps';
import { AGING_THRESHOLD_COLOR, resolveStatus } from '@/lib/statusMaps';
import { formatDurationInDays } from '@/types/aging';
import type { RecordAging } from '@/types/records';

const props = defineProps<{
    aging: RecordAging | null;
}>();

const color = computed<string>(() => props.aging?.color ?? '');

const entry = computed<StatusEntry>(() =>
    resolveStatus(AGING_THRESHOLD_COLOR, color.value),
);

const duration = computed<string>(() =>
    formatDurationInDays(props.aging?.age ?? 0),
);

const title = computed<string>(() => {
    const ruleName = props.aging?.ruleName ?? null;

    return ruleName === null
        ? entry.value.label
        : `${entry.value.label} · ${ruleName}`;
});
</script>

<template>
    <Badge
        v-if="color !== ''"
        :variant="entry.variant"
        :title="title"
        data-aging-badge
        :data-aging-color="color"
    >
        {{ duration }}
    </Badge>
</template>
