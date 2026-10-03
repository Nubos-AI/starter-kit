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
import { registerChartModules } from '@/lib/charts/moduleRegistry';
import { buildChartTheme, resolveChartTokens } from '@/lib/charts/theme';

registerChartModules();

const props = defineProps<{
    options: AgChartOptions;
}>();

const chartRef = useTemplateRef('chartRef');
const isMounted = ref<boolean>(false);
const isDark = ref<boolean>(false);
const cleanups: Array<() => void> = [];

const chartTheme = computed(() =>
    buildChartTheme(resolveChartTokens(document.documentElement), isDark.value),
);

const chartOptions = computed<AgChartOptions>(() =>
    markRaw({ ...toRaw(props.options), theme: chartTheme.value }),
);

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
