<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

withDefaults(defineProps<{ isPending?: boolean }>(), { isPending: false });

const emit = defineEmits<{ download: [] }>();
</script>

<template>
    <Button
        data-chart-download
        type="button"
        variant="outline"
        size="sm"
        :disabled="isPending"
        @click="emit('download')"
    >
        <Download />
        {{
            t('i18n.components.charts.chart_download_button.download_as_image')
        }}
    </Button>
</template>
