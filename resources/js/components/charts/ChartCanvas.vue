<script setup lang="ts">
import type { AgChartOptions, DownloadOptions } from 'ag-charts-community';
import { AgCharts } from 'ag-charts-vue3';
import {
    computed,
    markRaw,
    onMounted,
    onScopeDispose,
    ref,
    toRaw,
    useTemplateRef,
} from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useI18n } from '@/composables/useI18n';
import { registerChartModules } from '@/lib/charts/moduleRegistry';
import { buildChartTheme, resolveChartTokens } from '@/lib/charts/theme';

registerChartModules();

const props = defineProps<{
    options: AgChartOptions;
}>();

const { t } = useI18n();

const libraryTextKeys = [
    'ariaInitSeriesArea',
    'ariaAnnounceSelectedItem',
    'ariaAnnounceUnselectedItem',
    'ariaAnnounceChart',
    'ariaDescriptionLegendItem',
    'ariaLabelLegend',
    'ariaLabelLegendPagination',
    'ariaLabelLegendPagePrevious',
    'ariaLabelLegendPageNext',
    'ariaLabelLegendItem',
    'ariaLabelLegendItemUnknown',
    'overlayLoadingData',
    'overlayNoData',
    'overlayNoVisibleSeries',
    'tooltipPaginationStatus',
] as const;

const libraryTexts = computed<Record<string, string>>(() =>
    Object.fromEntries(
        libraryTextKeys.map((key) => [
            key,
            t(`i18n.components.charts.chart_canvas.${key}`),
        ]),
    ),
);

const chartRef = useTemplateRef('chartRef');
const isMounted = ref<boolean>(false);
const isDark = ref<boolean>(false);
const cleanups: Array<() => void> = [];

const chartTheme = computed(() =>
    buildChartTheme(resolveChartTokens(document.documentElement), isDark.value),
);

const chartOptions = computed<AgChartOptions>(() => {
    const options = toRaw(props.options);

    return markRaw({
        ...options,
        locale: {
            ...options.locale,
            localeText: {
                ...libraryTexts.value,
                ...options.locale?.localeText,
            },
        },
        theme: chartTheme.value,
    });
});

function readDarkMode(): boolean {
    return document.documentElement.classList.contains('dark');
}

async function downloadImage(options?: DownloadOptions): Promise<void> {
    await chartRef.value?.chart?.download(options);
}

onMounted(() => {
    isDark.value = readDarkMode();

    const observer = new MutationObserver(() => {
        isDark.value = readDarkMode();
    });

    observer.observe(document.documentElement, {
        attributeFilter: ['class'],
    });

    cleanups.push(() => observer.disconnect());

    isMounted.value = true;
});

onScopeDispose(() => {
    cleanups.forEach((cleanup) => cleanup());
});

defineExpose({ downloadImage });
</script>

<template>
    <div data-chart-canvas class="h-full w-full">
        <AgCharts
            v-if="isMounted"
            ref="chartRef"
            class="h-full w-full"
            :options="chartOptions"
        />
        <Skeleton v-else data-chart-skeleton class="h-full w-full" />
    </div>
</template>
