<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { PROMOTION_WRITE_ACTION } from '@/shared/lib/statusMaps';
import AlertError from '@/shared/ui/AlertError.vue';
import CardSection from '@/shared/ui/CardSection.vue';
import SimpleTable from '@/shared/ui/data-grid/SimpleTable.vue';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';
import { StatusBadge } from '@/shared/ui/primitives/status-badge';
import type { PromotionReport } from '../types/promotion';

const { t } = useI18n();

const props = defineProps<{
  report: PromotionReport;
}>();

const artifacts = (count: number): string =>
  count === 1
    ? t('i18n.components.promotion.promotion_report_card.1_artifact')
    : t('i18n.components.promotion.promotion_report_card.artifacts', {
        value1: count,
      });

const summary = computed<string>(() =>
  t('i18n.components.promotion.promotion_report_card.written_skipped', {
    value1: artifacts(props.report.written_count),
    value2: artifacts(props.report.skipped_count),
  }),
);

const skippedSentence = computed<string>(() =>
  t('i18n.components.promotion.promotion_report_card.skipped_reasons_are_listed_in_the_table', {
    value1: artifacts(props.report.skipped_count),
    value2: props.report.skipped_count === 1 ? 'wurde' : 'wurden',
  }),
);
</script>

<template>
  <CardSection
    data-promotion-report
    :title="t('i18n.components.promotion.promotion_report_card.transfer_result')"
    :description="summary"
  >
    <AlertError
      v-if="report.error !== null"
      data-promotion-report-error
      :title="t('i18n.components.promotion.promotion_report_card.nothing_transferred')"
      :message="report.error"
    />

    <Alert v-else-if="report.skipped_count > 0" data-promotion-report-skipped>
      <TriangleAlert />
      <AlertTitle>{{ t('i18n.components.promotion.promotion_report_card.partially_transferred') }}</AlertTitle>
      <AlertDescription>
        {{ skippedSentence }}
      </AlertDescription>
    </Alert>

    <div v-if="report.results.length > 0" class="overflow-x-auto">
      <SimpleTable data-promotion-report-results>
        <thead>
          <tr>
            <th class="text-left">
              {{ t('i18n.components.promotion.promotion_report_card.area') }}
            </th>
            <th class="text-left">
              {{ t('i18n.components.promotion.promotion_report_card.key') }}
            </th>
            <th class="text-left">
              {{ t('i18n.components.promotion.promotion_report_card.result') }}
            </th>
            <th class="text-left">
              {{ t('i18n.components.promotion.promotion_report_card.notice') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="result in report.results"
            :key="`${result.kind}|${result.key}`"
            :data-promotion-report-row="`${result.kind}|${result.key}`"
          >
            <td>{{ result.kind_label }}</td>
            <td>{{ result.key }}</td>
            <td>
              <StatusBadge :map="PROMOTION_WRITE_ACTION" :status="result.action" />
            </td>
            <td>{{ result.notes.join(' ') }}</td>
          </tr>
        </tbody>
      </SimpleTable>
    </div>

    <p v-for="note in report.notes" :key="note" class="text-sm text-muted-foreground">
      {{ note }}
    </p>
  </CardSection>
</template>
