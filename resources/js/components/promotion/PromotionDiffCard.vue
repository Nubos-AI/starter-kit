<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import type { GetRowIdParams } from 'ag-grid-community';
import { computed } from 'vue';
import DataGrid from '@/components/data-grid/DataGrid.vue';
import { promotionDiffColumns } from '@/components/promotion/promotionDiffColumns';
import type { PromotionReviewState } from '@/components/promotion/promotionDiffColumns';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useI18n } from '@/composables/useI18n';
import { ARTIFACT_KIND_LABELS } from '@/types/promotion';
import type { PromotionDiffGroup, PromotionDiffRow } from '@/types/promotion';

const { t } = useI18n();

const props = defineProps<{
    group: PromotionDiffGroup;
    state: PromotionReviewState;
}>();

const columnDefs = promotionDiffColumns(props.state);

const title = computed<string>(
    () => ARTIFACT_KIND_LABELS[props.group.kind] ?? props.group.kind,
);

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
    <section class="flex flex-col gap-3" :aria-label="title">
        <Alert
            v-for="hint in group.rename_hints"
            :key="`${hint.from_key}|${hint.to_key}`"
            data-promotion-rename
        >
            <TriangleAlert class="icon-warning" />
            <AlertTitle>{{
                t(
                    'i18n.components.promotion.promotion_diff_card.possible_rename',
                )
            }}</AlertTitle>
            <AlertDescription>{{ hint.message }}</AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>{{ title }}</CardTitle>
                <CardDescription>{{ description }}</CardDescription>
            </CardHeader>
            <CardContent>
                <DataGrid
                    dom-layout="autoHeight"
                    :column-defs="columnDefs"
                    :row-data="group.rows"
                    :get-row-id="rowId"
                    :aria-label="title"
                />
            </CardContent>
        </Card>
    </section>
</template>
