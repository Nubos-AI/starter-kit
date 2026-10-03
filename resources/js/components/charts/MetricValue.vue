<script setup lang="ts">
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { ReportRefusal, ReportResult } from '@/types/reports';
import {
    formatAggregateValue,
    REPORT_EMPTY_MESSAGE,
    REPORT_SUPPRESSED_MESSAGE,
    reportDiscardedMessage,
    resolveRefusalMessage,
    resolveReportState,
} from '@/types/reports';

const props = withDefaults(
    defineProps<{
        result?: ReportResult | null;
        refusal?: ReportRefusal | null;
    }>(),
    { result: null, refusal: null },
);

const state = computed(() => resolveReportState(props.result, props.refusal));

const refusalMessage = computed(() =>
    resolveRefusalMessage(props.refusal?.reason ?? ''),
);

const value = computed(() =>
    props.result === null
        ? ''
        : formatAggregateValue(props.result.total, props.result.aggregation),
);

const discardedCount = computed(() => props.result?.discarded_value_count ?? 0);

const diagnosticMessage = computed(() =>
    reportDiscardedMessage(discardedCount.value),
);
</script>

<template>
    <div class="flex flex-col gap-1">
        <Skeleton
            v-if="state === 'loading'"
            data-metric-loading
            class="h-8 w-24"
        />
        <p
            v-else-if="state === 'refused'"
            data-metric-refusal
            class="text-sm text-muted-foreground"
        >
            {{ refusalMessage }}
        </p>
        <p
            v-else-if="state === 'suppressed'"
            data-metric-suppressed
            class="text-sm text-muted-foreground"
        >
            {{ REPORT_SUPPRESSED_MESSAGE }}
        </p>
        <p
            v-else-if="state === 'empty'"
            data-metric-empty
            class="text-sm text-muted-foreground"
        >
            {{ REPORT_EMPTY_MESSAGE }}
        </p>
        <p v-else data-metric-value class="text-2xl font-semibold">
            {{ value }}
        </p>
        <p
            v-if="state === 'ready' && discardedCount > 0"
            data-metric-diagnostic
            class="text-xs text-muted-foreground"
        >
            {{ diagnosticMessage }}
        </p>
    </div>
</template>
