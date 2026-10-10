<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ObjectTypeAgingCard from '@/components/engine/objectType/ObjectTypeAgingCard.vue';
import { useI18n } from '@/composables/useI18n';
import { useObjectTypeSection } from '@/composables/useObjectTypeSection';
import type { AgingRuleRow } from '@/types/aging';
import type { FieldDefinition } from '@/types/fields';
import type { ObjectTypeSummary } from '@/types/objectTypes';
import type { SelectOption } from '@/types/ui';

const { t } = useI18n();

const props = defineProps<{
    objectType: ObjectTypeSummary;
    agingRules: AgingRuleRow[];
    agingClockFieldOptions: SelectOption[];
    agingConditionFields: FieldDefinition[];
    agingActiveRuleLimit: number;
}>();

const { canUpdate } = useObjectTypeSection(props.objectType, 'agingRules');
</script>

<template>
    <Head
        :title="
            t('i18n.pages.object_types.edit.aging_rules.aging_rules', {
                value1: objectType.name,
            })
        "
    />

    <ObjectTypeAgingCard
        :object-type-slug="objectType.slug"
        :rules="agingRules"
        :clock-field-options="agingClockFieldOptions"
        :condition-fields="agingConditionFields"
        :active-rule-limit="agingActiveRuleLimit"
        :can-create="canUpdate"
    />
</template>
