<script setup lang="ts">
import MetricValue from '@/components/charts/MetricValue.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ReportRefusal, ReportResult } from '@/types/reports';

const props = withDefaults(
    defineProps<{
        title: string;
        result?: ReportResult | null;
        refusal?: ReportRefusal | null;
    }>(),
    { result: null, refusal: null },
);
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ props.title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <MetricValue :result="props.result" :refusal="props.refusal" />
        </CardContent>
    </Card>
</template>
