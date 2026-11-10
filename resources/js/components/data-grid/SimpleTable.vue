<script setup lang="ts">
import { computed } from 'vue';
import { useUserPreferences } from '@/composables/useUserPreferences';
import { tableDensityParams } from '@/lib/tableAppearance';

const { settings } = useUserPreferences();
const appearance = computed(() => {
    const density = tableDensityParams[settings.value.density];

    return {
        '--table-font-size': `${density.fontSize}px`,
        '--table-row-height': `${density.rowHeight}px`,
        '--table-header-height': `${density.headerHeight}px`,
        '--table-cell-padding-y': `${density.spacing}px`,
    };
});
</script>

<template>
    <table data-slot="simple-table" :style="appearance">
        <slot />
    </table>
</template>
