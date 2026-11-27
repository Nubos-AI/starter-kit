<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ObjectTypeSummary } from '@/domains/business-data/data';
import { useObjectTypeSection } from '@/domains/business-data/ui-object-types';
import type { AgingRuleRow } from '@/shared/business-data/types/aging';
import { useI18n } from '@/shared/composables/useI18n';
import type { FieldDefinition } from '@/shared/types/fields';
import type { SelectOption } from '@/shared/types/ui';
import ObjectTypeAgingCard from '../../../ui/ObjectTypeAgingCard.vue';

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
        value1: objectType.label,
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
