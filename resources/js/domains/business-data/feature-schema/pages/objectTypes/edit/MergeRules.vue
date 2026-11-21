<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ObjectTypeSummary } from '@/domains/business-data/data';
import type { MergeRuleRow } from '@/domains/business-data/data';
import { useObjectTypeSection } from '@/domains/business-data/ui-object-types';
import { useI18n } from '@/shared/composables/useI18n';
import ObjectTypeMergeCard from '../../../ui/ObjectTypeMergeCard.vue';

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
        value1: objectType.label,
      })
    "
  />

  <ObjectTypeMergeCard :object-type-slug="objectType.slug" :rules="mergeRules" :can-create="canUpdate" />
</template>
