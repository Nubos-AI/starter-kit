<script setup lang="ts">
import { CircleAlert, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { useI18n } from '@/composables/useI18n';
import { PROMOTION_WRITE_ACTION } from '@/lib/statusMaps';
import { ARTIFACT_KIND_LABELS } from '@/types/promotion';
import type { PromotionReport } from '@/types/promotion';

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
    t(
        'i18n.components.promotion.promotion_report_card.skipped_reasons_are_listed_in_the_table',
        {
            value1: artifacts(props.report.skipped_count),
            value2: props.report.skipped_count === 1 ? 'wurde' : 'wurden',
        },
    ),
);

const kindLabel = (kind: string): string => ARTIFACT_KIND_LABELS[kind] ?? kind;
</script>

<template>
    <Card data-promotion-report>
        <CardHeader>
            <CardTitle>{{
                t(
                    'i18n.components.promotion.promotion_report_card.transfer_result',
                )
            }}</CardTitle>
            <CardDescription>{{ summary }}</CardDescription>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <Alert
                v-if="report.error !== null"
                variant="destructive"
                data-promotion-report-error
            >
                <CircleAlert />
                <AlertTitle>{{
                    t(
                        'i18n.components.promotion.promotion_report_card.nothing_transferred',
                    )
                }}</AlertTitle>
                <AlertDescription>{{ report.error }}</AlertDescription>
            </Alert>

            <Alert
                v-else-if="report.skipped_count > 0"
                data-promotion-report-skipped
            >
                <TriangleAlert />
                <AlertTitle>{{
                    t(
                        'i18n.components.promotion.promotion_report_card.partially_transferred',
                    )
                }}</AlertTitle>
                <AlertDescription>
                    {{ skippedSentence }}
                </AlertDescription>
            </Alert>

            <div v-if="report.results.length > 0" class="overflow-x-auto">
                <SimpleTable data-promotion-report-results>
                    <thead>
                        <tr>
                            <th class="text-left">
                                {{
                                    t(
                                        'i18n.components.promotion.promotion_report_card.area',
                                    )
                                }}
                            </th>
                            <th class="text-left">
                                {{
                                    t(
                                        'i18n.components.promotion.promotion_report_card.key',
                                    )
                                }}
                            </th>
                            <th class="text-left">
                                {{
                                    t(
                                        'i18n.components.promotion.promotion_report_card.result',
                                    )
                                }}
                            </th>
                            <th class="text-left">
                                {{
                                    t(
                                        'i18n.components.promotion.promotion_report_card.notice',
                                    )
                                }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="result in report.results"
                            :key="`${result.kind}|${result.key}`"
                            :data-promotion-report-row="`${result.kind}|${result.key}`"
                        >
                            <td>{{ kindLabel(result.kind) }}</td>
                            <td>{{ result.key }}</td>
                            <td>
                                <StatusBadge
                                    :map="PROMOTION_WRITE_ACTION"
                                    :status="result.action"
                                />
                            </td>
                            <td>{{ result.notes.join(' ') }}</td>
                        </tr>
                    </tbody>
                </SimpleTable>
            </div>

            <p
                v-for="note in report.notes"
                :key="note"
                class="text-sm text-muted-foreground"
            >
                {{ note }}
            </p>
        </CardContent>
    </Card>
</template>
