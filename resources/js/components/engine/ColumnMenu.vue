<script setup lang="ts">
import {
    ArrowDown,
    ArrowDownUp,
    ArrowUp,
    SlidersHorizontal,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ColumnSnapshot } from '@/composables/useColumnState';
import { useI18n } from '@/composables/useI18n';
import type { GridColumn } from '@/types/grid';

const { t } = useI18n();

const props = defineProps<{
    columns: GridColumn[];
    snapshot: ColumnSnapshot;
}>();

const emit = defineEmits<{
    'toggle-visible': [colId: string, visible: boolean];
    'cycle-sort': [colId: string];
}>();

const hiddenByColId = computed<Map<string, boolean>>(
    () => new Map(props.snapshot.map((entry) => [entry.colId, entry.hidden])),
);

const sortByColId = computed<Map<string, 'asc' | 'desc' | null>>(
    () => new Map(props.snapshot.map((entry) => [entry.colId, entry.sort])),
);

const sortableColumns = computed<GridColumn[]>(() =>
    props.columns.filter((column) => column.is_sortable),
);

function isVisible(colId: string): boolean {
    return !(hiddenByColId.value.get(colId) ?? false);
}

function sortIcon(colId: string): Component {
    const sort = sortByColId.value.get(colId) ?? null;

    if (sort === 'asc') {
        return ArrowUp;
    }

    if (sort === 'desc') {
        return ArrowDown;
    }

    return ArrowDownUp;
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="outline" size="sm">
                <SlidersHorizontal class="size-4" />
                {{ t('i18n.components.engine.column_menu.columns') }}
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            extension-point="menus.column-menu"
            :extension-context="$props"
            align="end"
            class="w-56"
        >
            <DropdownMenuLabel>{{
                t('i18n.components.engine.column_menu.show_columns')
            }}</DropdownMenuLabel>
            <DropdownMenuCheckboxItem
                v-for="column in columns"
                :key="column.key"
                :model-value="isVisible(column.key)"
                @update:model-value="
                    (value) =>
                        emit('toggle-visible', column.key, value === true)
                "
            >
                {{ column.label }}
            </DropdownMenuCheckboxItem>
            <template v-if="sortableColumns.length > 0">
                <DropdownMenuSeparator />
                <DropdownMenuLabel>{{
                    t('i18n.components.engine.column_menu.sorting')
                }}</DropdownMenuLabel>
                <DropdownMenuItem
                    v-for="column in sortableColumns"
                    :key="`sort-${column.key}`"
                    @select="emit('cycle-sort', column.key)"
                >
                    <component :is="sortIcon(column.key)" class="size-4" />
                    {{ column.label }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
