<script setup lang="ts">
import type { ReportRefusal, ReportResult } from '@/domains/analytics/data';
import { MetricValue } from '@/domains/analytics/ui-charts';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/primitives/card';

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
