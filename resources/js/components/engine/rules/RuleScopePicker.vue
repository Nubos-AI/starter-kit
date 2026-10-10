<script setup lang="ts">
import FilterBuilder from '@/components/engine/filter/FilterBuilder.vue';
import SegmentBar from '@/components/engine/segment/SegmentBar.vue';
import { Separator } from '@/components/ui/separator';
import type { FilterGroupNode } from '@/composables/useFilterTree';
import { useI18n } from '@/composables/useI18n';
import type { RuleScope } from '@/composables/useRules';
import type { SegmentSelection } from '@/composables/useSegments';
import type { FieldDefinition } from '@/types/fields';
import type { RecordObjectType } from '@/types/records';

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
    <section
        :aria-label="t('i18n.components.engine.rules.rule_scope_picker.scope')"
        class="flex flex-col gap-4"
    >
        <SegmentBar
            :object-type="objectType"
            :fields="fields"
            :active-segment-id="modelValue.segment_id"
            :allow-share="false"
            @apply="onSegmentApply"
        />

        <Separator />

        <div class="flex flex-col gap-2">
            <span class="text-sm font-medium">{{
                t(
                    'i18n.components.engine.rules.rule_scope_picker.custom_filter',
                )
            }}</span>
            <FilterBuilder
                :fields="fields"
                :model-value="modelValue.filter_definition ?? undefined"
                @apply="onFilterApply"
                @reset="onFilterReset"
            />
        </div>
    </section>
</template>
