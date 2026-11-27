<script setup lang="ts">
import { Info } from '@lucide/vue';
import { useI18n } from '@/shared/composables/useI18n';
import { Alert, AlertDescription, AlertTitle } from '@/shared/ui/primitives/alert';

const props = withDefaults(
  defineProps<{
    reason: string;
    title?: string;
  }>(),
  { title: undefined },
);

const { t } = useI18n();
</script>

<template>
  <Alert role="status" data-read-only-notice>
    <Info class="size-4" aria-hidden="true" />
    <AlertTitle>{{ props.title ?? t('i18n.components.read_only_notice.title') }}</AlertTitle>
    <AlertDescription>{{ props.reason }}</AlertDescription>
  </Alert>
</template>
