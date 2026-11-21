<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import type { GetRowIdParams } from 'ag-grid-community';
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import CardSection from '@/shared/ui/CardSection.vue';
import DataGrid from '@/shared/ui/data-grid/DataGrid.vue';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';
import type { PromotionDiffGroup, PromotionDiffRow } from '../types/promotion';
import type { PromotionReviewState } from '../utils/promotionDiffColumns';
import { promotionDiffColumns } from '../utils/promotionDiffColumns';

const { t } = useI18n();

const props = defineProps<{
  group: PromotionDiffGroup;
  state: PromotionReviewState;
}>();

const columnDefs = promotionDiffColumns(props.state);

const title = computed<string>(() => props.group.kind_label);

const description = computed<string>(() =>
  t('i18n.components.promotion.promotion_diff_card.changed_unchanged', {
    value1: props.group.rows.length,
    value2: props.group.unchanged_count,
  }),
);

function rowId(params: GetRowIdParams<PromotionDiffRow>): string {
  return `${params.data.kind}|${params.data.key}`;
}
</script>

<template>
  <section class="flex flex-col gap-6" :aria-label="title">
    <Alert v-for="hint in group.rename_hints" :key="`${hint.from_key}|${hint.to_key}`" data-promotion-rename>
      <TriangleAlert class="icon-warning" />
      <AlertTitle>{{ t('i18n.components.promotion.promotion_diff_card.possible_rename') }}</AlertTitle>
      <AlertDescription>{{ hint.message }}</AlertDescription>
    </Alert>

    <CardSection :title="title" :description="description">
      <DataGrid
        dom-layout="autoHeight"
        :column-defs="columnDefs"
        :row-data="group.rows"
        :get-row-id="rowId"
        :aria-label="title"
      />
    </CardSection>
    <slot />
  </section>
</template>
