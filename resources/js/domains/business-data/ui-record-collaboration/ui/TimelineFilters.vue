<script setup lang="ts">
import type { TimelineActorType, TimelineFilterState } from '@/domains/business-data/data';
import { useI18n } from '@/shared/composables/useI18n';
import type { SelectOption } from '@/shared/types/ui';
import FormField from '@/shared/ui/FormField.vue';
import { Combobox } from '@/shared/ui/primitives/combobox';
import { Input } from '@/shared/ui/primitives/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/shared/ui/primitives/select';

const { t } = useI18n();

const props = defineProps<{
  filterState: TimelineFilterState;
  actorOptions: SelectOption[];
}>();

const emit = defineEmits<{
  'update:filterState': [state: TimelineFilterState];
}>();

const ALL = '__all__';

const actorTypeLabels: Record<TimelineActorType, string> = {
  user: t('i18n.components.engine.timeline.timeline_filters.user'),
  automation: t('i18n.components.engine.timeline.timeline_filters.automation'),
  system: t('i18n.components.engine.timeline.timeline_filters.system'),
};

function patch(partial: Partial<TimelineFilterState>): void {
  emit('update:filterState', { ...props.filterState, ...partial });
}

function onActorType(value: string): void {
  patch({
    actorType: value === ALL || !(value in actorTypeLabels) ? null : (value as TimelineActorType),
  });
}

function onActor(value: string): void {
  patch({ actorId: value === ALL ? null : value });
}

function onDate(key: 'occurredFrom' | 'occurredTo', value: string | number): void {
  patch({ [key]: value === '' ? null : String(value) });
}
</script>

<template>
  <div class="flex flex-wrap items-end gap-3">
    <FormField
      data-timeline-filter
      for="timeline-filter-from"
      :label="t('i18n.components.engine.timeline.timeline_filters.from')"
      class="w-44"
    >
      <Input
        id="timeline-filter-from"
        type="date"
        class="w-full"
        :model-value="props.filterState.occurredFrom ?? ''"
        @update:model-value="onDate('occurredFrom', $event)"
      />
    </FormField>

    <FormField
      data-timeline-filter
      for="timeline-filter-to"
      :label="t('i18n.components.engine.timeline.timeline_filters.to')"
      class="w-44"
    >
      <Input
        id="timeline-filter-to"
        type="date"
        class="w-full"
        :model-value="props.filterState.occurredTo ?? ''"
        @update:model-value="onDate('occurredTo', $event)"
      />
    </FormField>

    <FormField
      data-timeline-filter
      for="timeline-filter-actor-type"
      :label="t('i18n.components.engine.timeline.timeline_filters.actor_type')"
      class="w-44"
    >
      <Select :model-value="props.filterState.actorType ?? ALL" @update:model-value="onActorType(String($event))">
        <SelectTrigger id="timeline-filter-actor-type" class="w-full">
          <SelectValue :placeholder="t('i18n.components.engine.timeline.timeline_filters.all')" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem :value="ALL">{{ t('i18n.components.engine.timeline.timeline_filters.all') }}</SelectItem>
          <SelectItem v-for="(label, type) in actorTypeLabels" :key="type" :value="type">
            {{ label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>

    <FormField
      v-if="props.actorOptions.length > 0"
      data-timeline-filter
      for="timeline-filter-actor"
      :label="t('i18n.components.engine.timeline.timeline_filters.actor')"
      class="w-44"
    >
      <Combobox
        id="timeline-filter-actor"
        :aria-label="t('i18n.components.engine.timeline.timeline_filters.actor')"
        :placeholder="t('i18n.components.engine.timeline.timeline_filters.all')"
        :options="[
          {
            value: ALL,
            label: t('i18n.components.engine.timeline.timeline_filters.all'),
          },
          ...props.actorOptions,
        ]"
        :searchable="props.actorOptions.length > 8"
        :model-value="props.filterState.actorId ?? ALL"
        @update:model-value="onActor(String($event))"
      />
    </FormField>
  </div>
</template>
