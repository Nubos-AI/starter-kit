<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ObjectTypeMergeCard from '@/components/engine/objectType/ObjectTypeMergeCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useObjectTypeSection } from '@/composables/useObjectTypeSection';
import type { MergeRuleRow } from '@/types/merge';
import type { ObjectTypeSummary } from '@/types/objectTypes';

const { t } = useI18n();

const props = defineProps<{
    objectType: ObjectTypeSummary;
    mergeRules: MergeRuleRow[];
}>();

const { canUpdate } = useObjectTypeSection(props.objectType, 'mergeRules');
</script>

<template>
    <Head
        :title="
            t('i18n.pages.object_types.edit.merge_rules.merge_rules', {
                value1: objectType.name,
            })
        "
    />

    <ObjectTypeMergeCard
        :object-type-slug="objectType.slug"
        :rules="mergeRules"
        :can-create="canUpdate"
    />
</template>
