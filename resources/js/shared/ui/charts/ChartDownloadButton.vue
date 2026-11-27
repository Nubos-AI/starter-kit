<script setup lang="ts">
import { Download } from '@lucide/vue';
import { useI18n } from '@/shared/composables/useI18n';
import { Button } from '@/shared/ui/primitives/button';

const { t } = useI18n();

withDefaults(defineProps<{ isPending?: boolean }>(), { isPending: false });

const emit = defineEmits<{ download: [] }>();
</script>

<template>
  <Button data-chart-download type="button" variant="outline" size="sm" :disabled="isPending" @click="emit('download')">
    <Download />
    {{ t('i18n.components.charts.chart_download_button.download_as_image') }}
  </Button>
</template>
