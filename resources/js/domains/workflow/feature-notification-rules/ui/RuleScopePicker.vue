<script setup lang="ts">
import type { SegmentSelection } from '@/shared/business-data/data/useSegments';
import type { RecordObjectType } from '@/shared/business-data/types/records';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import type { FilterGroupNode } from '@/shared/types/filters';
import { Separator } from '@/shared/ui/primitives/separator';
import FilterBuilder from '../../../../shared/filters/ui/FilterBuilder.vue';
import SegmentBar from '../../../../shared/segments/ui/SegmentBar.vue';
import type { RuleScope } from '../data/useRules';

const { t } = useI18n();

defineProps<{
  objectType: RecordObjectType;
  fields: FieldDefinition[];
  modelValue: RuleScope;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: RuleScope];
}>();

function onSegmentApply(selection: SegmentSelection): void {
  emit('update:modelValue', {
    segment_id: selection.segment.id,
    filter_definition: null,
  });
}

function onFilterApply(tree: FilterGroupNode): void {
  emit('update:modelValue', {
    segment_id: null,
    filter_definition: tree,
  });
}

function onFilterReset(): void {
  emit('update:modelValue', { segment_id: null, filter_definition: null });
}
</script>

<template>
  <section :aria-label="t('i18n.components.engine.rules.rule_scope_picker.scope')" class="flex flex-col gap-4">
    <SegmentBar
      :object-type="objectType"
      :fields="fields"
      :active-segment-id="modelValue.segment_id"
      :allow-share="false"
      @apply="onSegmentApply"
    />

    <Separator />

    <div class="flex flex-col gap-2">
      <span class="text-sm font-medium">{{ t('i18n.components.engine.rules.rule_scope_picker.custom_filter') }}</span>
      <FilterBuilder
        :fields="fields"
        :model-value="modelValue.filter_definition ?? undefined"
        @apply="onFilterApply"
        @reset="onFilterReset"
      />
    </div>
  </section>
</template>
