<script setup lang="ts">
import { computed } from 'vue';
import { formatDurationInDays } from '@/shared/business-data/types/aging';
import type { RecordAging } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import type { StatusEntry } from '@/shared/lib/statusMaps';
import { AGING_THRESHOLD_COLOR, resolveStatus } from '@/shared/lib/statusMaps';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';

const props = defineProps<{
  aging: RecordAging | null;
}>();

const { t } = useI18n();

const color = computed<string>(() => props.aging?.color ?? '');

const entry = computed<StatusEntry>(() => resolveStatus(AGING_THRESHOLD_COLOR, color.value));

const duration = computed<string>(() => formatDurationInDays(props.aging?.age ?? 0));

const title = computed<string>(() => {
  const ruleName = props.aging?.ruleName ?? null;

  const label = t(entry.value.label);

  return ruleName === null ? label : `${label} · ${ruleName}`;
});
</script>

<template>
  <StatusBadge
    v-if="color !== ''"
    :map="AGING_THRESHOLD_COLOR"
    :status="color"
    :label="duration"
    :title="title"
    data-aging-badge
    :data-aging-color="color"
  />
</template>
